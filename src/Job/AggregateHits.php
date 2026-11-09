<?php declare(strict_types=1);

namespace Analytics\Job;

use Omeka\Job\AbstractJob;

class AggregateHits extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();

        $logger = $services->get('Omeka\Logger');

        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('Log');
        if ($module && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE) {
            $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
            $referenceIdProcessor->setReferenceId('statistics/aggregate_hits/job_' . $this->job->getId());
            $logger->addProcessor($referenceIdProcessor);
        }

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        // Use direct sql requests because hits are generally numerous.
        $sqls = <<<'SQL'
            # Remove existing stats.
            TRUNCATE TABLE `stat`;
            
            # Create stats for page (browser) and download (file).
            INSERT INTO `stat` (
                `type`,
                `url`,
                `entity_id`,
                `entity_name`,
                `hits`,
                `hits_anonymous`,
                `hits_identified`,
                `created`,
                `modified`
            )
            SELECT DISTINCT
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
            ORDER BY `created` ASC
            ;
            
            # Create stats for resource (page or download).
            # The entities are merged by url, because the url is unique by type
            # in the table and distinct entities may share a min url, for
            # example when old hits use a wrong entity name.
            INSERT INTO `stat` (
                `type`,
                `url`,
                `entity_id`,
                `entity_name`,
                `hits`,
                `hits_anonymous`,
                `hits_identified`,
                `created`,
                `modified`
            )
            SELECT
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
            ORDER BY `created` ASC
            ;
            SQL;
        $connection->executeStatement($sqls);
    }
}
