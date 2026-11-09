<?php declare(strict_types=1);

namespace Analytics\Stdlib;

/**
 * Detect the hits of a file that duplicate a previous one of the same visitor.
 *
 * A file is requested many times for a single view: probe then download, ranges
 * of a video, preload of a player, replay. Only the first request of a visitor
 * within the deduplication window is a real hit.
 *
 * The hits must be checked ordered by url, ip, user agent and created: a hit
 * is a duplicate when it occurs less than the window after the last kept hit
 * of the same url, ip and user agent. The user agent distinguishes the
 * visitors sharing an ip: reverse proxy not trusted in the past, shared
 * network, anonymized ip.
 */
class HitDeduplicator
{
    /**
     * @var int
     */
    protected $windowSeconds;

    /**
     * @var string|null
     */
    protected $lastKey = null;

    /**
     * @var int
     */
    protected $lastKept = 0;

    public function __construct(int $windowSeconds)
    {
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * Check a hit against the previous ones, ordered by url, ip, user agent
     * and created.
     */
    public function isDuplicate(string $url, string $ip, string $userAgent, string $created): bool
    {
        $key = $url . '|' . $ip . '|' . $userAgent;
        $time = (int) strtotime($created);
        if ($key === $this->lastKey
            && $time - $this->lastKept < $this->windowSeconds
        ) {
            return true;
        }
        $this->lastKey = $key;
        $this->lastKept = $time;
        return false;
    }
}
