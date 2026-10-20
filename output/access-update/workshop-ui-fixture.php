<?php
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function url($v){return $v;}
function current_user(){return ['id'=>'demo-admin','role'=>'ADMIN','username'=>'eshahabian'];}
function csrf_field(){return '<input type="hidden" name="_csrf" value="test">';}
function workshop_can_add_member($u){return true;}
function workshop_member_user_sql($a){return '1=1';}
function workshop_is_archived($w){return false;}
function workshop_type_label($t){return 'حضوری';}
function workshop_session_interval_label($v){return 'هفتگی';}
function staff_sign_html($v){return '';}
function workshop_media_counts_from_row($w){return [];}
function workshop_media_counts_html($v){return '';}
function format_workshop_datetime_fa($v){return 'چهارشنبه، ساعت ۱۸:۰۰';}
function format_price($v){return '—';}
function workshop_closed_at($v){return null;}
function workshop_close_action_label($v){return 'اتمام کارگاه';}
function workshop_session_file_lines_html(...$args){return '<h3>فایل‌های هر جلسه</h3><p>جلسه اول</p>';}
class UiDb extends PDO {function __construct(){} public function query(string $q,?int $mode=null,mixed ...$args):PDOStatement|false{return new UiStmt();}}
class UiStmt extends PDOStatement {public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return [['id'=>'demo-admin','name'=>'عماد شهابیان','username'=>'eshahabian']];}}
$pdo=new UiDb();$workshopRole='doctor';$workshopEnrollmentsById=['demo'=>[['id'=>'demo-enrollment','patient_name'=>'عضو نمونه','status'=>'CONFIRMED']]];$workshopList=[['id'=>'demo','title'=>'زوج‌درمانی','type'=>'IN_PERSON','session_interval'=>'WEEKLY','starts_at'=>'2027-01-01 18:00:00','ends_at'=>'2027-03-01 18:00:00','price'=>0,'enrolled_count'=>0,'capacity'=>null,'is_published'=>1,'enrollment_open'=>1,'status'=>'PUBLISHED']];
?><!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>بررسی چیدمان کارگاه</title><link rel="stylesheet" href="style.css"><body><main style="max-width:1136px;margin:24px auto;padding:16px"><h1>کارگاه‌ها و دوره‌ها</h1><?php require dirname(__DIR__).'/includes/workshop_manage_cards.php'; ?></main></body></html>
