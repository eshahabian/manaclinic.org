<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);

flash_set('success', 'دوره‌ها و کارگاه‌های جدید در بخش خدمات اعلام می‌شوند. از پیام‌ها هم لینک ثبت‌نام برایتان می‌آید.');
redirect('/services');
