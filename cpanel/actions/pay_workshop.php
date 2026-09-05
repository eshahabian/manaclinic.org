<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/workshops.php';

$user = current_user();
if (!$user || $user['role'] !== 'PATIENT') {
    http_response_code(401);
    echo json_encode(['error' => 'لطفاً با حساب مراجعه‌کننده وارد شوید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

ensure_workshop_schema($pdo);
$enrollmentId = post('enrollmentId');
$useWallet = isset($_POST['use_wallet']) || post('use_wallet') === '1';

$stmt = $pdo->prepare("
  SELECT e.*, w.title, w.price, wp.id AS payment_id, wp.amount, wp.status AS pay_status
  FROM workshop_enrollments e
  JOIN workshops w ON w.id = e.workshop_id
  JOIN workshop_payments wp ON wp.enrollment_id = e.id
  WHERE e.id = ? AND e.patient_id = ?
  LIMIT 1
");
$stmt->execute([$enrollmentId, $user['id']]);
$row = $stmt->fetch();
if (!$row || $row['status'] !== 'PENDING_PAYMENT' || $row['pay_status'] !== 'PENDING') {
    http_response_code(400);
    echo json_encode(['error' => 'این ثبت‌نام قابل پرداخت نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$amount = (int) $row['amount'];
if ($amount <= 0) {
    $pdo->beginTransaction();
    try {
        confirm_workshop_payment($pdo, [
            'id' => $row['payment_id'],
            'enrollment_id' => $enrollmentId,
            'amount' => 0,
            'wallet_amount' => 0,
            'ref_id' => null,
        ]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'ثبت‌نام تأیید شد.'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$walletAmount = 0;
if ($useWallet) {
    $wallet = ensure_wallet($pdo, $user['id']);
    $walletAmount = min((int) $wallet['balance'], $amount);
}
$onlineAmount = $amount - $walletAmount;

if ($onlineAmount > 0 && !online_payment_enabled($config)) {
    if ($walletAmount <= 0) {
        http_response_code(503);
        echo json_encode(['error' => online_payment_disabled_message()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(503);
    echo json_encode([
        'error' => online_payment_disabled_message() . ' (موجودی کیف پول برای کل مبلغ کافی نیست.)',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo->beginTransaction();
try {
    if ($walletAmount > 0) {
        wallet_debit_balance(
            $pdo,
            $user['id'],
            $walletAmount,
            'workshop_enrollment',
            $enrollmentId,
            'پرداخت کارگاه: ' . $row['title']
        );
        $pdo->prepare('UPDATE workshop_payments SET wallet_amount=? WHERE id=?')
            ->execute([$walletAmount, $row['payment_id']]);
    }

    if ($onlineAmount <= 0) {
        confirm_workshop_payment($pdo, [
            'id' => $row['payment_id'],
            'enrollment_id' => $enrollmentId,
            'amount' => $amount,
            'wallet_amount' => $walletAmount,
            'ref_id' => null,
        ]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'پرداخت از کیف پول انجام شد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $callback = rtrim($config['app_url'], '/') . '/payments/verify?kind=workshop';
    $pay = zarinpal_request(
        $config,
        $onlineAmount,
        'پرداخت کارگاه مانا کلینیک - ' . $enrollmentId,
        $callback,
        $user['email'] ?? null
    );
    $pdo->prepare('UPDATE workshop_payments SET authority=? WHERE id=?')->execute([$pay['authority'], $row['payment_id']]);
    $pdo->commit();

    echo json_encode(['paymentUrl' => $pay['paymentUrl']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
