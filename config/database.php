<?php
declare(strict_types=1);

function db(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('PARKIR_DB_HOST') ?: '127.0.0.1';
    $port = getenv('PARKIR_DB_PORT') ?: '3306';
    $name = getenv('PARKIR_DB_NAME') ?: 'parkir';
    $username = getenv('PARKIR_DB_USER') ?: 'root';
    $password = getenv('PARKIR_DB_PASSWORD');

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $name
    );

    $connection = new PDO($dsn, $username, $password === false ? '' : $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connection;
}
