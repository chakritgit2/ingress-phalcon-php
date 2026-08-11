<?php

/**
 * Generic SQL migration runner.
 *
 * Applies every *.sql file in this directory, in filename order, tracking
 * what has already run in `schema_migrations` so re-running is a no-op.
 * Usage: php app/migrations/migrate.php
 */

$config = require __DIR__ . '/../config/config.php';

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config->database->host,
    $config->database->port,
    $config->database->dbname,
    $config->database->charset
);

$pdo = new PDO($dsn, $config->database->username, $config->database->password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (' .
    'id INT UNSIGNED NOT NULL AUTO_INCREMENT, ' .
    'migration VARCHAR(255) NOT NULL, ' .
    'applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ' .
    'PRIMARY KEY (id), ' .
    'UNIQUE KEY uq_schema_migrations_migration (migration)' .
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT migration FROM schema_migrations')
    ->fetchAll(PDO::FETCH_COLUMN);

$files = glob(__DIR__ . '/*.sql');
sort($files);

foreach ($files as $file) {
    $name = basename($file);

    if (in_array($name, $applied, true)) {
        echo "skip  {$name} (already applied)\n";
        continue;
    }

    $sql = file_get_contents($file);

    // Not wrapped in a transaction: MySQL DDL (CREATE TABLE) causes an
    // implicit commit, so a BEGIN/COMMIT around it provides no real
    // atomicity anyway — it only breaks PDO's transaction bookkeeping.
    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
        $stmt->execute(['migration' => $name]);
        echo "apply {$name}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "error {$name}: {$e->getMessage()}\n");
        exit(1);
    }
}

echo "done\n";
