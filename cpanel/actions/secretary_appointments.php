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

if ($action === 'approve_booking') {
    $appointmentId = trim((string) post('appointment_id'));
    try {
        $result = staff_approve_appointment_booking($pdo, $appointmentId, $user);
        if (!str_contains($next, 'tab=')) {
            $next .= (str_contains($next, '?') ? '&' : '?') . 'tab=upcoming';
        }
        flash_set('success', 'درخواست «' . (string) $result['patient_name'] . '» بدون پرداخت پذیرفته شد و در حال بررسی ماند. با «پرداخت شده» رزرو می‌شود.');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        if (!str_contains($next, 'tab=')) {
            $next .= (str_contains($next, '?') ? '&' : '?') . 'tab=upcoming';
        }
    }
    redirect($next);
}

if ($action === 'reject_booking') {
    $appointmentId = trim((string) post('appointment_id'));
    try {
        $result = staff_reject_appointment_booking($pdo, $appointmentId, $user, (string) post('note'));
        if (!str_contains($next, 'tab=')) {
            $next .= (str_contains($next, '?') ? '&' : '?') . 'tab=upcoming';
        }
        flash_set('success', 'درخواست «' . (string) $result['patient_name'] . '» رد شد و ساعت آزاد گردید.');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        if (!str_contains($next, 'tab=')) {
            $next .= (str_contains($next, '?') ? '&' : '?') . 'tab=upcoming';
        }
    }
    redirect($next);
}

if ($action === 'set_time') {
    $appointmentId = trim((string) post('appointment_id'));
    try {
        $result = appointment_reschedule(
            $pdo,
            $user,
            $appointmentId,
            (int) post('hour'),
            (int) post('minute')
        );
        $when = format_fa_datetime((string) $result['starts_at']);
        if (!empty($result['unchanged'])) {
            flash_set('success', 'ساعت همین است: ' . $when);
        } else {
            flash_set('success', 'ساعت نوبت «' . (string) $result['patient_name'] . '» به ' . $when . ' تغییر کرد.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($next);
}

if ($action === 'set_room') {
    $appointmentId = trim((string) post('appointment_id'));
    try {
        if (!function_exists('appointment_assert_room_choice')) {
            require_once __DIR__ . '/../includes/appointment_session.php';
        }
        $stmt = $pdo->prepare('SELECT * FROM appointments WHERE id=? LIMIT 1');
        $stmt->execute([$appointmentId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('نوبت یافت نشد.');
        }
        $roomNo = appointment_parse_room_choice((string) post('room_no'));
        $roomNo = appointment_assert_room_choice($pdo, $row, $roomNo);
        if ($roomNo < 1) {
            if (function_exists('appointment_is_online_mode') && appointment_is_online_mode($row)) {
                flash_set('success', 'جلسه آنلاین است و بدون اتاق می‌ماند.');
                redirect($next);
            }
            throw new RuntimeException('اتاق را انتخاب کنید.');
        }
        appointment_save_room($pdo, $user, $row, $roomNo);
        $savedRoom = function_exists('clinic_room_label') ? clinic_room_label($roomNo) : ('اتاق ' . $roomNo);
        flash_set('success', $savedRoom . ' برای این نوبت ثبت شد.');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($next);
}

if ($action === 'confirm_payment') {
    $appointmentId = trim((string) post('appointment_id'));
    try {
        $roomNo = function_exists('appointment_parse_room_choice')
            ? appointment_parse_room_choice((string) post('room_no'))
            : (int) post('room_no');
        if (post('pay_choice') === 'unpaid') {
            $result = staff_confirm_appointment_unpaid($pdo, $appointmentId, $user, (string) post('note'), $roomNo);
            $flash = 'وقت «' . (string) $result['patient_name'] . '» رزرو شد و ' . format_price((int) $result['amount']) . ' از کیف پولش کسر شد.';
        } else {
            $result = staff_confirm_appointment_payment($pdo, $appointmentId, $user, $_FILES['receipt'] ?? [], (string) post('note'), $roomNo);
            $flash = 'پرداخت «' . (string) $result['patient_name'] . '» ثبت شد و وقت رزرو گردید.';
        }
        if (!str_contains($next, 'tab=')) {
            $next .= (str_contains($next, '?') ? '&' : '?') . 'tab=upcoming';
        }
        flash_set('success', $flash);
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
        $result = secretary_mark_patient_cancelled($pdo, $appointmentId, $user, $note, (string) post('cancel_side'));
        if (!str_contains($next, 'tab=')) {
            $next .= (str_contains($next, '?') ? '&' : '?') . 'tab=cancelled';
        }
        $sideText = ((string) ($result['side'] ?? '')) === 'clinic' ? 'کلینیک' : 'مراجع';
        $refundText = (int) ($result['refundAmount'] ?? 0) > 0
            ? ' ' . format_price((int) $result['refundAmount']) . ' به کیف پول برگشت.'
            : '';
        flash_set('success', 'کنسلی از سمت ' . $sideText . ' برای «' . (string) $result['patient_name'] . '» ثبت شد.' . $refundText);
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
