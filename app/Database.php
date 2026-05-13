<?php

declare(strict_types=1);

final class Database
{
    /** @var PDO|null */
    private static $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $driver = (string) config('DB_DRIVER', 'mysql');

        if ($driver === 'sqlite') {
            $path = (string) config('DB_PATH', __DIR__ . '/../database/database.sqlite');
            if (!preg_match('/^[A-Za-z]:\\\\|^\//', $path)) {
                $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            }

            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $dsn = 'sqlite:' . $path;
            self::$connection = new PDO($dsn);
            self::$connection->exec('PRAGMA foreign_keys = ON');
            self::$connection->exec('PRAGMA journal_mode = WAL');
            self::$connection->exec('PRAGMA busy_timeout = 5000');
            self::$connection->setAttribute(PDO::ATTR_TIMEOUT, 5);
        } else {
            $host = (string) config('DB_HOST', '127.0.0.1');
            $port = (string) config('DB_PORT', '3306');
            $dbName = (string) config('DB_NAME', '');
            $charset = (string) config('DB_CHARSET', 'utf8mb4');
            $user = (string) config('DB_USER', '');
            $pass = (string) config('DB_PASS', '');

            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $dbName, $charset);
            self::$connection = new PDO($dsn, $user, $pass);
        }

        self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return self::$connection;
    }
}
