const fs = require('fs');
const file = 'cpanel/pages/staff_app.php';
let s = fs.readFileSync(file, 'utf8');
const start = s.indexOf("if ($path === '/app' && staff_app_limited_doctor($user)) {");
const end = s.indexOf("if ($path === '/app/profile'", start);
if (start < 0 || end < 0) throw Error('Separate home not found');
s = s.slice(0,start) + s.slice(end);
const homeStart = s.indexOf("if ($section === 'home') {");
const homeEnd = s.indexOf("if ($section === 'therapists')",homeStart);
let home = s.slice(homeStart,homeEnd);
const oldSetup = home.slice(home.indexOf("    if (!function_exists('collaboration_request_new_count'))"), home.indexOf('    ob_start();'));
const setup = `    $consultOpen = 0;
    if (staff_app_home_tile_allowed($user, '/app/consult')) {
        if (!function_exists('collaboration_request_new_count')) {
            require_once __DIR__ . '/../includes/collaboration.php';
        }
        $consultOpen = (function_exists('consult_request_new_count') ? consult_request_new_count() : 0)
            + (function_exists('collaboration_request_new_count') ? collaboration_request_new_count() : 0);
    }
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    [, $helloJm, $helloJd] = gregorian_to_jalali((int) $today->format('Y'), (int) $today->format('n'), (int) $today->format('j'));
    $helloJalali = to_fa_digits((string) $helloJd) . ' ' . (jalali_month_names()[$helloJm] ?? '');
    $helloGregorian = $today->format('j F');
`;
home = home.replace(oldSetup, setup);
home = home.replace('<span>داشبورد مدیریتی</span>', "<span><?= staff_app_limited_doctor($user) ? 'برنامه درمانگر' : 'داشبورد مدیریتی' ?></span>");
home = home.replace('<strong>وقت‌ها</strong>', "<strong><?= staff_app_limited_doctor($user) ? 'وقت‌های من' : 'وقت‌ها' ?></strong>");
let count = 0;
home = home.replace(/      <a class="sapp-tile" href="<\?= e\(url\('([^']+)'\)\) \?>">[\s\S]*?      <\/a>/g, (block,route) => {
  count++;
  return `      <?php if (staff_app_home_tile_allowed($user, '${route}')): ?>\n${block}\n      <?php endif; ?>`;
});
if (count !== 9) throw Error('Expected nine shared cards, got ' + count);
s = s.slice(0,homeStart) + home + s.slice(homeEnd);
fs.writeFileSync(file,s);
console.log('Removed separate home; nine cards now use shared permissions and layout.');
