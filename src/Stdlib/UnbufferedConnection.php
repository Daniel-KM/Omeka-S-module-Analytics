<?php declare(strict_types=1);

namespace Analytics\Stdlib;

/**
 * Open an unbuffered connection to stream a big result with a low memory use,
 * without loading it in the php client.
 */
class UnbufferedConnection
{
    /**
     * @param array $params Params of the doctrine connection.
     */
    public static function open(array $params): \PDO
    {
        $dsn = 'mysql:';
        if (!empty($params['unix_socket'])) {
            $dsn .= 'unix_socket=' . $params['unix_socket'];
        } else {
            $dsn .= 'host=' . ($params['host'] ?? 'localhost');
            if (!empty($params['port'])) {
                $dsn .= ';port=' . $params['port'];
            }
        }
        $dsn .= ';dbname=' . $params['dbname'] . ';charset=utf8mb4';
        return new \PDO($dsn, $params['user'] ?? null, $params['password'] ?? null, [
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
