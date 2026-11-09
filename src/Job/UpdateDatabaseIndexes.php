<?php declare(strict_types=1);

namespace Analytics\Job;

use Omeka\Job\AbstractJob;

/**
 * Update the indexes of the tables of hits and stats.
 *
 * The tables of hits may be huge, so the indexes are added online and before
 * the removal of the old ones, that are useless or redundant, so the queries
 * remain quick during the process. Each index is checked by its columns, so the
 * job can be run again.
 */
class UpdateDatabaseIndexes extends AbstractJob
{
    /**
     * Indexes to add: name => columns. The names are the doctrine ones.
     */
    const ADD = [
        'hit' => [
            'IDX_5AD22641F47645AEB23DB7B8' => ['url', 'created'],
        ],
    ];

    /**
     * Indexes to remove, by columns, whatever the name is.
     */
    const REMOVE = [
        'hit' => [
            ['url'],
            ['entity_id'],
            ['entity_name'],
            ['ip'],
            ['referrer'],
            ['user_agent'],
            ['accept_language'],
        ],
        'stat' => [
            ['type'],
            ['entity_id'],
        ],
    ];

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        $logger = $services->get('Omeka\Logger');
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('analytics/update_database_indexes/job_' . $this->job->getId());
        $logger->addProcessor($referenceIdProcessor);

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        // The job may be dispatched twice, for example when an upgrade is run
        // again while the index is being built.
        $runningJobId = $connection->executeQuery(
            'SELECT `id` FROM `job` WHERE `class` = ? AND `status` IN (?, ?) AND `id` < ? ORDER BY `id` LIMIT 1',
            [static::class, \Omeka\Entity\Job::STATUS_STARTING, \Omeka\Entity\Job::STATUS_IN_PROGRESS, $this->job->getId()]
        )->fetchOne();
        if ($runningJobId) {
            $logger->notice(
                'The indexes are already being updated by the job #{job_id}.', // @translate
                ['job_id' => $runningJobId]
            );
            return;
        }

        foreach (self::ADD as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if ($this->indexName($table, $columns)) {
                    continue;
                }
                $logger->info(
                    'Adding index `{index}` on table `{table}`, that may be long on a big table.', // @translate
                    ['index' => $name, 'table' => $table]
                );
                try {
                    $connection->executeStatement(sprintf(
                        'ALTER TABLE `%s` ADD INDEX `%s` (`%s`), ALGORITHM=INPLACE, LOCK=NONE',
                        $table, $name, implode('`, `', $columns)
                    ));
                } catch (\Throwable $e) {
                    // The old indexes are kept, since the queries need them.
                    $logger->err(
                        'Unable to add index `{index}` on table `{table}`: {message}', // @translate
                        ['index' => $name, 'table' => $table, 'message' => $e->getMessage()]
                    );
                    return;
                }
                $logger->notice(
                    'Index `{index}` added on table `{table}`.', // @translate
                    ['index' => $name, 'table' => $table]
                );
            }
        }

        foreach (self::REMOVE as $table => $indexes) {
            foreach ($indexes as $columns) {
                $name = $this->indexName($table, $columns);
                if (!$name) {
                    continue;
                }
                try {
                    $connection->executeStatement(sprintf(
                        'ALTER TABLE `%s` DROP INDEX `%s`, ALGORITHM=INPLACE, LOCK=NONE',
                        $table, $name
                    ));
                } catch (\Throwable $e) {
                    $logger->err(
                        'Unable to remove index `{index}` on table `{table}`: {message}', // @translate
                        ['index' => $name, 'table' => $table, 'message' => $e->getMessage()]
                    );
                    continue;
                }
                $logger->notice(
                    'Index `{index}` removed from table `{table}`.', // @translate
                    ['index' => $name, 'table' => $table]
                );
            }
        }

        $logger->notice(
            'The indexes of the tables of hits and stats are updated.' // @translate
        );
    }

    /**
     * Get the name of the non-unique index with exactly these columns, if any.
     */
    protected function indexName(string $table, array $columns): ?string
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $rows = $connection
            ->executeQuery(sprintf('SHOW INDEX FROM `%s` WHERE `Non_unique` = 1', $table))
            ->fetchAllAssociative();
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row['Key_name']][(int) $row['Seq_in_index']] = $row['Column_name'];
        }
        foreach ($indexes as $name => $indexColumns) {
            ksort($indexColumns);
            if (array_values($indexColumns) === $columns) {
                return (string) $name;
            }
        }
        return null;
    }
}
