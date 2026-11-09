<?php declare(strict_types=1);

namespace Analytics\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Count the requests of the robots by day, separately from the hits.
 *
 * The robots must not be mixed with the visits, but it is useful to know how
 * many there are. So there is one row by day, type and name, whatever the
 * number of requests.
 */
class BotCounter
{
    /**
     * Display of the page of the challenge of the module Bot Challenge.
     */
    const TYPE_CHALLENGED = 'challenged';

    /**
     * Answer to the challenge, so a client able to run javascript.
     */
    const TYPE_SOLVED = 'solved';

    /**
     * Request of a client that declares itself as a robot in its user agent.
     */
    const TYPE_DECLARED = 'declared';

    const NAME_MAX_LENGTH = 64;

    /**
     * @var Connection
     */
    protected $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function createTable(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `analytics_bot_count` (
                `day` DATE NOT NULL,
                `type` VARCHAR(16) NOT NULL,
                `name` VARCHAR(64) DEFAULT '' NOT NULL,
                `hits` INT DEFAULT 0 NOT NULL,
                PRIMARY KEY(`day`, `type`, `name`)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    /**
     * Count a request.
     *
     * A counter must never break a page, for example before the upgrade of
     * the database.
     */
    public function record(string $type, ?string $userAgent): void
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO `analytics_bot_count` (`day`, `type`, `name`, `hits`)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE `hits` = `hits` + 1',
                [date('Y-m-d'), $type, self::name($userAgent)]
            );
        } catch (\Doctrine\DBAL\Exception $e) {
        }
    }

    /**
     * Get the number of requests by type and the most frequent names.
     *
     * @param string $since Date of the first day to take into account.
     * @return array With keys "totals" (by type) and "names" (hits by name).
     */
    public function summary(string $since, int $limit = 10): array
    {
        try {
            $totals = $this->connection->fetchAllKeyValue(
                'SELECT `type`, SUM(`hits`) FROM `analytics_bot_count` WHERE `day` >= ? GROUP BY `type`',
                [$since]
            );
            $names = $this->connection->fetchAllKeyValue(
                'SELECT `name`, SUM(`hits`) AS `total` FROM `analytics_bot_count`
                WHERE `day` >= ? AND `name` <> ""
                GROUP BY `name`
                ORDER BY `total` DESC
                LIMIT ' . $limit,
                [$since]
            );
        } catch (\Doctrine\DBAL\Exception $e) {
            return ['totals' => [], 'names' => []];
        }
        return [
            'totals' => array_map('intval', $totals),
            'names' => array_map('intval', $names),
        ];
    }

    /**
     * Get the name of a robot from its user agent, if it declares it.
     *
     * The name is the first word that contains a keyword, so without the
     * version: "Mozilla/5.0 (compatible; Googlebot/2.1; +http…)" is "Googlebot".
     */
    public static function name(?string $userAgent): string
    {
        return preg_match('~[a-z0-9_.-]*(?:bot|crawler|spider|slurp|check_http)[a-z0-9_.-]*~i', (string) $userAgent, $matches)
            ? substr($matches[0], 0, self::NAME_MAX_LENGTH)
            : '';
    }

    /**
     * Estimate the requests of the robots from the totals by type.
     *
     * The challenges without answer are the robots that hide themselves, and
     * the other ones declare themselves.
     *
     * @param array $totals Number of requests by type.
     */
    public static function estimate(array $totals): int
    {
        return max(0, ($totals[self::TYPE_CHALLENGED] ?? 0) - ($totals[self::TYPE_SOLVED] ?? 0))
            + ($totals[self::TYPE_DECLARED] ?? 0);
    }
}
