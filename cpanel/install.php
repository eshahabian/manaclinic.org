<?php
declare(strict_types=1);

// install.php از index هم قابل دسترسی است
require_once __DIR__ . '/includes/helpers.php';

$config = require __DIR__ . '/config.php';
$error = null;
$ok = false;

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['db_host'],
        $config['db_name'],
        $config['db_charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS users (
        id VARCHAR(32) PRIMARY KEY,
        name VARCHAR(191) NOT NULL,
        email VARCHAR(191) NOT NULL UNIQUE,
        phone VARCHAR(50) NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('ADMIN','DOCTOR','PATIENT','SECRETARY') NOT NULL DEFAULT 'PATIENT',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS doctor_profiles (
        id VARCHAR(32) PRIMARY KEY,
        user_id VARCHAR(32) NOT NULL UNIQUE,
        specialty VARCHAR(255) NOT NULL,
        bio TEXT NOT NULL,
        avatar_url VARCHAR(255) NULL,
        session_price INT NOT NULL DEFAULT 3000000,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_doctor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS articles (
        id VARCHAR(32) PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL UNIQUE,
        content MEDIUMTEXT NOT NULL,
        excerpt TEXT NOT NULL,
        cover_url VARCHAR(255) NULL,
        published TINYINT(1) NOT NULL DEFAULT 0,
        published_at DATETIME NULL,
        author_id VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_article_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS availabilities (
        id VARCHAR(32) PRIMARY KEY,
        doctor_id VARCHAR(32) NOT NULL,
        date DATE NOT NULL,
        start_time CHAR(5) NOT NULL,
        end_time CHAR(5) NOT NULL,
        slot_minutes INT NOT NULL DEFAULT 50,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_doctor_date (doctor_id, date),
        CONSTRAINT fk_avail_doctor FOREIGN KEY (doctor_id) REFERENCES doctor_profiles(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS appointments (
        id VARCHAR(32) PRIMARY KEY,
        doctor_id VARCHAR(32) NOT NULL,
        patient_id VARCHAR(32) NOT NULL,
        starts_at DATETIME NOT NULL,
        ends_at DATETIME NOT NULL,
        status ENUM('PENDING_PAYMENT','CONFIRMED','CANCELLED','COMPLETED') NOT NULL DEFAULT 'PENDING_PAYMENT',
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_doc_start (doctor_id, starts_at),
        INDEX idx_patient (patient_id),
        CONSTRAINT fk_app_doctor FOREIGN KEY (doctor_id) REFERENCES doctor_profiles(id) ON DELETE CASCADE,
        CONSTRAINT fk_app_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS payments (
        id VARCHAR(32) PRIMARY KEY,
        appointment_id VARCHAR(32) NOT NULL UNIQUE,
        amount INT NOT NULL,
        authority VARCHAR(100) NULL,
        ref_id VARCHAR(100) NULL,
        status ENUM('PENDING','PAID','FAILED') NOT NULL DEFAULT 'PENDING',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_pay_app FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // ارتقای نقش‌ها برای دیتابیس‌های قبلی
    try {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('ADMIN','DOCTOR','PATIENT','SECRETARY') NOT NULL DEFAULT 'PATIENT'");
    } catch (Throwable $ignored) {
    }

    $adminId = 'admin001mana01';
    $doctorUserId = 'doctor001mana01';
    $doctorProfileId = 'dprofile001mana';
    $patientId = 'patient001mana01';
    $secretaryId = 'secretary001mana';
    $hashAdmin = password_hash('admin123', PASSWORD_DEFAULT);
    $hashDoctor = password_hash('doctor123', PASSWORD_DEFAULT);
    $hashPatient = password_hash('patient123', PASSWORD_DEFAULT);
    $hashSecretary = password_hash('An@bel.356#%^', PASSWORD_DEFAULT);
    $bio = "مشاوره تخصصی: فردی، خانواده (پیش از ازدواج و زناشویی)، کودک و نوجوان، تحصیلی و شغلی\nروان‌درمانی: درمان اضطراب، افسردگی و وسواس";

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute(['admin@ravansara.ir']);
    if (!$stmt->fetch()) {
        $pdo->prepare('INSERT INTO users (id,name,email,phone,password_hash,role) VALUES (?,?,?,?,?,?)')
            ->execute([$adminId, 'مدیر سایت', 'admin@ravansara.ir', '09120000000', $hashAdmin, 'ADMIN']);
    }

    $stmt->execute(['doctor@ravansara.ir']);
    if (!$stmt->fetch()) {
        $pdo->prepare('INSERT INTO users (id,name,email,phone,password_hash,role) VALUES (?,?,?,?,?,?)')
            ->execute([$doctorUserId, 'دکتر شیوا گرانمایه پور', 'doctor@ravansara.ir', '09121111111', $hashDoctor, 'DOCTOR']);
        $pdo->prepare('INSERT INTO doctor_profiles (id,user_id,specialty,bio,session_price,is_active) VALUES (?,?,?,?,?,1)')
            ->execute([$doctorProfileId, $doctorUserId, 'روان‌درمانی شناختی-رفتاری', $bio, 3000000]);
    } else {
        $pdo->prepare('UPDATE users SET name=? WHERE email=?')
            ->execute(['دکتر شیوا گرانمایه پور', 'doctor@ravansara.ir']);
        $pdo->prepare('UPDATE doctor_profiles dp JOIN users u ON u.id=dp.user_id SET dp.bio=?, dp.session_price=3000000 WHERE u.email=?')
            ->execute([$bio, 'doctor@ravansara.ir']);
    }

    $stmt->execute(['patient@ravansara.ir']);
    if (!$stmt->fetch()) {
        $pdo->prepare('INSERT INTO users (id,name,email,phone,password_hash,role) VALUES (?,?,?,?,?,?)')
            ->execute([$patientId, 'علی رضایی', 'patient@ravansara.ir', '09123333333', $hashPatient, 'PATIENT']);
    }

    $stmt->execute(['secretary@manaclinic.org']);
    if (!$stmt->fetch()) {
        $pdo->prepare('INSERT INTO users (id,name,email,phone,password_hash,role) VALUES (?,?,?,?,?,?)')
            ->execute([$secretaryId, 'منشی کلینیک', 'secretary@manaclinic.org', '09124444444', $hashSecretary, 'SECRETARY']);
    } else {
        $pdo->prepare('UPDATE users SET password_hash=?, role=?, name=? WHERE email=?')
            ->execute([$hashSecretary, 'SECRETARY', 'منشی کلینیک', 'secretary@manaclinic.org']);
    }

    // مقالات نمونه
    $author = $pdo->query("SELECT id FROM users WHERE email='doctor@ravansara.ir'")->fetch();
    if ($author) {
        $exists = $pdo->prepare('SELECT id FROM articles WHERE slug=?');
        $exists->execute(['modiriat-ezterab']);
        if (!$exists->fetch()) {
            $pdo->prepare('INSERT INTO articles (id,title,slug,content,excerpt,published,published_at,author_id) VALUES (?,?,?,?,?,1,NOW(),?)')
                ->execute([
                    cuid(),
                    'چگونه اضطراب روزمره را مدیریت کنیم؟',
                    'modiriat-ezterab',
                    "اضطراب بخشی طبیعی از زندگی است، اما وقتی بیش از حد شود می‌تواند کیفیت زندگی را کاهش دهد.\n\nتنفس آگاهانه و فعالیت بدنی منظم کمک‌کننده‌اند.",
                    'راهکارهای عملی برای کاهش اضطراب در زندگی روزمره.',
                    $author['id'],
                ]);
        }
        $exists->execute(['khab-salem']);
        if (!$exists->fetch()) {
            $pdo->prepare('INSERT INTO articles (id,title,slug,content,excerpt,published,published_at,author_id) VALUES (?,?,?,?,?,1,NOW(),?)')
                ->execute([
                    cuid(),
                    'اهمیت خواب سالم برای سلامت روان',
                    'khab-salem',
                    "خواب کافی و باکیفیت نقش مهمی در تنظیم هیجانات، حافظه و تمرکز دارد.",
                    'خواب کافی یکی از پایه‌های اصلی تعادل هیجانی است.',
                    $author['id'],
                ]);
        }

        $dp = $pdo->query("SELECT id FROM doctor_profiles WHERE user_id='{$author['id']}' OR user_id=(SELECT id FROM users WHERE email='doctor@ravansara.ir' LIMIT 1) LIMIT 1")->fetch();
        if ($dp) {
            for ($i = 1; $i <= 7; $i++) {
                $date = date('Y-m-d', strtotime("+{$i} day"));
                $check = $pdo->prepare('SELECT id FROM availabilities WHERE doctor_id=? AND date=?');
                $check->execute([$dp['id'], $date]);
                if (!$check->fetch()) {
                    $pdo->prepare('INSERT INTO availabilities (id,doctor_id,date,start_time,end_time,slot_minutes) VALUES (?,?,?,?,?,50)')
                        ->execute([cuid(), $dp['id'], $date, '10:00', '14:00']);
                }
            }
        }
    }

    $ok = true;
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>نصب مانا کلینیک</title>
  <style>
    body{font-family:Tahoma,sans-serif;background:#f7f5f0;padding:2rem;line-height:1.8}
    .box{max-width:640px;margin:auto;background:#fff;border:1px solid #d5e0da;border-radius:12px;padding:1.5rem}
    .ok{color:#1f7a4d}.err{color:#b33a3a}
    code{background:#eef4f0;padding:.1rem .35rem;border-radius:4px}
  </style>
</head>
<body>
  <div class="box">
    <h1>نصب مانا کلینیک (PHP)</h1>
    <?php if ($ok): ?>
      <p class="ok">نصب با موفقیت انجام شد.</p>
      <p>حساب‌ها:</p>
      <ul>
        <li>ادمین: <code>admin@ravansara.ir</code> / <code>admin123</code></li>
        <li>دکتر: <code>doctor@ravansara.ir</code> / <code>doctor123</code></li>
        <li>بیمار: <code>patient@ravansara.ir</code> / <code>patient123</code></li>
        <li>منشی: <code>secretary@manaclinic.org</code> / <code>An@bel.356#%^</code></li>
      </ul>
      <p><a href="/">رفتن به سایت</a></p>
      <p style="color:#b33a3a">بعد از نصب، فایل <code>install.php</code> را از هاست حذف کنید.</p>
    <?php else: ?>
      <p class="err">خطا: <?= htmlspecialchars((string)$error) ?></p>
      <p>ابتدا در cPanel دیتابیس بسازید و <code>config.php</code> را پر کنید.</p>
    <?php endif; ?>
  </div>
</body>
</html>
