<?php declare(strict_types=1);

namespace Analytics\Job;

use Analytics\Stdlib\UnbufferedConnection;
use Omeka\Job\AbstractJob;

class AggregateHits extends AbstractJob
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        $this->logger = $services->get('Omeka\Logger');

        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('Log');
        if ($module && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE) {
            $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
            $referenceIdProcessor->setReferenceId('statistics/aggregate_hits/job_' . $this->job->getId());
            $this->logger->addProcessor($referenceIdProcessor);
        }

        $this->connection = $services->get('Omeka\Connection');

        // The hits are read with plain selects, that do not lock the table, so
        // the site can record hits during the rebuild, unlike "insert from
        // select". The rows created live during the rebuild are overwritten.
        $this->connection->executeStatement('TRUNCATE TABLE `stat`');

        // Stats for page (browser) and download (file).
        $this->insertStreamed(
            'page/download',
            'SELECT
                IF(`url` LIKE "/files/%", "download", "page"),
                MIN(`url`),
                `entity_id`,
                `entity_name`,
                COUNT(`id`),
                SUM(CASE WHEN `user_id` < 1 THEN 1 ELSE 0 END),
                SUM(CASE WHEN `user_id` > 0 THEN 1 ELSE 0 END),
                MIN(`created`),
                MAX(`created`)
            FROM `hit`
            GROUP BY `url`
            ORDER BY MIN(`created`) ASC'
        );

        // Stats for resource (page or download). The entities are merged by
        // url, because the url is unique by type in the table and distinct
        // entities may share a min url, for example when old hits use a wrong
        // entity name.
        $this->insertStreamed(
            'resource',
            'SELECT
                "resource",
                `url`,
                MAX(`entity_id`),
                MAX(`entity_name`),
                SUM(`hits`),
                SUM(`hits_anonymous`),
                SUM(`hits_identified`),
                MIN(`created`),
                MAX(`modified`)
            FROM (
                SELECT
                    MIN(`url`) AS `url`,
                    `entity_id`,
                    `entity_name`,
                    COUNT(`id`) AS `hits`,
                    SUM(CASE WHEN `user_id` < 1 THEN 1 ELSE 0 END) AS `hits_anonymous`,
                    SUM(CASE WHEN `user_id` > 0 THEN 1 ELSE 0 END) AS `hits_identified`,
                    MIN(`created`) AS `created`,
                    MAX(`created`) AS `modified`
                FROM `hit`
                GROUP BY `entity_name`, `entity_id`
            ) AS `by_entity`
            GROUP BY `url`
            ORDER BY MIN(`created`) ASC'
        );
    }

    /**
     * Stream an aggregated select of the hits into the table of stats.
     *
     * The select runs on a separate unbuffered connection and the rows are
     * inserted by batches on the main one, so the site is never blocked.
     */
    protected function insertStreamed(string $label, string $select): void
    {
        $sqlInsert = 'INSERT INTO `stat`
            (`type`, `url`, `entity_id`, `entity_name`, `hits`, `hits_anonymous`, `hits_identified`, `created`, `modified`)
            VALUES %s
            ON DUPLICATE KEY UPDATE
                `hits` = VALUES(`hits`),
                `hits_anonymous` = VALUES(`hits_anonymous`),
                `hits_identified` = VALUES(`hits_identified`),
                `created` = VALUES(`created`),
                `modified` = VALUES(`modified`)';

        $pdo = UnbufferedConnection::open($this->connection->getParams());
        $stmt = $pdo->query($select);
        $batch = [];
        $total = 0;
        $flush = function () use (&$batch, $sqlInsert): void {
            if (!$batch) {
                return;
            }
            $placeholders = implode(', ', array_fill(0, count($batch), '(?, ?, ?, ?, ?, ?, ?, ?, ?)'));
            $this->connection->executeStatement(
                sprintf($sqlInsert, $placeholders),
                array_merge(...$batch)
            );
            $batch = [];
        };
        while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            $batch[] = $row;
            if (count($batch) >= 1000) {
                $flush();
            }
            if (++$total % 100000 === 0) {
                if ($this->shouldStop()) {
                    $stmt->closeCursor();
                    $this->logger->warn(
                        'The job was stopped: the stats are incomplete, run it again.' // @translate
                    );
                    return;
                }
                $this->logger->info(
                    '{count} stats of {label} created.', // @translate
                    ['count' => $total, 'label' => $label]
                );
            }
        }
        $flush();
        $stmt->closeCursor();

        $this->logger->notice(
            'Aggregation of the stats of {label}: {total} rows.', // @translate
            ['label' => $label, 'total' => $total]
        );
    }
}
