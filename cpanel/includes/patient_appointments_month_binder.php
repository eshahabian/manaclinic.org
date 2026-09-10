<?php
declare(strict_types=1);

/** @var array $ymdPack */
/** @var string $appointmentItemMode */

$ymdPack = is_array($ymdPack ?? null) ? $ymdPack : [];
$appointmentItemMode = $appointmentItemMode ?? 'simple';
$ymdEmpty = (string) ($ymdEmpty ?? 'نوبتی در این بخش نیست.');
$ymdNoun = 'نوبت';
$ymdAllPrefix = 'نوبت‌های';
$ymdShowPeople = false;
$ymdClass = 'patient-ymd';
$ymdRenderItems = static function (array $list, array $bucket = []) use ($appointmentItemMode): void {
    $appointmentList = $list;
    $appointmentItemMode = $appointmentItemMode;
    require __DIR__ . '/patient_appointment_items.php';
    $openSlots = is_array($bucket['open_slots'] ?? null) ? $bucket['open_slots'] : [];
    require __DIR__ . '/patient_open_slots.php';
};
require __DIR__ . '/appointment_ymd_binder.php';
