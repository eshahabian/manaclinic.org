<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/appointment_cancel.php';
require_once __DIR__ . '/../includes/appointment_session.php';
require_once __DIR__ . '/../includes/appointment_payment.php';

$user = require_login(['SECRETARY', 'ADMIN']);
csrf_verify();

$action = post('action');
$next = safe_next_path(post('next')) ?? '/secretary/appointments';

if ($action === 'set_session_mode') {
    $appointmentId = trim((string) post('appointment_id'));
    $mode = appointment_normalize_session_mode(post('session_mode'));
    ensure_appointment_session_schema($pdo);
    if ($appointmentId === '') {
        flash_set('error', 'نوبت مشخص نیست.');
        redirect($next);
    }
    $stmt = $pdo->prepare('UPDATE appointments SET session_mode=? WHERE id=?');
    $stmt->execute([$mode, $appointmentId]);
    if ($stmt->rowCount() < 1) {
        // ممکن است مقدار قبلی همان باشد
        $check = $pdo->prepare('SELECT id FROM appointments WHERE id=? LIMIT 1');
        $check->execute([$appointmentId]);
        if (!$check->fetch()) {
            flash_set('error', 'نوبت یافت نشد.');
            redirect($next);
        }
    }
    flash_set('success', 'نوع جلسه به «' . appointment_session_mode_label($mode) . '» به‌روز شد.');
    redirect($next);
}

if ($action === 'confirm_payment') {
    $appointmentId = trim((string) post('appointment_id'));
    try {
        $result = staff_confirm_appointment_payment($pdo, $appointmentId, $user, $_FILES['receipt'] ?? []);
        if (!str_contains($next, 'tab=')) {
            $next .= (str_contains($next, '?') ? '&' : '?') . 'tab=upcoming';
        }
        flash_set('success', 'پرداخت «' . (string) $result['patient_name'] . '» تأیید و نوبت ثبت شد.');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        if (!str_contains($next, 'tab=')) {
            $next .= (str_contains($next, '?') ? '&' : '?') . 'tab=upcoming';
        }
    }
    redirect($next);
}

if ($action === 'cancel_patient') {
    $appointmentId = post('appointment_id');
    $note = post('note');
    try {
        $result = secretary_mark_patient_cancelled($pdo, $appointmentId, $user, $note);
        if (!str_contains($next, 'tab=')) {
            $next .= (str_contains($next, '?') ? '&' : '?') . 'tab=cancelled';
        }
        flash_set('success', 'کنسلی مراجع برای «' . (string) $result['patient_name'] . '» ثبت شد.');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        if (!str_contains($next, 'tab=')) {
            $next .= (str_contains($next, '?') ? '&' : '?') . 'tab=upcoming';
        }
    }
    redirect($next);
}

flash_set('error', 'درخواست نامعتبر است.');
redirect('/secretary/appointments');
