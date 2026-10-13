<?php
declare(strict_types=1);

function ensure_collaboration_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS collaboration_requests (
        id VARCHAR(32) NOT NULL PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        phone VARCHAR(32) NOT NULL,
        specialty VARCHAR(160) NOT NULL DEFAULT '',
        message TEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'new',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_collab_status (status, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function collaboration_requests(PDO $pdo, int $limit = 40): array
{
    ensure_collaboration_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM collaboration_requests ORDER BY created_at DESC LIMIT ' . max(1, min(100, $limit)));
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}
