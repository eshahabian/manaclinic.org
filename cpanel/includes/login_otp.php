<?php
declare(strict_types=1);

function login_otp_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_otp (
        id CHAR(64) PRIMARY KEY, user_id VARCHAR(191) NOT NULL, code_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL, attempts INT NOT NULL DEFAULT 0,
        used TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_otp_limits (
        bucket CHAR(64) PRIMARY KEY, started_at BIGINT NOT NULL, hits INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Atomic counters shared by sessions and application workers. */
function login_otp_limit(PDO $pdo, string $bucket, int $maximum, int $window): bool
{
    $key = hash('sha256', $bucket);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT IGNORE INTO login_otp_limits VALUES(?,?,0)')->execute([$key,time()]);
        $s=$pdo->prepare('SELECT * FROM login_otp_limits WHERE bucket=? FOR UPDATE');$s->execute([$key]);$row=$s->fetch();
        $expired=(int)$row['started_at']<=time()-$window;
        $hits=$expired?0:(int)$row['hits'];
        $pdo->prepare('UPDATE login_otp_limits SET started_at=?,hits=? WHERE bucket=?')->execute([$expired?time():$row['started_at'],$hits+1,$key]);
        $pdo->commit();
        return $hits<$maximum;
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function login_otp_verify(PDO $pdo, string $id, string $code): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/D',$id)) return null;
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT * FROM login_otp WHERE id=? FOR UPDATE');$s->execute([$id]);$row=$s->fetch();
        $valid=$row && !(int)$row['used'] && (int)$row['attempts']<5 && strtotime($row['expires_at'])>time();
        if($valid){
            $valid=preg_match('/^[0-9]{6}$/D',$code) && password_verify($code,$row['code_hash']);
            $pdo->prepare('UPDATE login_otp SET attempts=attempts+1,used=? WHERE id=?')->execute([$valid?1:0,$id]);
        }
        $pdo->commit();
        if(!$valid)return null;
        $s=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$s->execute([$row['user_id']]);
        return $s->fetch() ?: null;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
