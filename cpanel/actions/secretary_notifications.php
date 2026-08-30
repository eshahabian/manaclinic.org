<?php
declare(strict_types=1);
require_login(['SECRETARY']);
mark_notifications_read($pdo, (string) current_user()['id']);
flash_set('success', 'پیام‌ها خوانده شدند.');
redirect('/secretary');
