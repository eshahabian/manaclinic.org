<?php
declare(strict_types=1);

function db_connect(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['db_host'],
        $config['db_name'],
        $config['db_charset'] ?? 'utf8mb4'
    );

    try {
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/install')) {
            throw $e;
        }
        http_response_code(500);
        echo 'اتصال به دیتابیس برقرار نشد. config.php و نصب را بررسی کنید. <a href="install">نصب</a>';
        exit;
    }

    return $pdo;
}
