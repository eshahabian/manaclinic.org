from pathlib import Path
root=Path('cpanel')
files=['includes/sms_service.php','actions/admin_manage.php','pages/admin/sms_notify.php','includes/admin_panel.php','includes/doctor_panel.php','actions/login_otp_request.php']
for f in files:
 p=Path('output/sms-controls/baseline')/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((root/f).read_bytes())
p=root/'includes/sms_service.php';s=p.read_text(encoding='utf-8-sig')
a=s.index('    $isAppointment =');b=s.index('    $map=[];',a)
s=s[:a]+s[b:]
s=s.replace("// مراجع — این قالب‌ها را تغییر ندهید مگر با تأیید صریح (مسیر پایدار وب‌وان).","// قالب‌های قابل مدیریت در پنل مدیر.")
s=s.replace("'(متن پویا)'","'{message}'")
s=s.replace("'مانا کلینیک: جلسه برگزار شد. برداشت خود را در پنل مانا بنویسید.'","'{message}'").replace("'مانا کلینیک: فایل جدیدی به کارگاه اضافه شد.'","'{message}'")
s=s.replace("// خلاصه صبحگاهی (متن پویا از sms_doctor_daily_digest_body؛ این فقط برچسب ادمین است)","// قالب خلاصه صبحگاهی")
s=s.replace("        'quiet'=>", "        'therapist_copy'=>['کپی پیام درمانگر برای عماد','{message}'],\n        'quiet'=>")
insert=r'''
/** Defaults preserve the existing sending policy until the manager changes a switch. */
function sms_kind_enabled(PDO $pdo, string $kind): bool
{
    if (!isset(sms_template_catalog()[$kind])) return true;
    if (in_array($kind, ['quiet','workshop_announcement'], true)) {
        $legacy = $kind === 'quiet' ? 'quiet' : 'workshop';
        $default = mail_setting_get($pdo, 'sms_notify_'.$legacy, '0');
    } else $default = '1';
    return mail_setting_get($pdo, 'sms_kind_enabled_'.$kind, $default) === '1';
}

function sms_template_tokens(string $kind): array
{
    $item = sms_template_catalog()[$kind] ?? null;
    if (!$item) return [];
    preg_match_all('/\{[a-z_]+\}/', $item[1], $matches);
    return array_values(array_unique($matches[0]));
}

function sms_template_error(string $kind, string $text): string
{
    if (!isset(sms_template_catalog()[$kind])) return 'نوع پیامک نامعتبر است.';
    if (mb_strlen($text) > 500) return 'متن حداکثر ۵۰۰ نویسه باشد.';
    preg_match_all('/\{[^{}]*\}/u', $text, $used);
    if (array_diff($used[0], sms_template_tokens($kind)) || preg_match('/[{}]/', preg_replace('/\{[a-z_]+\}/', '', $text))) return 'فقط متغیرهای راهنما مجازند.';
    if ($kind === 'login_otp' && $text !== '' && !str_contains($text, '{code}')) return 'متن کد ورود باید {code} داشته باشد.';
    return '';
}

function sms_kind_recipient(string $kind): string
{
    if (str_starts_with($kind, 'appointment_')) return 'مراجع همان نوبت؛ شماره موبایل حسابی که نوبت برای او ثبت شده است';
    if (str_starts_with($kind, 'doctor_appointment_')) return 'درمانگر انتخاب‌شده برای همان نوبت';
    return [
        'doctor_daily_digest'=>'هر درمانگر دارای نوبت تأییدشده در همان روز',
        'therapist_copy'=>'شخص مشخص: عماد شهابیان (حساب eshahabian)؛ کپی عین پیام درمانگر',
        'workshop_registered'=>'مراجع ثبت‌نام‌شده در همان کارگاه',
        'workshop_reminder'=>'اعضای تأییدشده همان کارگاه',
        'workshop_announcement'=>'مراجعان قدیمی با انتخاب اطلاع‌رسانی هنگام ساخت کارگاه',
        'workshop_session_reflect'=>'اعضای تأییدشده کارگاه پس از پایان جلسه',
        'workshop_media_added'=>'اعضای تأییدشده همان کارگاه پس از افزودن فایل',
        'workshop_presenter'=>'درمانگرانی که به‌عنوان اجراکننده همان کارگاه انتخاب شده‌اند',
        'workshop_presenter_copy'=>'شخص مشخص: عماد شهابیان (حساب eshahabian)؛ نمونه پیام اجراکننده',
        'quiet'=>'درمانگر مربوط، منشی‌ها و مدیر برای پیگیری مراجع بدون مراجعه',
        'login_otp'=>'صاحب حساب متناظر با شماره درخواست‌کننده ورود پیامکی',
    ][$kind] ?? '';
}

function sms_template_sample_variables(): array
{
    return ['datetime'=>'۱۴۰۵/۰۷/۲۹ ۱۳:۰۰','patient'=>'مراجع نمونه','date'=>'۲۹ مهر','slots'=>'۱۰:۰۰، ۱۳:۰۰','code'=>'123456','message'=>'مانا کلینیک: این یک پیام نمونه برای بررسی متن است.'];
}

/** Dynamic producers keep their original body by default; an override wraps {message}. */
function sms_dynamic_body(PDO $pdo, string $kind, string $body): string
{
    if (!in_array($kind, ['workshop_presenter','workshop_presenter_copy','workshop_session_reflect','workshop_media_added'], true)) return $body;
    if (trim(mail_setting_get($pdo, 'sms_template_'.$kind, '')) === '') return $body;
    return sms_template_render($pdo, $kind, ['message'=>$body]);
}
'''
pos=s.index('function sms_first_name');s=s[:pos]+insert+'\n'+s[pos:]
# render accepts an optional draft for preview and test without saving
s=s.replace('string $kind, array $variables): string','string $kind, array $variables, ?string $draft = null): string',1)
s=s.replace("$template=mail_setting_get($pdo,'sms_template_'.$kind,'');","$template=$draft ?? mail_setting_get($pdo,'sms_template_'.$kind,'');",1)
# guards at both enqueue and immediate; dispatch checks flags for already pending messages
pos=s.index('    $mobile = sms_iran_mobile($phone);',s.index('function sms_send_immediate'))
s=s[:pos]+"    if (!sms_connection_ready($pdo) || !sms_kind_enabled($pdo, $kind)) return ['ok'=>false,'status'=>'SKIPPED','error'=>'ارسال این نوع پیامک یا اتصال پیامک غیرفعال است.'];\n    $body = sms_dynamic_body($pdo, $kind, $body);\n"+s[pos:]
# avoid rendering dynamic twice at queue
s=s.replace("    sms_queue_extended(\n        $pdo,", "    $context['body_prepared'] = 1;\n    sms_queue_extended(\n        $pdo,",1)
pos=s.index('    ensure_outreach_schema($pdo);',s.index('function sms_queue_extended'))
s=s[:pos]+"    if (!sms_kind_enabled($pdo, $kind)) return false;\n    if (empty($context['body_prepared'])) $body = sms_dynamic_body($pdo, $kind, $body);\n"+s[pos:]
s=s.replace("    if (!empty($job['expires_at'])", "    if (!sms_kind_enabled($pdo, (string) $job['kind'])) return false;\n    if (!empty($job['expires_at'])",1)
# custom digest uses the actual date and slots
s=s.replace("    $prefix = 'مانا کلینیک: نوبت ' . $dayLabel . ': ';", "    global $pdo;\n    if ($pdo instanceof PDO && trim(mail_setting_get($pdo, 'sms_template_doctor_daily_digest', '')) !== '') {\n        return sms_template_render($pdo, 'doctor_daily_digest', ['date'=>$dayLabel,'slots'=>implode('، ', $slots)]);\n    }\n    $prefix = 'مانا کلینیک: نوبت ' . $dayLabel . ': ';")
p.write_text(s,encoding='utf-8')
p=root/'actions/login_otp_request.php';s=p.read_text(encoding='utf-8-sig').replace('if(!sms_connection_ready($pdo)){',"if(!sms_connection_ready($pdo) || !sms_kind_enabled($pdo, 'login_otp')){");p.write_text(s,encoding='utf-8')
# manager-only changes and targeted test
p=root/'actions/admin_manage.php';s=p.read_text(encoding='utf-8-sig');s=s.replace("$action==='sms_doctor_digest_manual'", "$action==='sms_doctor_digest_manual' || in_array($action,['save_sms_template','test_sms_template','save_sms_notify'],true)",1)
a=s.index("if ($action === 'save_sms_notify')");b=s.index("if ($action === 'sms_broadcast')",a)
s=s[:a]+r'''
if (in_array($action, ['save_sms_template','test_sms_template'], true)) {
    $kind = post('kind');
    $text = trim(post('template'));
    $back = '/admin/sms-notify';
    $error = sms_template_error($kind, $text);
    if ($kind === 'therapist_copy' && $text !== '' && $text !== '{message}') $error = 'کپی درمانگر عین پیام اصلی است؛ متن را در پیام اصلی ویرایش کنید.';
    if ($error !== '') { flash_set('error', $error); redirect($back); }
    if ($action === 'save_sms_template') {
        $pdo->beginTransaction();
        try {
            mail_setting_set($pdo, 'sms_template_'.$kind, $text);
            mail_setting_set($pdo, 'sms_kind_enabled_'.$kind, isset($_POST['enabled']) ? '1' : '0');
            if (in_array($kind,['quiet','workshop_announcement'],true)) mail_setting_set($pdo,'sms_notify_'.($kind==='quiet'?'quiet':'workshop'),isset($_POST['enabled'])?'1':'0');
            // Disabled queued jobs must not be resurrected when the switch is enabled again.
            if (!isset($_POST['enabled'])) $pdo->prepare("UPDATE sms_outbox SET status='SKIPPED',last_error='این نوع پیامک توسط مدیر غیرفعال شد.' WHERE kind=? AND status='PENDING'")->execute([$kind]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        flash_set('success', 'متن و وضعیت پیامک ذخیره شد.');
    } else {
        $phone = sms_iran_mobile(post('phone'));
        if ($phone === null) { flash_set('error','شماره موبایل ایران را درست وارد کنید.'); redirect($back); }
        if (!sms_connection_ready($pdo)) { flash_set('error','اتصال پیامک غیرفعال است.'); redirect($back); }
        // One explicit test, using draft text and synthetic variables; no real appointment is changed.
        if (isset($_SESSION['sms_template_test_at']) && time() - (int)$_SESSION['sms_template_test_at'] < 15) { flash_set('error','برای تست بعدی ۱۵ ثانیه صبر کنید.'); redirect($back); }
        $_SESSION['sms_template_test_at'] = time();
        $body = sms_template_render($pdo, $kind, sms_template_sample_variables(), $text);
        $result = sms_send_immediate($pdo, $phone, $body, 'test', 'template-test:'.cuid(), null, ['entity'=>'template_test','template_kind'=>$kind]);
        flash_set(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok']) ? 'پیامک تست برای شماره انتخابی در سرویس پیامک پذیرفته شد؛ دریافت روی گوشی را بررسی کنید.' : ('ارسال تست انجام نشد: '.($result['error'] ?? sms_status_label($result['status']))));
    }
    redirect($back.'#sms-'.$kind);
}

if ($action === 'save_sms_notify') {
    flash_set('error', 'تنظیمات را از کارت هر پیامک ذخیره کنید.');
    redirect('/admin/sms-notify');
}

'''+s[b:];p.write_text(s,encoding='utf-8')
# navigation in desktop and responsive app menus
for f in ['includes/admin_panel.php','includes/doctor_panel.php']:
 p=root/f;s=p.read_text(encoding='utf-8-sig')
 if f.endswith('admin_panel.php'):
  s=s.replace("if (sms_settings_allowed($user)) {", "if (sms_settings_allowed($user)) {\n            $nav[]=['href'=>'/admin/sms-notify','label'=>'مدیریت پیامک‌ها'];",1)
  s=s.replace("'/admin/sms-settings', '/admin/sms-doctor-digest'", "'/admin/sms-settings', '/admin/sms-notify', '/admin/sms-doctor-digest'")
 else:
  s=s.replace("if (sms_settings_allowed(current_user())) $tail[]", "if (sms_settings_allowed(current_user())) $tail[]=['type'=>'link','href'=>'/admin/sms-notify','label'=>'مدیریت پیامک‌ها'];\n        if (sms_settings_allowed(current_user())) $tail[]",1)
 p.write_text(s,encoding='utf-8')
