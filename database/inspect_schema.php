<?php

declare(strict_types=1);

$config = [];
foreach (file(__DIR__ . '/.env.connect', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = array_map('trim', explode('=', $line, 2));
    $config[$key] = trim($value, "\"'");
}

$schema = str_replace('.', '_', $config['DB_NAME']);
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $config['DB_HOST'],
    $config['DB_PORT'],
    $schema
);

try {
    $pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $exception) {
    if ($config['DB_HOST'] !== 'localhost' || $config['DB_USER'] !== 'root') {
        throw $exception;
    }
    try {
        $pdo = new PDO($dsn, $config['DB_USER'], '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (PDOException $fallbackException) {
        if ((int) $fallbackException->errorInfo[1] !== 1049) {
            throw $fallbackException;
        }
        $serverDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_PORT']);
        $server = new PDO($serverDsn, $config['DB_USER'], '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schemas = $server->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys') ORDER BY SCHEMA_NAME")->fetchAll(PDO::FETCH_COLUMN);
        fwrite(STDERR, 'Configured database was not found. Available databases: ' . implode(', ', $schemas) . PHP_EOL);
        exit(2);
    }
}

$statement = $pdo->prepare(
    'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = :schema
      ORDER BY TABLE_NAME, ORDINAL_POSITION'
);
$statement->execute(['schema' => $schema]);

echo json_encode($statement->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
