const fs=require('fs');const f='cpanel/includes/outreach.php';let s=fs.readFileSync(f,'utf8');const a=s.indexOf('function sms_queue(PDO'),b=s.indexOf('function outreach_queue_message(',a);s=s.slice(0,a)+`function sms_queue(PDO $pdo, string $phone, string $body, string $kind = 'manual'): bool
{
    return sms_queue_extended($pdo,$phone,$body,$kind);
}

function sms_queue_one(PDO $pdo, string $phone, string $body, string $kind = 'manual'): bool
{
    return sms_queue($pdo,$phone,$body,$kind);
}

`+s.slice(b);s=s.replace("        sms_queue($pdo, $phone, $body, $kind);\r\n        $count++;", "        if (sms_queue($pdo, $phone, $body, $kind)) $count++;").replace("        sms_queue($pdo, $phone, $body, 'broadcast');\r\n        $count++;", "        if (sms_queue($pdo, $phone, $body, 'broadcast')) $count++;");fs.writeFileSync(f,s);
for(const name of ['sms_test','sms_broadcast','sms_notify']){const file='cpanel/pages/admin/'+name+'.php';let p=fs.readFileSync(file,'utf8').replace('require_site_admin();','require_sms_operator();').replace('render_admin_page(', 'sms_render_page(');fs.writeFileSync(file,p);}
