<?php
declare(strict_types=1);

/**
 * نویسنده مقاله را پیدا یا ایجاد می‌کند.
 */
function seed_article_author_id(PDO $pdo, string $name, string $usernameHint = ''): ?string
{
    $stmt = $pdo->prepare("SELECT id FROM users WHERE name=? AND role='DOCTOR' LIMIT 1");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $pdo->prepare('
          UPDATE doctor_profiles
          SET is_approved=1, is_active=1,
              specialty=COALESCE(NULLIF(specialty,\'\'), ?),
              bio=COALESCE(NULLIF(bio,\'\'), ?)
          WHERE user_id=?
        ')->execute([
            'روانشناسی بالینی و روان‌درمانی',
            'تمرکز بر اضطراب، تنظیم هیجان و همراهی تخصصی در مسیر درمان.',
            (string) $id,
        ]);
        return (string) $id;
    }

    $base = $usernameHint !== '' ? $usernameHint : 'doctor_' . substr(md5($name), 0, 8);
    $username = $base;
    $n = 1;
    while (true) {
        $c = $pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
        $c->execute([$username]);
        if (!$c->fetch()) {
            break;
        }
        $username = $base . $n;
        $n++;
    }

    $userId = cuid();
    $email = $username . '@manaclinic.local';
    $pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,must_change_password) VALUES (?,?,?,?,?,?,?,1)')
        ->execute([$userId, $username, $name, $email, '09100000000', $pass, 'DOCTOR']);

    $pdo->prepare('INSERT INTO doctor_profiles (id,user_id,specialty,bio,session_price,is_approved,is_active,created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([
            cuid(),
            $userId,
            'روانشناسی بالینی و روان‌درمانی',
            'تمرکز بر اضطراب، تنظیم هیجان و همراهی تخصصی در مسیر درمان.',
            3000000,
            1,
            1,
            '2020-01-01 00:00:00',
        ]);

    return $userId;
}

/** دکتر عطیه گارسچی را برای نمایش در کاشی متخصصان فعال و اول می‌کند */
function ensure_doctor_atiyeh_garsichi(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $userId = seed_article_author_id($pdo, 'دکتر عطیه گارسچی', 'atiyeh_garsichi');
    if (!$userId) {
        return;
    }

    $pdo->prepare("
      UPDATE doctor_profiles
      SET is_approved=1, is_active=1,
          specialty='روانشناسی بالینی و روان‌درمانی',
          bio='تمرکز بر اضطراب، تنظیم هیجان و همراهی تخصصی در مسیر درمان.',
          created_at='2020-01-01 00:00:00'
      WHERE user_id=?
    ")->execute([$userId]);
}

/**
 * هم‌تراز کردن خلاصه مقالات + درج مقالات ویژه.
 */
function ensure_featured_psychology_article(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    ensure_doctor_atiyeh_garsichi($pdo);

    $cards = [
        'modiriat-ezterab' => [
            'title' => 'چگونه اضطراب روزمره را مدیریت کنیم؟',
            'excerpt' => 'اضطراب بخشی طبیعی از زندگی است؛ با تنفس آگاهانه، نظم روزانه و مراقبت به‌موقع می‌توان فشار آن را کم کرد و آرامش بیشتری به روزمرگی برگرداند.',
        ],
        'khab-salem' => [
            'title' => 'اهمیت خواب سالم برای آرامش ذهن و سلامت روان',
            'excerpt' => 'خواب کافی پایهٔ تمرکز، خلق پایدار و آرامش ذهن است؛ بی‌خوابی می‌تواند اضطراب و بی‌حوصلگی را شدیدتر کند و انرژی روز بعد را پایین بیاورد.',
        ],
        'mehrbani-ba-khod-va-ezterab' => [
            'title' => 'مهربانی با خود: چطور با اضطراب و صدای منتقد درونی آشتی کنیم؟',
            'excerpt' => 'بر اساس پژوهش‌های تازه روانشناسی، تقویت مهربانی با خود می‌تواند استرس، اضطراب و خلق پایین را کم کند و رابطهٔ مهربان‌تری با خودتان بسازد.',
        ],
        'tanzim-hayajan-va-ezterab' => [
            'title' => 'تنظیم هیجان؛ کلید کاهش اضطراب در درمان‌های روز دنیا',
            'excerpt' => 'پژوهش‌های تازه نشان می‌دهد بهبود تنظیم هیجان در مسیر درمان می‌تواند کاهش اضطراب را پیش‌بینی کند؛ از نشخوار فکری تا بازنگری شناختی.',
        ],
    ];

    $upd = $pdo->prepare('UPDATE articles SET title=?, excerpt=? WHERE slug=?');
    foreach ($cards as $slug => $row) {
        $upd->execute([$row['title'], $row['excerpt'], $slug]);
    }

    // مقاله مهربانی با خود
    $slug = 'mehrbani-ba-khod-va-ezterab';
    $check = $pdo->prepare('SELECT id FROM articles WHERE slug=? LIMIT 1');
    $check->execute([$slug]);
    if (!$check->fetch()) {
        $authorId = $pdo->query("SELECT id FROM users WHERE role='DOCTOR' ORDER BY created_at ASC LIMIT 1")->fetchColumn()
            ?: $pdo->query("SELECT id FROM users WHERE role='ADMIN' ORDER BY created_at ASC LIMIT 1")->fetchColumn();
        if ($authorId) {
            $content = <<<'HTML'
<p>خیلی از ما وقتی اشتباه می‌کنیم یا احساس ضعف داریم، با خودمان سخت‌گیرتر از هر کس دیگری حرف می‌زنیم. این صدای تند درونی گاهی به‌اشتباه انگیزه به‌نظر می‌رسد، اما پژوهش‌های تازه روانشناسی نشان می‌دهد که <strong>مهربانی با خود (Self-Compassion)</strong> نه تنبلی است و نه خودخواهی؛ بلکه مهارتی آموختنی برای مراقبت از سلامت روان است.</p>
<h2>مهربانی با خود یعنی چه؟</h2>
<p>کریستین نف، یکی از پژوهشگران شناخته‌شده این حوزه، مهربانی با خود را در سه بخش ساده توضیح می‌دهد:</p>
<ul>
  <li><strong>مهربانی به‌جای قضاوت:</strong> وقتی رنج می‌کشید، با خودتان مثل یک دوست دلسوز حرف بزنید نه مثل یک قاضی سخت‌گیر.</li>
  <li><strong>اشتراک انسانی:</strong> به‌یاد بیاورید که اشتباه کردن، خسته شدن و آسیب‌دیدن بخشی از تجربه انسانی است؛ شما تنها نیستید.</li>
  <li><strong>ذهن‌آگاهی:</strong> احساسات را ببینید و نام بگذارید، بدون اینکه در آن‌ها غرق شوید یا انکارشان کنید.</li>
</ul>
<h2>پژوهش‌های تازه چه می‌گویند؟</h2>
<p>در سال‌های ۲۰۲۵ و ۲۰۲۶، چند مرور نظام‌مند و کارآزمایی بالینی دوباره نشان دادند که مداخلات مبتنی بر مهربانی با خود می‌توانند به کاهش اضطراب، نشانه‌های افسردگی و استرس ادراک‌شده کمک کنند.</p>
<p><em>این مطلب جنبه آموزشی دارد و تشخیص یا درمان پزشکی محسوب نمی‌شود.</em></p>
HTML;
            $pdo->prepare('
              INSERT INTO articles (id, title, slug, content, excerpt, published, published_at, author_id)
              VALUES (?,?,?,?,?,1, DATE_ADD(NOW(), INTERVAL 1 MINUTE), ?)
            ')->execute([
                cuid(),
                $cards[$slug]['title'],
                $slug,
                $content,
                $cards[$slug]['excerpt'],
                $authorId,
            ]);
        }
    }

    // مقاله دکتر عطیه گارسچی — تنظیم هیجان
    $slug2 = 'tanzim-hayajan-va-ezterab';
    $check->execute([$slug2]);
    if ($check->fetch()) {
        return;
    }

    $authorId = seed_article_author_id($pdo, 'دکتر عطیه گارسچی', 'atiyeh_garsichi');
    if (!$authorId) {
        return;
    }

    $content2 = <<<'HTML'
<p>وقتی اضطراب بالا می‌رود، خیلی‌ها فقط به «آرام شدن» فکر می‌کنند. اما پژوهش‌های تازه روان‌درمانی نشان می‌دهد بخش مهمی از مسیر درمان، یادگیری <strong>تنظیم هیجان (Emotion Regulation)</strong> است؛ یعنی چطور با نگرانی، نشخوار فکری و هیجان‌های شدید کنار بیاییم، نه اینکه صرفاً آن‌ها را سرکوب کنیم.</p>

<h2>تنظیم هیجان چیست؟</h2>
<p>تنظیم هیجان مجموعه مهارت‌هایی است که کمک می‌کند شدت، مدت و تأثیر احساسات روی رفتار و فکر را مدیریت کنیم. این مهارت‌ها می‌توانند سازگار باشند (مثل نام‌گذاری احساس، فاصله گرفتن از فکر، بازنگری شناختی) یا ناسازگار (مثل سرکوب هیجان، نگرانی مداوم، نشخوار و حواس‌پرتی اجتنابی).</p>

<h2>یافته‌های تازه دنیا چه می‌گویند؟</h2>
<p>در مطالعات اخیر روی درمان‌های مبتنی بر پذیرش و تعهد (ACT) و درمان شناختی‌رفتاری (CBT)، پژوهشگران بررسی کردند کدام راهبردهای تنظیم هیجان با کاهش اضطراب مرتبط‌اند. یافته‌ها نشان می‌دهد کاهش راهبردهای ناسازگار — به‌ویژه <strong>نگرانی مداوم، نشخوار فکری و باورهای فراشناختی منفی</strong> — اغلب پیوند قوی‌تری با بهتر شدن علائم دارد.</p>
<p>همچنین در درمان‌های شناختی‌رفتاری اضطراب اجتماعی، بهبود تنظیم هیجان در طول درمان توانسته کاهش بعدی اضطراب را پیش‌بینی کند. به زبان ساده: وقتی فرد یاد می‌گیرد هیجان را بهتر مدیریت کند، معمولاً علائم اضطراب هم در ادامه کاهش می‌یابد.</p>
<p>در برخی کارآزمایی‌های اضطراب فراگیر نیز مشاهده شده درمان روان‌شناختی مؤثر می‌تواند با تغییرات زیستی مرتبط با انعطاف عصبی همراه باشد؛ هرچند این یافته‌ها هنوز با احتیاط تفسیر می‌شوند و به‌تنهایی معیار موفقیت درمان نیستند.</p>

<h2>در عمل چه کمکی می‌کند؟</h2>
<ul>
  <li><strong>شناسایی الگو:</strong> بفهمید در اضطراب بیشتر نگران می‌شوید، نشخوار می‌کنید یا احساس را قورت می‌دهید.</li>
  <li><strong>کاهش سرکوب:</strong> به‌جای «نباید احساس کنم»، بگویید «الان اضطراب هست و می‌توانم با آن بمانم و قدم بعدی را بردارم.»</li>
  <li><strong>بازنگری شناختی:</strong> فکر فاجعه‌ساز را با شواهد واقعی سبک‌سنگین کنید.</li>
  <li><strong>حضور بدنی:</strong> نفس آرام، تماس پا با زمین و نام‌گذاری احساس، شدت موج هیجانی را کم می‌کند.</li>
</ul>

<h2>یک تمرین کوتاه</h2>
<ol>
  <li>هیجان را نام بگذارید: «اضطراب / خشم / شرم».</li>
  <li>شدت آن را از ۰ تا ۱۰ بگویید.</li>
  <li>یک جمله حمایتی بسازید: «این احساس گذراست؛ لازم نیست همین حالا همه چیز را حل کنم.»</li>
  <li>یک اقدام کوچک و واقعی انجام دهید (پیام دادن، کمی راه رفتن، نوشتن نگرانی روی کاغذ).</li>
</ol>

<h2>چه زمانی به متخصص مراجعه کنیم؟</h2>
<p>اگر نگرانی بیشتر روزها را پر کرده، خواب و تمرکز را مختل کرده، یا از موقعیت‌های مهم زندگی اجتناب می‌کنید، یادگیری تنظیم هیجان در چارچوب روان‌درمانی حرفه‌ای مؤثرتر است. در شرایط بحران یا افکار آسیب به خود، فوراً با اورژانس (۱۱۵) تماس بگیرید.</p>

<p><em>نویسنده: دکتر عطیه گارسچی — این مطلب جنبه آموزشی دارد و جایگزین تشخیص یا درمان تخصصی نیست.</em></p>
HTML;

    $pdo->prepare('
      INSERT INTO articles (id, title, slug, content, excerpt, published, published_at, author_id)
      VALUES (?,?,?,?,?,1, DATE_ADD(NOW(), INTERVAL 2 MINUTE), ?)
    ')->execute([
        cuid(),
        $cards[$slug2]['title'],
        $slug2,
        $content2,
        $cards[$slug2]['excerpt'],
        $authorId,
    ]);
}
