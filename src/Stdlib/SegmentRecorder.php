<?php declare(strict_types=1);

namespace Analytics\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Record the requested parts of the medias, aggregated by month.
 *
 * The recorder is a no-op when the option "analytics_segments" is off, so the
 * callers (listeners, module Image Server) call it without check.
 */
class SegmentRecorder
{
    const TYPE_REGION = 'region';
    const TYPE_TIME = 'time';

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var bool
     */
    protected $enabled;

    public function __construct(Connection $connection, bool $enabled)
    {
        $this->connection = $connection;
        $this->enabled = $enabled;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record buckets of a media ("region" of an image or "time" of a file).
     *
     * @param int[] $buckets
     */
    public function record(string $entityName, int $entityId, string $type, array $buckets): void
    {
        if (!$this->enabled || !$entityId || !$buckets) {
            return;
        }
        $period = (int) date('Ym');
        $sql = 'INSERT INTO `analytics_segment` (`entity_name`, `entity_id`, `type`, `bucket`, `period`, `hits`)
            VALUES (:entity_name, :entity_id, :type, :bucket, :period, 1)
            ON DUPLICATE KEY UPDATE `hits` = `hits` + 1';
        foreach (array_unique($buckets) as $bucket) {
            $this->connection->executeStatement($sql, [
                'entity_name' => $entityName,
                'entity_id' => $entityId,
                'type' => $type,
                'bucket' => (int) $bucket,
                'period' => $period,
            ]);
        }
    }
}
