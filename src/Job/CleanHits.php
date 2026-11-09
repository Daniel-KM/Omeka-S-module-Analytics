<?php declare(strict_types=1);

namespace Analytics\Job;

use Analytics\Stdlib\HitDeduplicator;
use Omeka\Job\AbstractJob;

class CleanHits extends AbstractJob
{
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

        $totalFiles = (int) $connection->fetchOne(
            'SELECT COUNT(`id`) FROM `hit` WHERE `url` LIKE "%/files/%"'
        );
        $logger->info(
            'Starting deduplication of {total} hits of files (sorting them may be long).', // @translate
            ['total' => $totalFiles]
        );

        // A single pass in deduplication order: the sort is done once by the
        // server and the rows are streamed through an unbuffered connection,
        // instead of a full sort of the table for each chunk.
        $deduplicator = new HitDeduplicator($minutes * 60);
        $toDelete = [];
        $total = 0;
        $pdo = \Analytics\Stdlib\UnbufferedConnection::open($connection->getParams());
        $stmt = $pdo->query(
            'SELECT `id`, `url`, `ip`, `user_agent`, `accept_language`, `created` FROM `hit`
            WHERE `url` LIKE "%/files/%"
            ORDER BY `url`, `ip`, `user_agent`, `accept_language`, `created`, `id`'
        );
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (++$total % 100000 === 0) {
                if ($this->shouldStop()) {
                    $stmt->closeCursor();
                    $logger->warn(
                        'The job was stopped: no hit was removed.' // @translate
                    );
                    return;
                }
                $logger->info(
                    '{count}/{total} hits of files checked, duplicates found so far: {duplicates}.', // @translate
                    ['count' => $total, 'total' => $totalFiles, 'duplicates' => count($toDelete)]
                );
            }
            if ($deduplicator->isDuplicate($row['url'], $row['ip'], $row['user_agent'], $row['accept_language'], $row['created'])) {
                $toDelete[] = (int) $row['id'];
            }
        }
        $stmt->closeCursor();
        $pdo = null;

        $logger->info(
            'All the hits of files are checked ({total}), duplicates to remove: {duplicates}.', // @translate
            ['total' => $total, 'duplicates' => count($toDelete)]
        );

        // Remove by primary key order: nearly sequential disk accesses, a lot
        // quicker than the deduplication order on mechanical disks.
        sort($toDelete);

        $removed = 0;
        foreach (array_chunk($toDelete, 1000) as $chunk) {
            $ids = implode(',', $chunk);
            $connection->executeStatement(
                'INSERT INTO `hit_duplicate` SELECT * FROM `hit` WHERE `id` IN (' . $ids . ')'
            );
            $connection->executeStatement(
                'DELETE FROM `hit` WHERE `id` IN (' . $ids . ')'
            );
            $removed += count($chunk);
            if ($removed % 50000 === 0) {
                $logger->info(
                    '{count}/{total} duplicates removed.', // @translate
                    ['count' => $removed, 'total' => count($toDelete)]
                );
            }
        }

        $logger->notice(
            'Deduplication of the hits of files: duplicates removed: {count}, among {total} hits of files. The removed hits are archived in the table "hit_duplicate": check them, then empty it, or restore them with "INSERT INTO hit SELECT * FROM hit_duplicate".', // @translate
            ['count' => count($toDelete), 'total' => $total]
        );

        // The requests of the regions of the images (tiles, zones) are no
        // longer hits: the view of an image is its info.json. The past ones
        // are removed and archived too. The regions "full" are kept: they are
        // downloads of the whole image. The checks of rights by the image
        // server (module Access) and the bot challenge are not views either.
        $logger->info(
            'Searching the hits of the regions of the images.' // @translate
        );

        $removedRegions = 0;
        $regionIds = $connection->executeQuery(
            'SELECT `id` FROM `hit`
            WHERE `url` REGEXP "/iiif/.+/[0-9]+,[0-9]+,[0-9]+,[0-9]+/[^/]+/[^/]+/[^/.]+\\.[a-zA-Z0-9]+$"
                OR `url` REGEXP "/(iiif/search|iiif-search)/[^/]+/(annotations|alto|hocr)[/.]"
                OR `url` IN ("/authorize", "/bot-challenge")'
        )->fetchFirstColumn();
        sort($regionIds);
        foreach (array_chunk($regionIds, 1000) as $chunk) {
            if ($this->shouldStop()) {
                break;
            }
            $in = implode(',', array_map('intval', $chunk));
            $connection->executeStatement(
                'INSERT INTO `hit_duplicate` SELECT * FROM `hit` WHERE `id` IN (' . $in . ')'
            );
            $connection->executeStatement(
                'DELETE FROM `hit` WHERE `id` IN (' . $in . ')'
            );
            $removedRegions += count($chunk);
            if ($removedRegions % 50000 === 0) {
                $logger->info(
                    '{count}/{total} hits of regions removed.', // @translate
                    ['count' => $removedRegions, 'total' => count($regionIds)]
                );
            }
        }

        $logger->notice(
            'Removal of the hits of the parts of medias (regions of images, alto, annotations and hocr of the pages) and of the technical requests (checks of rights, bot challenge): hits removed and archived in the table "hit_duplicate": {count}. The view of an image is its info.json now.', // @translate
            ['count' => $removedRegions]
        );

        // Rebuild all the stats from the remaining hits, even without
        // duplicates, so a previously failed rebuild is repaired.
        $logger->info(
            'Rebuilding the statistics from the remaining hits.' // @translate
        );
        $aggregateHits = new AggregateHits($this->job, $services);
        $aggregateHits->perform();

        // Hide the checkbox of the main settings once the cleaning was done.
        $services->get('Omeka\Settings')
            ->set('analytics_clean_hits_done', date('Y-m-d\TH:i:s'));
    }
}
