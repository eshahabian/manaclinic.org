<?php
declare(strict_types=1);

function ensure_petty_cash_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS petty_cash_entries (
        id VARCHAR(32) PRIMARY KEY,
        kind ENUM('IN','OUT') NOT NULL,
        item_name VARCHAR(120) NOT NULL DEFAULT '',
        amount INT NOT NULL,
        entry_date DATE NOT NULL,
        note VARCHAR(500) NULL,
        receipt_path VARCHAR(255) NULL,
        created_by VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        pay_account VARCHAR(40) NULL,
        approver VARCHAR(40) NULL,
        INDEX idx_petty_date (entry_date),
        INDEX idx_petty_item (item_name)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $addColumn = static function (PDO $pdo, string $column, string $ddl): void {
        $has = $pdo->query('SHOW COLUMNS FROM petty_cash_entries LIKE ' . $pdo->quote($column))->fetch();
        if (!$has) {
            $pdo->exec('ALTER TABLE petty_cash_entries ADD COLUMN ' . $ddl);
        }
    };
    $addColumn($pdo, 'pay_account', 'pay_account VARCHAR(40) NULL AFTER created_by');
    $addColumn($pdo, 'approver', 'approver VARCHAR(40) NULL AFTER pay_account');
    $ready = true;
}

/** @return array<string, string> */
function petty_cash_pay_accounts(): array
{
    return [
        'blue' => 'تنخواه بلو بانک',
        'parsian' => 'تنخواه پارسیان',
        'shirkhodaei' => 'عطیه شیرخدایی',
        'moradi' => 'هستی مرادی',
        'garsichi' => 'عطیه گارسچی',
        'geranmaye' => 'شیوا گرانمایه',
    ];
}

/** مبلغ ورودی فقط به بلو کارت یا پارسیان می‌نشیند. @return array<string, string> */
function petty_cash_in_accounts(): array
{
    return [
        'blue' => 'بلو کارت',
        'parsian' => 'پارسیان',
    ];
}

/** @return array<string, string> */
function petty_cash_approvers(): array
{
    return [
        'garsichi' => 'عطیه گارسچی',
        'geranmaye' => 'شیوا گرانمایه پور',
    ];
}

function petty_cash_option_label(array $options, string $key): string
{
    return (string) ($options[$key] ?? '');
}

function petty_cash_select_html(string $name, string $id, string $label, array $options, bool $required = true): string
{
    ob_start();
    ?>
    <div>
      <label class="label" for="<?= e($id) ?>"><?= e($label) ?></label>
      <select class="input" id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $required ? ' required' : '' ?>>
        <option value="">انتخاب کنید</option>
        <?php foreach ($options as $key => $title): ?>
          <option value="<?= e((string) $key) ?>"><?= e((string) $title) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php
    return (string) ob_get_clean();
}

function petty_cash_doctor_allowed(?array $user): bool
{
    if (!$user || (string) ($user['role'] ?? '') !== 'DOCTOR') {
        return false;
    }
    if ((!function_exists('doctor_is_shiva') || !function_exists('doctor_is_garsichi')) && is_file(__DIR__ . '/doctor_profile_fields.php')) {
        require_once __DIR__ . '/doctor_profile_fields.php';
    }

    return (function_exists('doctor_is_shiva') && doctor_is_shiva($user))
        || (function_exists('doctor_is_garsichi') && doctor_is_garsichi($user));
}

function petty_cash_user_allowed(?array $user = null): bool
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user) {
        return false;
    }
    $role = (string) ($user['role'] ?? '');
    if ($role === 'SECRETARY' || $role === 'ACCOUNTANT') {
        return true;
    }

    return petty_cash_doctor_allowed($user);
}

/** برنامه کارکنان: هر منشی فعال، به‌علاوه دکتر عطیه گارسچی و دکتر شیوا گرانمایه. حسابدار فقط پنل سایت را دارد. */
function petty_cash_staff_app_allowed(?array $user = null): bool
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user) {
        return false;
    }
    if ((string) ($user['role'] ?? '') === 'SECRETARY') {
        return true;
    }

    return petty_cash_doctor_allowed($user);
}

/** @return array{start:string,end:string,year:int,month:int,label:string} */
function petty_cash_month_range(int $jy, int $jm): array
{
    if ($jy < 1300 || $jm < 1 || $jm > 12) {
        $meta = jalali_current_month_meta();
        $jy = (int) ($meta['year'] ?? 0);
        $jm = (int) ($meta['month'] ?? 1);
    }
    $len = function_exists('jalali_month_length') ? jalali_month_length($jy, $jm) : 31;
    $start = jalali_ymd($jy, $jm, 1);
    $end = jalali_ymd($jy, $jm, $len);

    return [
        'start' => $start,
        'end' => $end,
        'year' => $jy,
        'month' => $jm,
        'label' => petty_cash_month_name($jm) . ' ' . to_fa_digits((string) $jy),
    ];
}

function petty_cash_month_name(int $jm): string
{
    $months = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
        5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
        9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    ];

    return $months[$jm] ?? '';
}

function petty_cash_parse_parts(int $jy, int $jm, int $jd): ?string
{
    if ($jy < 1300 || $jy > 1600 || $jm < 1 || $jm > 12 || $jd < 1) {
        return null;
    }
    $len = function_exists('jalali_month_length') ? jalali_month_length($jy, $jm) : 31;
    if ($jd > $len) {
        return null;
    }

    return jalali_ymd($jy, $jm, $jd);
}

function petty_cash_date_fields(int $jy, int $jm, int $jd): string
{
    static $n = 0;
    $n++;
    $id = 'petty-entry-date-' . $n;
    $value = to_fa_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
    ob_start();
    ?>
    <div>
      <label class="label" for="<?= e($id) ?>">تاریخ</label>
      <input class="input" id="<?= e($id) ?>" name="entry_date" type="text" data-jdp data-jdp-only-date readonly autocomplete="off" required style="cursor:pointer" value="<?= e($value) ?>" placeholder="تاریخ شمسی">
    </div>
    <?php
    return (string) ob_get_clean();
}

function petty_cash_datepicker_assets(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;

    return '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">'
        . '<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>'
        . '<script>if(window.jalaliDatepicker){jalaliDatepicker.startWatch({selector:"[data-jdp]",time:false,hideAfterChange:true,showTodayBtn:true,showEmptyBtn:true,autoReadOnlyInput:true,persianDigits:true,zIndex:100000,container:"body"});}</script>';
}

/** @return array{in:int,out:int,items:list<array<string,mixed>>,entries:list<array<string,mixed>>} */
function petty_cash_month_report(PDO $pdo, string $start, string $end): array
{
    ensure_petty_cash_schema($pdo);
    $sum = $pdo->prepare("SELECT kind, COALESCE(SUM(amount),0) AS total FROM petty_cash_entries WHERE entry_date BETWEEN ? AND ? GROUP BY kind");
    $sum->execute([$start, $end]);
    $in = 0;
    $out = 0;
    foreach ($sum->fetchAll() as $row) {
        if (($row['kind'] ?? '') === 'IN') {
            $in = (int) $row['total'];
        } elseif (($row['kind'] ?? '') === 'OUT') {
            $out = (int) $row['total'];
        }
    }
    $items = $pdo->prepare("
      SELECT item_name, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
      FROM petty_cash_entries
      WHERE kind='OUT' AND entry_date BETWEEN ? AND ?
      GROUP BY item_name
      ORDER BY total DESC, item_name ASC
    ");
    $items->execute([$start, $end]);
    $entries = $pdo->prepare("
      SELECT e.*, u.name AS created_by_name
      FROM petty_cash_entries e
      JOIN users u ON u.id = e.created_by
      WHERE e.entry_date BETWEEN ? AND ?
      ORDER BY e.entry_date DESC, e.created_at DESC
    ");
    $entries->execute([$start, $end]);

    return [
        'in' => $in,
        'out' => $out,
        'items' => $items->fetchAll() ?: [],
        'entries' => $entries->fetchAll() ?: [],
    ];
}

function petty_cash_apply_post(PDO $pdo, array $user): string
{
    if (!petty_cash_user_allowed($user)) {
        throw new RuntimeException('دسترسی به تنخواه مجاز نیست.');
    }
    if (post('action') === 'delete') {
        $id = trim((string) post('id'));
        if (!preg_match('/^[a-zA-Z0-9_-]{8,40}$/', $id)) {
            throw new RuntimeException('این مورد برای حذف پیدا نشد.');
        }
        ensure_petty_cash_schema($pdo);
        $stmt = $pdo->prepare('SELECT receipt_path FROM petty_cash_entries WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('این مورد دیگر در تنخواه نیست.');
        }
        $pdo->prepare('DELETE FROM petty_cash_entries WHERE id=?')->execute([$id]);
        $receipt = trim((string) ($row['receipt_path'] ?? ''));
        if ($receipt !== '') {
            $left = $pdo->prepare('SELECT COUNT(*) FROM petty_cash_entries WHERE receipt_path=?');
            $left->execute([$receipt]);
            if ((int) $left->fetchColumn() === 0 && function_exists('staff_receipt_abs')) {
                $abs = staff_receipt_abs($receipt);
                if (is_file($abs)) {
                    unlink($abs);
                }
            }
        }

        return 'مورد از تنخواه حذف شد.';
    }

    $kind = post('action') === 'in' ? 'IN' : (post('action') === 'out' ? 'OUT' : '');
    $date = function_exists('parse_user_date') ? parse_user_date((string) post('entry_date')) : null;
    if ($date === null) {
        $date = petty_cash_parse_parts((int) post('entry_jy'), (int) post('entry_jm'), (int) post('entry_jd'));
    }
    $note = trim((string) post('note'));
    if ($kind === '' || $date === null) {
        throw new RuntimeException('مبلغ و تاریخ را درست وارد کنید.');
    }
    if (mb_strlen($note) > 500) {
        throw new RuntimeException('توضیح طولانی است.');
    }

    $payAccount = '';
    $approver = '';
    if ($kind === 'IN') {
        $payAccount = trim((string) post('pay_account'));
        if (!isset(petty_cash_in_accounts()[$payAccount])) {
            throw new RuntimeException('مبلغ ورودی فقط برای بلو کارت یا پارسیان ثبت می‌شود.');
        }
    } elseif ($kind === 'OUT') {
        $payAccount = trim((string) post('pay_account'));
        $approver = trim((string) post('approver'));
        if (!isset(petty_cash_pay_accounts()[$payAccount])) {
            throw new RuntimeException('حساب پرداخت را انتخاب کنید.');
        }
        if (!isset(petty_cash_approvers()[$approver])) {
            throw new RuntimeException('تاییدکننده را انتخاب کنید.');
        }
    }

    /** @var list<array{name:string,amount:int}> $lines */
    $lines = [];
    if ($kind === 'OUT') {
        $names = $_POST['item_name'] ?? [];
        $amounts = $_POST['amount'] ?? [];
        if (!is_array($names)) {
            $names = [(string) $names];
        }
        if (!is_array($amounts)) {
            $amounts = [(string) $amounts];
        }
        $count = max(count($names), count($amounts));
        if ($count > 30) {
            throw new RuntimeException('در هر ثبت حداکثر ۳۰ قلم می‌شود.');
        }
        for ($i = 0; $i < $count; $i++) {
            $name = trim((string) ($names[$i] ?? ''));
            $rawAmount = trim((string) ($amounts[$i] ?? ''));
            if ($name === '' && $rawAmount === '') {
                continue;
            }
            if ($name === '' || $rawAmount === '') {
                throw new RuntimeException('هر قلم باید هم نام داشته باشد هم مبلغ.');
            }
            if (mb_strlen($name) > 120) {
                throw new RuntimeException('نام قلم طولانی است.');
            }
            $money = parse_user_money($rawAmount);
            if ($money <= 0) {
                throw new RuntimeException('مبلغ هر قلم را درست وارد کنید.');
            }
            $lines[] = ['name' => $name, 'amount' => $money];
        }
        if (!$lines) {
            throw new RuntimeException('قلم هزینه و مبلغ را بنویسید.');
        }
    } else {
        $money = parse_user_money((string) post('amount'));
        if ($money <= 0) {
            throw new RuntimeException('مبلغ و تاریخ را درست وارد کنید.');
        }
        $lines[] = ['name' => '', 'amount' => $money];
    }

    ensure_petty_cash_schema($pdo);
    $receipt = null;
    $file = $_FILES['receipt'] ?? null;
    if ($kind === 'OUT' && is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (!function_exists('staff_save_receipt')) {
            throw new RuntimeException('رسید ذخیره نشد.');
        }
        $receipt = staff_save_receipt($file, cuid());
    }

    $insert = $pdo->prepare('
      INSERT INTO petty_cash_entries (id, kind, item_name, amount, entry_date, note, receipt_path, created_by, pay_account, approver)
      VALUES (?,?,?,?,?,?,?,?,?,?)
    ');
    $createdBy = (string) ($user['id'] ?? '');
    $noteValue = $note !== '' ? $note : null;
    $accountValue = $payAccount !== '' ? $payAccount : null;
    $approverValue = $approver !== '' ? $approver : null;
    try {
        $pdo->beginTransaction();
        foreach ($lines as $line) {
            $insert->execute([
                cuid(),
                $kind,
                $line['name'],
                $line['amount'],
                $date,
                $noteValue,
                $receipt,
                $createdBy,
                $accountValue,
                $approverValue,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof RuntimeException) {
            throw $e;
        }
        throw new RuntimeException('ثبت تنخواه انجام نشد.');
    }

    if ($kind === 'IN') {
        return 'مبلغ ورودی ثبت شد.';
    }
    if (count($lines) === 1) {
        return 'هزینه ثبت شد.';
    }

    return to_fa_digits((string) count($lines)) . ' قلم هزینه ثبت شد.';
}
