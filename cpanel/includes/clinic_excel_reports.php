<?php
declare(strict_types=1);

require_once __DIR__ . '/petty_cash.php';
require_once __DIR__ . '/appointment_payment.php';

function clinic_excel_reports_allowed(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $role = (string) ($user['role'] ?? '');
    $username = strtolower(trim((string) ($user['username'] ?? '')));
    if ($role === 'SECRETARY' || $username === 'eshahabian') {
        return true;
    }
    // Atieh's account ID is recorded in the clinic's verified account audit.
    return $role === 'DOCTOR' && (
        $username === 'shgeranmaye' || ($user['id'] ?? '') === '36dfdb042124cb0a89814c30'
    );
}

function clinic_excel_report_date(string $value): ?string
{
    if (!preg_match('~^(\d{4})/(\d{1,2})/(\d{1,2})$~D', normalize_input($value), $parts)) {
        return null;
    }
    return petty_cash_parse_parts((int) $parts[1], (int) $parts[2], (int) $parts[3]);
}

function clinic_excel_cash_rows(array $report): Generator
{
    yield ['تاریخ شمسی', 'نوع', 'عنوان', 'مبلغ (تومان)', 'حساب', 'تأییدکننده', 'ثبت‌کننده', 'توضیحات'];
    foreach ($report['entries'] as $entry) {
        yield [
            to_jalali_label($entry['entry_date']),
            $entry['kind'] === 'IN' ? 'ورودی' : 'هزینه',
            $entry['item_name'],
            (int) $entry['amount'],
            petty_cash_entry_account_label($entry['kind'], (string) $entry['pay_account']),
            petty_cash_option_label(petty_cash_approvers(), (string) $entry['approver']),
            $entry['created_by_name'],
            $entry['note'],
        ];
    }
    yield [];
    yield ['جمع ورودی', '', '', $report['in']];
    yield ['جمع هزینه', '', '', $report['out']];
    yield ['خالص بازه', '', '', $report['in'] - $report['out']];
}

function clinic_excel_appointment_rows(PDO $pdo, string $type, string $id, ?string $start, ?string $end): Generator
{
    $forDoctor = $type === 'doctor';
    $condition = $forDoctor ? 'a.doctor_id = ?' : 'a.patient_id = ?';
    $params = [$id];
    if ($start !== null && $end !== null) {
        $condition .= ' AND a.starts_at >= ? AND a.starts_at < ?';
        $until = (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
        array_push($params, $start . ' 00:00:00', $until . ' 00:00:00');
    }
    $stmt = $pdo->prepare("
        SELECT a.*, pu.name AS patient_name, du.name AS doctor_name,
               p.status AS pay_status, p.amount, p.amount_usd, p.ref_id, p.receipt_path
        FROM appointments a
        JOIN users pu ON pu.id = a.patient_id
        JOIN doctor_profiles dp ON dp.id = a.doctor_id
        JOIN users du ON du.id = dp.user_id
        LEFT JOIN payments p ON p.appointment_id = a.id
        WHERE {$condition}
        ORDER BY a.starts_at, a.id
    ");
    $stmt->execute($params);
    $head = ['تاریخ شمسی', 'ساعت', 'مراجعه‌کننده', 'درمانگر', 'وضعیت نوبت', 'پرداخت تأیید شده؟', 'وضعیت پرداخت'];
    if ($forDoctor) {
        array_push($head, 'مبلغ ثبت‌شده (تومان)', 'مبلغ ثبت‌شده (دلار)');
    }
    yield $head;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $values = [
            to_jalali_label(substr($row['starts_at'], 0, 10)),
            substr($row['starts_at'], 11, 5),
            $row['patient_name'],
            $row['doctor_name'],
            appointment_row_status_label($row),
            $row['pay_status'] === 'PAID' ? 'بله' : 'خیر',
            appointment_pay_status_display($row),
        ];
        if ($forDoctor) {
            $usd = $row['amount_usd'];
            $toman = $row['amount'];
            $values[] = $usd === null && $toman !== null ? (int) $toman : null;
            $values[] = $usd !== null ? (float) $usd : null;
        }
        yield $values;
    }
}

function clinic_excel_send(string $filename, iterable $rows): void
{
    $path = tempnam(sys_get_temp_dir(), 'mana-xlsx-');
    if ($path === false) {
        throw new RuntimeException('Could not create the workbook.');
    }

    try {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the workbook.');
        }
        $spreadsheet = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $documentRels = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $packageRels = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $sheet = '<worksheet xmlns="' . $spreadsheet . '">'
            . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews>'
            . '<cols><col min="1" max="9" width="26" customWidth="1"/></cols><sheetData>';
        foreach ($rows as $row) {
            $sheet .= '<row>';
            foreach ($row as $value) {
                if (is_int($value) || is_float($value)) {
                    $sheet .= '<c><v>' . json_encode($value, JSON_THROW_ON_ERROR) . '</v></c>';
                } else {
                    // Inline strings keep names and notes literal, including a leading "=".
                    $sheet .= '<c t="inlineStr"><is><t xml:space="preserve">'
                        . htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8')
                        . '</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $parts = [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<Relationships xmlns="' . $packageRels . '"><Relationship Id="rId1" Type="'
                . $documentRels . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<workbook xmlns="' . $spreadsheet . '" xmlns:r="' . $documentRels . '">'
                . '<bookViews><workbookView/></bookViews>'
                . '<sheets><sheet name="گزارش" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="' . $packageRels . '"><Relationship Id="rId1" Type="'
                . $documentRels . '/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet . '</sheetData></worksheet>',
        ];
        foreach ($parts as $name => $xml) {
            if (!$zip->addFromString($name, '<?xml version="1.0" encoding="UTF-8"?>' . $xml)) {
                throw new RuntimeException('Could not write the workbook.');
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('Could not save the workbook.');
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    } finally {
        unlink($path);
    }
}
