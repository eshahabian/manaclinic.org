const fs=require('fs');const f='cpanel/includes/staff_app.php';let s=fs.readFileSync(f,'utf8');const a=s.indexOf('function staff_app_full_access('),b=s.indexOf('function staff_app_allowed(',a);s=s.slice(0,a)+`function staff_app_full_access(array $user): bool
{
    require_once __DIR__ . '/doctor_profile_fields.php';
    return ($user['role'] ?? '') === 'SECRETARY' || clinic_user_is_access_exception($user);
}

`+s.slice(b);fs.writeFileSync(f,s);
