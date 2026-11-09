<?php declare(strict_types=1);

namespace Analytics\Job;

use Analytics\Stdlib\HitDeduplicator;
use Omeka\Job\AbstractJob;

class CleanHits extends AbstractJob
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
        /** @var \Analytics\Api\Adapter\HitAdapter $hitAdapter */
        $hitAdapter = $services->get('Omeka\ApiAdapterManager')->get('hits');
        $minutes = $hitAdapter->deduplicationMinutes();
        if ($minutes <= 0) {
            $logger->notice(
                'The deduplication of the hits of files is disabled (no window).' // @translate
            );
            return;
        }

        $deduplicator = new HitDeduplicator($minutes * 60);
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

        // The requests of the regions of the images (tiles, zones) are no
        // longer hits: the view of an image is its info.json. The past ones
        // are removed and archived too. The regions "full" are kept: they are
        // downloads of the whole image.
        $removedRegions = 0;
        do {
            if ($this->shouldStop()) {
                break;
            }
            $ids = $connection->executeQuery(
                'SELECT `id` FROM `hit`
                WHERE `url` REGEXP "/iiif/.+/[0-9]+,[0-9]+,[0-9]+,[0-9]+/[^/]+/[^/]+/[^/.]+\\.[a-zA-Z0-9]+$"
                LIMIT 1000'
            )->fetchFirstColumn();
            if (!$ids) {
                break;
            }
            $in = implode(',', array_map('intval', $ids));
            $connection->executeStatement(
                'INSERT INTO `hit_duplicate` SELECT * FROM `hit` WHERE `id` IN (' . $in . ')'
            );
            $connection->executeStatement(
                'DELETE FROM `hit` WHERE `id` IN (' . $in . ')'
            );
            $removedRegions += count($ids);
        } while (true);

        $logger->notice(
            'Removal of the hits of regions of images: {count} hits removed and archived in the table "hit_duplicate". The view of an image is its info.json now.', // @translate
            ['count' => $removedRegions]
        );

        // Rebuild all the stats from the remaining hits, even without
        // duplicates, so a previously failed rebuild is repaired.
        $aggregateHits = new AggregateHits($this->job, $services);
        $aggregateHits->perform();

        // Hide the checkbox of the main settings once the cleaning was done.
        $services->get('Omeka\Settings')
            ->set('analytics_clean_hits_done', date('Y-m-d\TH:i:s'));
    }
}
