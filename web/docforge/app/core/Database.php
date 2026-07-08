<?php

namespace DocForge\Core;

class Database
{
    /** @var \PDO */
    private static $pdo;

    /** @param array<string,mixed> $config */
    public static function connect(array $config)
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $db = $config['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['name'],
            $db['charset']
        );
        self::$pdo = new \PDO($dsn, $db['user'], $db['pass'], array(
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ));
        return self::$pdo;
    }

    /** @return \PDO */
    public static function pdo()
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('Database not connected');
        }
        return self::$pdo;
    }
}
