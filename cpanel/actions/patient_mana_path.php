<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../includes/mana_path.php';

mana_path_require_user($user);
ensure_mana_path_schema($pdo);

$patientId = (string) $user['id'];
$profile = mana_path_load_profile($pdo, $patientId);
$do = trim((string) ($_POST['do'] ?? ''));
$backRaw = trim((string) ($_POST['back'] ?? ''));
$back = '/dashboard/path';
if ($backRaw === '/dashboard/path2') {
    $back = '/dashboard/path2';
} elseif ($backRaw === '/dashboard/path') {
    $back = '/dashboard/path';
}

try {
    if ($do === 'intro') {
        $concerns = $_POST['concerns'] ?? [];
        if (!is_array($concerns)) {
            $concerns = [];
        }
        mana_path_save_intro($pdo, $profile, array_map('strval', $concerns), (string) ($_POST['gender'] ?? ''));
        flash_set('success', 'مسیرت ساخته شد.');
        redirect($back);
    }

    if ($do === 'add_concerns') {
        $concerns = $_POST['concerns'] ?? [];
        if (!is_array($concerns)) {
            $concerns = [];
        }
        mana_path_add_concerns($pdo, $profile, array_map('strval', $concerns));
        flash_set('success', 'موضوع تازه به مسیرت اضافه شد.');
        redirect($back);
    }

    if ($do === 'gender') {
        mana_path_set_gender($pdo, $profile, (string) ($_POST['gender'] ?? ''));
        flash_set('success', 'همراه ذخیره شد.');
        redirect($back);
    }

    if ($do === 'mood') {
        mana_path_set_mood($pdo, $profile, (int) ($_POST['mood'] ?? 0));
        flash_set('success', 'حالت ثبت شد. انرژی ذهن کمی بیشتر شد.');
        redirect($back);
    }

    if ($do === 'add_tree') {
        $treeId = trim((string) ($_POST['tree_id'] ?? ''));
        mana_path_add_tree($pdo, $profile, $treeId);
        flash_set('success', 'مسیر تازه باز شد.');
        redirect($back);
    }

    if ($do === 'mission') {
        $missionId = trim((string) ($_POST['mission_id'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        $res = mana_path_complete_mission($pdo, $profile, $missionId, ['note' => $note]);
        if (!empty($res['already'])) {
            flash_set('success', 'این کار امروز انجام شده.');
        } else {
            $msg = 'آفرین. انرژی ذهن +' . (int) ($res['xp'] ?? 0);
            if (!empty($res['advanced'])) {
                $msg .= ' مرحله امروز سفر هم ثبت شد.';
            }
            flash_set('success', $msg);
        }
        $fresh = mana_path_load_profile($pdo, $patientId);
        if (!empty($fresh['crisis_flag'])) {
            flash_set('error', 'اگر در بحران هستی، اول ایمنی. مسیر را لازم نیست ادامه بدهی.');
        }
        redirect($back);
    }

    if ($do === 'step') {
        $treeId = trim((string) ($_POST['tree_id'] ?? ''));
        $stepId = trim((string) ($_POST['step_id'] ?? ''));
        $payload = [
            'answers' => $_POST['answers'] ?? [],
            'checkin' => $_POST['checkin'] ?? '',
            'note' => trim(implode("\n", array_filter([
                (string) ($_POST['note_sit'] ?? ''),
                (string) ($_POST['note_thought'] ?? ''),
                (string) ($_POST['note'] ?? ''),
            ]))),
        ];
        $res = mana_path_complete_step($pdo, $profile, $treeId, $stepId, $payload);
        $fresh = mana_path_load_profile($pdo, $patientId);
        if (!empty($fresh['crisis_flag']) || !empty($res['extra']['crisis'])) {
            flash_set('error', 'اگر فکر آسیب به خود داری، اول کمک فوری بگیر. این نتیجه تشخیص بیماری نیست.');
            redirect($back);
        }
        if (!empty($res['already'])) {
            flash_set('success', 'این مرحله قبلاً انجام شده.');
        } elseif (!empty($res['extra']['band'])) {
            flash_set('success', $res['extra']['band'] . ' — ' . ($res['extra']['path'] ?? 'مسیر پیشنهادی آماده است.'));
        } elseif (!empty($res['extra']['suggest_clinic'])) {
            flash_set('success', 'ثبت شد. اگر این موضوع هنوز سنگین است، می‌توانی با درمانگر حرف بزنی.');
        } else {
            $xp = (int) ($res['xp'] ?? 0);
            flash_set('success', $xp > 0 ? ('انجام شد. انرژی ذهن +' . $xp) : 'ثبت شد.');
        }
        redirect($back);
    }

    flash_set('error', 'درخواست نامعتبر بود.');
} catch (Throwable $e) {
    flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'انجام نشد.');
}

redirect($back);
