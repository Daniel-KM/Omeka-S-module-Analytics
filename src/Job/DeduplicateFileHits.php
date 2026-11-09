<?php declare(strict_types=1);

namespace Analytics\Job;

use Analytics\Api\Adapter\HitAdapter;
use Analytics\Stdlib\HitDeduplicator;
use Omeka\Job\AbstractJob;

class DeduplicateFileHits extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests and heavy memory use.
     *
     * @var int
     */
    const SQL_LIMIT = 50000;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        $logger = $services->get('Omeka\Logger');
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('Log');
        if ($module && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE) {
            $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
            $referenceIdProcessor->setReferenceId('analytics/deduplicate_file_hits/job_' . $this->job->getId());
            $logger->addProcessor($referenceIdProcessor);
        }

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        // The removed hits are archived in a table, so a too wide removal, for
        // example when old hits share the ip of an untrusted reverse proxy,
        // can be checked and restored.
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS `hit_duplicate` LIKE `hit`');
        // The archive is read only by id, so the secondary indexes copied from
        // the table of hits are useless.
        $indexes = $connection->executeQuery('SHOW INDEX FROM `hit_duplicate` WHERE `Key_name` <> "PRIMARY"')->fetchAllAssociative();
        foreach (array_unique(array_column($indexes, 'Key_name')) as $index) {
            $connection->executeStatement("ALTER TABLE `hit_duplicate` DROP INDEX `$index`");
        }

        // The hits are read in deduplication order by chunks, and deleted only
        // at the end, so the offsets remain stable.
        $deduplicator = new HitDeduplicator(HitAdapter::DEDUPLICATION_MINUTES * 60);
        $toDelete = [];
        $total = 0;
        $offset = 0;
        do {
            if ($this->shouldStop()) {
                $logger->warn(
                    'The job was stopped: no hit was removed.' // @translate
                );
                return;
            }
            $rows = $connection->executeQuery(
                'SELECT `id`, `url`, `ip`, `user_agent`, `created` FROM `hit`
                WHERE `url` LIKE "%/files/%"
                ORDER BY `url`, `ip`, `user_agent`, `created`, `id`
                LIMIT ' . self::SQL_LIMIT . ' OFFSET ' . $offset
            )->fetchAllAssociative();
            foreach ($rows as $row) {
                ++$total;
                if ($deduplicator->isDuplicate($row['url'], $row['ip'], $row['user_agent'], $row['created'])) {
                    $toDelete[] = (int) $row['id'];
                }
            }
            $offset += self::SQL_LIMIT;
        } while (count($rows) === self::SQL_LIMIT);

        foreach (array_chunk($toDelete, 1000) as $chunk) {
            $ids = implode(',', $chunk);
            $connection->executeStatement(
                'INSERT INTO `hit_duplicate` SELECT * FROM `hit` WHERE `id` IN (' . $ids . ')'
            );
            $connection->executeStatement(
                'DELETE FROM `hit` WHERE `id` IN (' . $ids . ')'
            );
        }

        $logger->notice(
            'Deduplication of the hits of files: {count} duplicates removed among {total} hits of files. The removed hits are archived in the table "hit_duplicate": check them, then empty it, or restore them with "INSERT INTO hit SELECT * FROM hit_duplicate".', // @translate
            ['count' => count($toDelete), 'total' => $total]
        );

        // Rebuild all the stats from the remaining hits, even without
        // duplicates, so a previously failed rebuild is repaired.
        $aggregateHits = new AggregateHits($this->job, $services);
        $aggregateHits->perform();
    }
}
