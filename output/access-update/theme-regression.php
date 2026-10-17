<?php
require '/app/includes/helpers.php';
require '/app/includes/staff_app.php';
$base = '';
$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    $checks++;
}
function collaboration_request_new_count(): int { return 0; }
function consult_request_new_count(): int { return 0; }
function home_for(array $user): string {
    $section = 'home';
    $role = $user['role'];
    $name = $user['name'] ?? '';
    eval(file_get_contents('/app/home-fragment.php'));
    return $renderedHome;
}
$newDoctor = ['id'=>'new-doctor', 'role'=>'DOCTOR', 'username'=>'new-account-123', 'name'=>'درمانگر تازه'];
$newHome = home_for($newDoctor);
check(staff_app_allowed($newDoctor), 'New doctor automatically allowed');
check(substr_count($newHome, 'class="sapp-tile"') === 2, 'New doctor sees two shared tiles');
check(substr_count($newHome, '<svg') === 2, 'Both shared tiles have icons');
check(str_contains($newHome, 'sapp-hello-date'), 'New doctor has shared date');
check(str_contains($newHome, 'خوش آمدید، درمانگر تازه'), 'Shared greeting');
check(str_contains($newHome, 'وقت‌های من'), 'Own schedule label');
check(!str_contains($newHome, '/app/rooms'), 'No restricted card');
foreach (['/app/rooms', '/app/consult', '/app/hours', '/app/therapists', '/app/checklist', '/app/petty-cash'] as $route) {
    check(!staff_app_path_allowed($newDoctor, $route, 'GET'), 'Route denied: '.$route);
}
check(staff_app_path_allowed($newDoctor, '/app/profile', 'POST'), 'Own profile remains editable');
foreach ([
    ['role'=>'SECRETARY', 'name'=>'منشی'],
    ['role'=>'ADMIN', 'username'=>'eshahabian', 'name'=>'عماد شهابیان'],
    ['role'=>'DOCTOR', 'username'=>'shgeranmaye', 'name'=>'شیوا گرانمایه پور'],
    ['role'=>'DOCTOR', 'name'=>'عطیه گارسچی'],
] as $user) {
    $home = home_for($user);
    check(str_contains($home, 'sapp-hello-date'), 'Existing account date retained');
    check(str_contains($home, '/app/rooms'), 'Existing access retained');
    check(substr_count($home, 'class="sapp-tile"') === (($user['role']==='SECRETARY') ? 9 : (($user['role']==='DOCTOR') ? 7 : 6)), 'Existing tile count retained');
}
class PhotoStatement extends PDOStatement {
    public function __construct(private mixed $photo) {}
    public function execute(?array $params = null): bool { $GLOBALS['photo_owner'] = $params[0] ?? ''; return true; }
    public function fetchColumn(int $column = 0): mixed { return $this->photo; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return ['Field'=>'avatar_url']; }
}
class PhotoDatabase extends PDO {
    public function __construct(public string $doctorPhoto, public string $userPhoto) {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new PhotoStatement(str_contains($query, 'doctor_profiles') ? $this->doctorPhoto : $this->userPhoto); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new PhotoStatement(''); }
}
check(staff_app_avatar_src(new PhotoDatabase('/uploads/doctors/own.jpg','/uploads/avatars/old.jpg'), $newDoctor) === '/uploads/doctors/own.jpg', 'Professional photo takes priority');
check($GLOBALS['photo_owner'] === 'new-doctor', 'Photo belongs to current doctor');
check(staff_app_avatar_src(new PhotoDatabase('','/uploads/avatars/old.jpg'), $newDoctor) === '/uploads/avatars/old.jpg', 'Account photo fallback');
check(staff_app_avatar_src(new PhotoDatabase('',''), $newDoctor) === '', 'No photo keeps initial fallback');
check(staff_app_avatar_src(new PhotoDatabase('/uploads/doctors/other.jpg','/uploads/avatars/secretary.jpg'), ['role'=>'SECRETARY','id'=>'secretary']) === '/uploads/avatars/secretary.jpg', 'Secretary avatar unchanged');
echo 'PASS '.$checks.' shared-theme, new-doctor access, date and avatar checks';
