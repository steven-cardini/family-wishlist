<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $pdo = new PDO('sqlite:' . $dir . '/db.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    // See docs/technical_specification.md, 7.3
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 2) {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
            if ($version === 0) {
                $pdo->exec((string)file_get_contents(__DIR__ . '/db/schema.sql'));
            } elseif ($version === 1) {
                $pdo->exec('ALTER TABLE login_attempts ADD COLUMN input TEXT;
                    ALTER TABLE login_attempts ADD COLUMN blocked INTEGER NOT NULL DEFAULT 0;
                    PRAGMA user_version = 2');
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }
    return $pdo;
}
