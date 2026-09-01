<?php
declare(strict_types=1);

// install.php از index هم قابل دسترسی است
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/name_transliterations.php';

$config = require __DIR__ . '/config.php';
$error = null;
$ok = false;
$resetPasswords = false;

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
        username VARCHAR(64) NOT NULL UNIQUE,
        name VARCHAR(191) NOT NULL,
        email VARCHAR(191) NULL,
        phone VARCHAR(50) NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('ADMIN','DOCTOR','PATIENT','SECRETARY') NOT NULL DEFAULT 'PATIENT',
        preferred_doctor_id VARCHAR(32) NULL,
        must_change_password TINYINT(1) NOT NULL DEFAULT 0,
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
        is_approved TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
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

      CREATE TABLE IF NOT EXISTS doctor_patient_charts (
        id VARCHAR(32) PRIMARY KEY,
        doctor_id VARCHAR(32) NOT NULL,
        patient_id VARCHAR(32) NOT NULL,
        history_text MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_doctor_patient_chart (doctor_id, patient_id),
        INDEX idx_chart_doctor (doctor_id),
        CONSTRAINT fk_chart_doctor FOREIGN KEY (doctor_id) REFERENCES doctor_profiles(id) ON DELETE CASCADE,
        CONSTRAINT fk_chart_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS doctor_session_notes (
        id VARCHAR(32) PRIMARY KEY,
        doctor_id VARCHAR(32) NOT NULL,
        patient_id VARCHAR(32) NOT NULL,
        appointment_id VARCHAR(32) NOT NULL,
        note_text MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_session_note_appointment (appointment_id),
        INDEX idx_session_doctor_patient (doctor_id, patient_id),
        CONSTRAINT fk_snote_doctor FOREIGN KEY (doctor_id) REFERENCES doctor_profiles(id) ON DELETE CASCADE,
        CONSTRAINT fk_snote_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_snote_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS doctor_highlights (
        id VARCHAR(32) PRIMARY KEY,
        doctor_id VARCHAR(32) NOT NULL,
        patient_id VARCHAR(32) NOT NULL,
        excerpt TEXT NOT NULL,
        remark TEXT NULL,
        color VARCHAR(20) NOT NULL DEFAULT 'yellow',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_hl_doctor_patient (doctor_id, patient_id),
        CONSTRAINT fk_hl_doctor FOREIGN KEY (doctor_id) REFERENCES doctor_profiles(id) ON DELETE CASCADE,
        CONSTRAINT fk_hl_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS name_transliterations (
        id VARCHAR(32) PRIMARY KEY,
        persian VARCHAR(100) NOT NULL,
        latin VARCHAR(100) NOT NULL,
        part ENUM('first','last','any') NOT NULL DEFAULT 'any',
        source VARCHAR(32) NOT NULL DEFAULT 'seed',
        hits INT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_name_latin_part (persian, latin, part),
        INDEX idx_lookup (persian, part, hits DESC)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // جداول پرونده بالینی خصوصی دکتر (برای دیتابیس‌های قبلی)
    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS doctor_patient_charts (
            id VARCHAR(32) PRIMARY KEY,
            doctor_id VARCHAR(32) NOT NULL,
            patient_id VARCHAR(32) NOT NULL,
            history_text MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_doctor_patient_chart (doctor_id, patient_id),
            INDEX idx_chart_doctor (doctor_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $ignored) {
    }
    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS doctor_session_notes (
            id VARCHAR(32) PRIMARY KEY,
            doctor_id VARCHAR(32) NOT NULL,
            patient_id VARCHAR(32) NOT NULL,
            appointment_id VARCHAR(32) NOT NULL,
            note_text MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_session_note_appointment (appointment_id),
            INDEX idx_session_doctor_patient (doctor_id, patient_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $ignored) {
    }
    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS doctor_highlights (
            id VARCHAR(32) PRIMARY KEY,
            doctor_id VARCHAR(32) NOT NULL,
            patient_id VARCHAR(32) NOT NULL,
            excerpt TEXT NOT NULL,
            remark TEXT NULL,
            color VARCHAR(20) NOT NULL DEFAULT 'yellow',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_hl_doctor_patient (doctor_id, patient_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $ignored) {
    }

    // ارتقای نقش‌ها و ستون‌ها برای دیتابیس‌های قبلی
    try {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('ADMIN','DOCTOR','PATIENT','SECRETARY') NOT NULL DEFAULT 'PATIENT'");
    } catch (Throwable $ignored) {
    }
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
    } catch (Throwable $ignored) {
    }
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN preferred_doctor_id VARCHAR(32) NULL AFTER role");
    } catch (Throwable $ignored) {
    }
    try {
        $pdo->exec("ALTER TABLE doctor_profiles ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER session_price");
    } catch (Throwable $ignored) {
    }
    try {
        // درمانگرهای قبلی که فعال بودند را تأییدشده در نظر بگیر
        $pdo->exec("UPDATE doctor_profiles SET is_approved=1 WHERE is_active=1 AND is_approved=0");
    } catch (Throwable $ignored) {
    }
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN username VARCHAR(64) NULL AFTER id");
    } catch (Throwable $ignored) {
    }
    try {
        $pdo->exec("ALTER TABLE users MODIFY email VARCHAR(191) NULL");
    } catch (Throwable $ignored) {
    }

    $pdo->exec("UPDATE users SET username='admin' WHERE (username IS NULL OR username='') AND (email LIKE 'admin@%' OR role='ADMIN') LIMIT 1");
    $pdo->exec("UPDATE users SET username='doctor' WHERE (username IS NULL OR username='') AND (email LIKE 'doctor@%' OR role='DOCTOR') LIMIT 1");
    $pdo->exec("UPDATE users SET username='patient' WHERE (username IS NULL OR username='') AND (email LIKE 'patient@%' OR role='PATIENT') LIMIT 1");
    $pdo->exec("UPDATE users SET username='secretary' WHERE (username IS NULL OR username='') AND (email LIKE 'secretary@%' OR role='SECRETARY') LIMIT 1");
    $pdo->exec("UPDATE users SET username=CONCAT('user_', SUBSTRING(id,1,8)) WHERE username IS NULL OR username=''");
    try {
        $pdo->exec("ALTER TABLE users MODIFY username VARCHAR(64) NOT NULL");
    } catch (Throwable $ignored) {
    }
    try {
        $pdo->exec("CREATE UNIQUE INDEX users_username_unique ON users (username)");
    } catch (Throwable $ignored) {
    }

    $adminId = 'admin001mana01';
    $doctorUserId = 'doctor001mana01';
    $doctorProfileId = 'dprofile001mana';
    $patientId = 'patient001mana01';
    $secretaryId = 'secretary001mana';
    $pass123 = password_hash('123', PASSWORD_DEFAULT);
    $bio = "مشاوره تخصصی: فردی، خانواده (پیش از ازدواج و زناشویی)، کودک و نوجوان، تحصیلی و شغلی\nروان‌درمانی: درمان اضطراب، افسردگی و وسواس";

    // فقط کاربر جدید می‌سازد؛ رمز کاربران موجود را دست نمی‌زند
    // برای ریست اضطراری رمز حساب‌های نمونه: /install?reset_passwords=1
    $resetPasswords = isset($_GET['reset_passwords']) && $_GET['reset_passwords'] === '1';
    $upsertUser = function (
        string $id,
        string $username,
        string $name,
        string $role,
        ?string $phone = null
    ) use ($pdo, $pass123, $resetPasswords): void {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if (!$stmt->fetch()) {
            $pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,must_change_password) VALUES (?,?,?,?,?,?,?,1)')
                ->execute([$id, $username, $name, $username . '@manaclinic.local', $phone, $pass123, $role]);
        } elseif ($resetPasswords) {
            $pdo->prepare('UPDATE users SET name=?, role=?, password_hash=?, must_change_password=1, phone=COALESCE(phone, ?) WHERE username=?')
                ->execute([$name, $role, $pass123, $phone, $username]);
        } else {
            $pdo->prepare('UPDATE users SET name=?, role=?, phone=COALESCE(phone, ?) WHERE username=?')
                ->execute([$name, $role, $phone, $username]);
        }
    };

    $upsertUser($adminId, 'admin', 'مدیر سایت', 'ADMIN', '09120000000');
    $upsertUser($doctorUserId, 'doctor', 'دکتر شیوا گرانمایه پور', 'DOCTOR', '09121111111');
    $upsertUser($patientId, 'patient', 'علی رضایی', 'PATIENT', '09123333333');
    $upsertUser($secretaryId, 'secretary', 'منشی کلینیک', 'SECRETARY', '09124444444');

    $doctorRow = $pdo->query("SELECT id FROM users WHERE username='doctor' LIMIT 1")->fetch();
    if ($doctorRow) {
        $dp = $pdo->prepare('SELECT id FROM doctor_profiles WHERE user_id=?');
        $dp->execute([$doctorRow['id']]);
        if (!$dp->fetch()) {
            $pdo->prepare('INSERT INTO doctor_profiles (id,user_id,specialty,bio,session_price,is_approved,is_active) VALUES (?,?,?,?,?,1,1)')
                ->execute([$doctorProfileId, $doctorRow['id'], 'روان‌درمانی شناختی-رفتاری', $bio, 3000000]);
        } else {
            $pdo->prepare('UPDATE doctor_profiles SET specialty=?, bio=?, session_price=3000000, is_approved=1, is_active=1 WHERE user_id=?')
                ->execute(['روان‌درمانی شناختی-رفتاری', $bio, $doctorRow['id']]);
        }
    }

    $author = $pdo->query("SELECT id FROM users WHERE username='doctor' LIMIT 1")->fetch();
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

        $dp = $pdo->prepare('SELECT id FROM doctor_profiles WHERE user_id=? LIMIT 1');
        $dp->execute([$author['id']]);
        $dp = $dp->fetch();
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

    ensure_name_transliterations_schema($pdo);

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
      <p class="ok">نصب / ارتقا با موفقیت انجام شد.</p>
      <p>اگر حساب از قبل نبود، با این مشخصات ساخته شد (رمز اولیه <code>123</code> و اجباری به عوض کردن):</p>
      <ul>
        <li>ادمین: <code>admin</code></li>
        <li>دکتر: <code>doctor</code></li>
        <li>بیمار: <code>patient</code></li>
        <li>منشی: <code>secretary</code></li>
      </ul>
      <p>اگر قبلاً رمز را عوض کرده بودید، همان رمز جدیدتان معتبر است و ریست نشده.</p>
      <?php if (!empty($resetPasswords)): ?>
        <p class="ok">رمز حساب‌های نمونه به <code>123</code> ریست شد.</p>
      <?php endif; ?>
      <p><a href="/">رفتن به سایت</a></p>
      <p style="color:#b33a3a">بعد از نصب، فایل <code>install.php</code> را از هاست حذف کنید.</p>
    <?php else: ?>
      <p class="err">خطا: <?= htmlspecialchars((string)$error) ?></p>
      <p>ابتدا در cPanel دیتابیس بسازید و <code>config.php</code> را پر کنید.</p>
    <?php endif; ?>
  </div>
</body>
</html>
