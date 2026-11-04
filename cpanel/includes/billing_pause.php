<?php
declare(strict_types=1);

/**
 * توقف موقت کیف پول مراجعه‌کننده و آپلود فیش.
 * برای فعال‌کردن دوباره، هر دو مقدار را false کنید. کد حذف نشده است.
 */
function patient_wallet_paused(): bool
{
    return true;
}

function receipt_upload_paused(): bool
{
    return true;
}
