<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/user_phone.php';

$pdo = null;
try {
    $config = require __DIR__ . '/config.php';
    $pdo = new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $targets = ['secretary1' => '09334903314', 'secretary2' => '09398414273'];
    $pdo->beginTransaction();
    $users = $pdo->query('SELECT id, username, role, phone, is_disabled FROM users ORDER BY id FOR UPDATE')->fetchAll();
    $update = $pdo->prepare('UPDATE users SET phone = ? WHERE id = ?');
    $verify = $pdo->prepare('SELECT phone FROM users WHERE id = ?');

    foreach ($targets as $username => $phone) {
        $matches = array_values(array_filter($users, static fn(array $user): bool => $user['username'] === $username));
        if (count($matches) !== 1 || $matches[0]['role'] !== 'SECRETARY' || (int) $matches[0]['is_disabled'] !== 0) {
            throw new DomainException("Expected one active secretary account for $username.");
        }

        $id = $matches[0]['id'];
        $phoneKey = user_phone_key($phone);
        foreach ($users as $user) {
            if ($user['id'] !== $id && user_phone_key((string) $user['phone']) === $phoneKey) {
                throw new DomainException("The mobile number for $username belongs to another account.");
            }
        }

        $update->execute([$phone, $id]);
        $verify->execute([$id]);
        if ($verify->fetchColumn() !== $phone) {
            throw new DomainException("Could not verify the mobile number for $username.");
        }
    }

    $pdo->commit();
    fwrite(STDOUT, "Verified mobile numbers for secretary1 and secretary2.\n");
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $message = $error instanceof DomainException ? $error->getMessage() : 'Could not apply the secretary mobile update.';
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
