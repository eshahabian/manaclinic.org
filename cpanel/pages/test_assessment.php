<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/psych_assessments.php';
if (!psych_assessment_enabled($slug)) { http_response_code(404); exit; }
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
$spec=psych_assessment_specs()[$slug]??null;
if(!$spec){http_response_code(404);exit;}
$form=psych_assessment_form($slug);
$result=null;$error='';$values=[];
$action=(string)($_POST['assessment_action']??'');
$isPost=($_SERVER['REQUEST_METHOD']??'GET')==='POST';
$isAi=$isPost && $action==='ai';
if($isAi && !psych_assessment_ai_enabled($slug)){
    http_response_code(404);header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>'تحلیل هوش مصنوعی این آزمون فعال نشده است.'],JSON_UNESCAPED_UNICODE);exit;
}
$clinicalOperator=in_array(strtoupper((string)(current_user()['role']??'')),['ADMIN','DOCTOR'],true);
$context=['version'=>$spec['version'],'official_report'=>($_POST['official_report']??'')==='1',
    'clinical_operator'=>$clinicalOperator,'validity'=>(string)($_POST['validity']??'')];
$provider=(string)(parse_url(assistant_ai_config()['base_url'],PHP_URL_HOST)?:'سرویس هوش مصنوعی کلینیک');
if($isPost){
 try{
    if($spec['mode']==='clinical' && !$clinicalOperator){
        http_response_code(403);
        if($isAi){header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>'این بخش فقط برای درمانگر یا مدیر است.'],JSON_UNESCAPED_UNICODE);exit;}
        throw new InvalidArgumentException('این بخش فقط برای درمانگر یا مدیر است.');
    }
    if(($_POST['adult']??'')!=='1')throw new InvalidArgumentException('این بخش برای بزرگسالان است؛ افراد زیر ۱۸ سال با متخصص اقدام کنند.');
    if(($_POST['version']??'')!==$spec['version'])throw new InvalidArgumentException('نسخه گزارش یا پاسخنامه را بررسی کنید.');
    if(!$form && ($_POST['existing_form']??'')!=='1')throw new InvalidArgumentException('فقط امتیاز پاسخنامه یا گزارش تکمیل‌شده همان نسخه را وارد کنید.');
    if(!is_array($_POST['values']??null))throw new InvalidArgumentException('امتیازها کامل نیستند.');
    $values=$_POST['values'];$result=psych_assessment_score($slug,$values,$context);
    if($isAi){
      header('Content-Type: application/json; charset=utf-8');
      if(!current_user()){http_response_code(401);echo json_encode(['error'=>'برای تحلیل هوش مصنوعی وارد حساب شوید.'],JSON_UNESCAPED_UNICODE);exit;}
      if(($_POST['ai_consent']??'')!=='1')throw new InvalidArgumentException('رضایت برای ارسال امتیازها به سرویس هوش مصنوعی لازم است.');
      if($result['safety_followup']){echo json_encode(['safety'=>psych_safety_message()],JSON_UNESCAPED_UNICODE);exit;}
      if(!$result['ai_allowed']){http_response_code(422);echo json_encode(['error'=>'اعتبار گزارش اجازه تحلیل خودکار نمی‌دهد؛ متخصص باید آن را بررسی کند.'],JSON_UNESCAPED_UNICODE);exit;}
      if(!assistant_enabled()||!assistant_ai_available()){http_response_code(503);echo json_encode(['error'=>'هوش مصنوعی فعلاً در دسترس نیست. نمودار و امتیازها مستقل از آن آماده‌اند.'],JSON_UNESCAPED_UNICODE);exit;}
      $bucket='psych_ai_'.(string)current_user()['id'];
      throttle_guard_json($bucket,5,3600,'درخواست‌های تحلیل این ساعت زیاد است؛ کمی بعد دوباره تلاش کنید.');
      throttle_guard_json('psych_ai',12,3600,'درخواست‌های تحلیل زیاد است؛ کمی بعد دوباره تلاش کنید.');
      throttle_hit($bucket,3600);throttle_hit('psych_ai',3600);
      try{
        $analysis=bdi_parse_ai(assistant_ai_chat(psych_assessment_ai_messages($result),1100));
        echo json_encode(['analysis'=>$analysis],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
      }catch(Throwable $ignored){http_response_code(503);echo json_encode(['error'=>'تحلیل تکمیل نشد؛ امتیاز و نمودار محفوظ‌اند. کمی بعد دوباره امتحان کنید.'],JSON_UNESCAPED_UNICODE);}
      exit;
    }
 }catch(InvalidArgumentException $ex){
    $error=$ex->getMessage();http_response_code(422);
    if($isAi){header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$error],JSON_UNESCAPED_UNICODE);exit;}
 }
}
$pageHead=($pageHead??'').'<link rel="stylesheet" href="'.e(url('/assets/css/bdi.css?v=3')).'"><link rel="stylesheet" href="'.e(url('/assets/css/psych-tests.css?v=1')).'">';
$pageScripts=($pageScripts??'').'<script src="'.e(url('/assets/js/bdi.js?v=3')).'" defer></script>';
$fields=$spec['mode']==='items'?array_combine(range(1,$spec['count']),array_map(static fn($i)=>'سؤال '.to_fa_digits((string)$i),range(1,$spec['count']))):psych_profile_labels($slug);
$inputMin=$spec['mode']==='items'?$spec['min']:0;
$inputMax=$spec['mode']==='items'?$spec['max']:($slug==='neo'?48:120);
// Keep integer zeros and integer multiples of ten intact when formatting.
$number=static function($v):string{if($v===null)return 'تعریف‌نشده';$s=is_int($v)?(string)$v:rtrim(rtrim(number_format((float)$v,4,'.',''),'0'),'.');return to_fa_digits($s);};
ob_start();
?>
<section class="bdi-room container-page section" aria-label="<?= e($test['title']) ?>">
 <a class="bdi-back" href="<?= e(url('/tests')) ?>">← آزمون‌های روان‌شناسی</a>
 <section class="bdi-hero"><div><span class="bdi-tag">خودشناسی با مانا · <?= e($test['abbr']) ?></span><h1><?= e($test['title']) ?></h1><p><?= e($test['description']) ?> نتیجه برای شناخت بهتر است و جایگزین ارزیابی متخصص نیست.</p><a class="btn btn-primary" href="#<?= $result?'assessment-result':'assessment-form' ?>"><?= $result?'گزارش من':($form?'شروع پرسشنامه':'تحلیل پاسخنامه موجود') ?></a></div><div class="bdi-hero-art" aria-hidden="true"><span>✦</span><div>شناخت بیشتر<br><strong>یک قدم با خودت</strong></div><span>☾</span></div></section>
 <div class="bdi-meta"><div><small>نسخه امتیازدهی</small><strong><?= e($spec['version']) ?></strong></div><div><small>بازه بررسی</small><strong><?= e($spec['period']) ?></strong></div><div><small>وضعیت سؤال‌ها</small><strong><?= $form?'نسخه مجاز متصل است':'در انتظار مجوز و متن معتبر' ?></strong></div><div><small>گزارش</small><strong><?= psych_assessment_ai_enabled($slug)?'نمودار و تحلیل اختیاری':'نمودار نتیجه' ?></strong></div></div>
 <?php if($result): ?>
 <section id="assessment-result" aria-labelledby="assessment-report-title">
  <div class="bdi-section-title"><div><span class="bdi-tag">گزارش همین پاسخنامه</span><h2 id="assessment-report-title">تصویری روشن‌تر از پاسخ‌ها</h2></div><button type="button" class="btn btn-outline" data-bdi-print>چاپ گزارش</button></div>
  <?php if($result['safety_followup']): ?><div class="bdi-safety" role="alert"><?= e(psych_safety_message()) ?></div><?php endif; ?>
  <?php if(!$result['ai_allowed']): ?><div class="bdi-safety" role="alert">وضعیت اعتبار: <?= e($result['label']) ?>. نمودار فقط نمره‌های واردشده را نشان می‌دهد؛ تحلیل شخصیتی ارائه نمی‌شود.</div><?php endif; ?>
  <div class="bdi-report-grid">
   <article class="bdi-card bdi-score-card"><h3><?= $result['total']!==null?'امتیاز محاسبه‌شده':'پروفایل مستقل مقیاس‌ها' ?></h3>
   <?php if($result['total']!==null): $dash=round($result['total']/$result['total_max']*440,2); ?>
    <svg class="bdi-donut" viewBox="0 0 180 180" role="img" aria-label="<?= e('امتیاز '.$result['total'].' از '.$result['total_max']) ?>"><circle cx="90" cy="90" r="70" fill="none" stroke="#efe7f5" stroke-width="13"/><circle cx="90" cy="90" r="70" fill="none" stroke="#9863b7" stroke-width="13" stroke-linecap="round" stroke-dasharray="<?= $dash ?> 440" transform="rotate(-90 90 90)"/><text x="90" y="90" text-anchor="middle" class="bdi-total"><?= e($number($result['total'])) ?></text><text x="90" y="118" text-anchor="middle" class="bdi-outof">از <?= e($number($result['total_max'])) ?> امتیاز</text></svg>
   <?php else: ?><div class="assessment-profile-number"><?= e($number(count($result['axes']))) ?><small>مقیاس مستقل</small></div><p class="bdi-note">برای این گزارش نمره کل ساختگی جمع نمی‌شود.</p><?php endif; ?>
    <strong class="bdi-band"><?= e($result['label']) ?></strong><p class="bdi-note">این تصویر درصد ابتلا، مقایسه با جمعیت ایران یا روند بهبود نیست. نمره پایین هم به‌تنهایی وجود مشکل را رد نمی‌کند.</p>
    <?php foreach($result['metrics'] as $metric): ?><div class="assessment-metric"><small><?= e($metric['label']) ?></small><strong><?= e($number($metric['value'])) ?><?= $metric['value']!==null?' / '.e($number($metric['max'])):'' ?></strong></div><?php endforeach; ?>
   </article>
   <article class="bdi-card"><h3><?= $result['axes']?'نقشه مقیاس‌های این گزارش':'نقشه امتیاز هر سؤال' ?></h3>
   <?php if($result['axes']):
     $axes=$result['axes'];$n=count($axes);$cx=260;$cy=220;$radius=140;
     $point=static function($i,$r)use($n,$cx,$cy):array{$angle=-M_PI/2+2*M_PI*$i/$n;return [$cx+cos($angle)*$r,$cy+sin($angle)*$r];};
     if($n>=3 && $n<=10): ?>
     <svg class="assessment-radar" viewBox="0 0 520 440" role="img" aria-label="نمودار شعاعی مقیاس‌ها؛ مقادیر دقیق در فهرست زیر آمده است">
      <?php for($level=1;$level<=4;$level++):$pts=[];for($i=0;$i<$n;$i++)$pts[]=implode(',',$point($i,$radius*$level/4)); ?><polygon points="<?= e(implode(' ',$pts)) ?>" fill="none" stroke="#e5d9ee"/><?php endfor; ?>
      <?php foreach($axes as $i=>$axis):[$x,$y]=$point($i,$radius);[$lx,$ly]=$point($i,$radius+28); ?><line x1="260" y1="220" x2="<?= $x ?>" y2="<?= $y ?>" stroke="#eee5f3"/><text x="<?= $lx ?>" y="<?= $ly ?>" text-anchor="middle" dominant-baseline="middle"><?= e(strtoupper($axis['key'])) ?></text><?php endforeach; ?>
      <?php $pts=[];foreach($axes as $i=>$axis)$pts[]=implode(',',$point($i,$radius*(float)$axis['value']/$axis['max'])); ?><polygon points="<?= e(implode(' ',$pts)) ?>" fill="#b189c633" stroke="#9461b0" stroke-width="3"/>
     </svg>
     <?php endif; ?>
     <div class="assessment-axis-list"><?php foreach($axes as $axis): ?><div class="assessment-axis"><div><strong><?= e(strtoupper($axis['key']).' · '.$axis['label']) ?></strong><span><?= e($number($axis['value']).' / '.$number($axis['max'])) ?></span></div><meter min="0" max="<?= $axis['max'] ?>" value="<?= $axis['value'] ?>"><?= e($number($axis['value'])) ?></meter><small><?= e($axis['band']) ?><?= isset($axis['raw'])?' · امتیاز خام: '.e($number($axis['raw'])):'' ?></small></div><?php endforeach; ?></div>
     <p class="bdi-note">طول هر محور نسبت به دامنه همان مقیاس است؛ درصد بیماری یا صدک جمعیتی نیست. مقیاس‌های نام‌گذاری‌شده بیماری را تشخیص نمی‌دهند.</p>
   <?php else: $chart=$result['processed_answers']??$result['answers']; ?>
     <div class="bdi-bars" role="list" aria-label="امتیاز هر سؤال پس از اعمال کلید"><?php foreach($chart as $id=>$score): ?><div class="bdi-bar" role="listitem" tabindex="0" aria-label="<?= e('سؤال '.$id.': '.$score) ?>"><span><?= e($number($score)) ?></span><div class="bdi-bar-track"><i style="height:<?= $score/$spec['max']*100 ?>%"></i></div><small><?= e($number($id)) ?></small></div><?php endforeach; ?></div><p class="bdi-note">نمره هر سؤال پس از اعمال کلید، شامل معکوس‌سازی لازم.</p>
   <?php endif; ?>
   </article>
  </div>
  <?php if(psych_assessment_ai_enabled($slug)): ?>
  <article class="bdi-card bdi-ai"><span class="bdi-tag">✦ همراه هوشمند مانا</span><h3>توضیحی برای قدم بعدی</h3><p>محاسبه و نمودار مستقل از هوش مصنوعی‌اند. توضیح تولیدشده ممکن است خطا داشته باشد و توسط متخصص بازبینی نشده است.</p>
   <?php if($result['safety_followup']): ?><p><?= e(psych_safety_message()) ?></p>
   <?php elseif(!$result['ai_allowed']): ?><p>تا روشن‌شدن اعتبار گزارش، تحلیل خودکار متوقف است.</p>
   <?php elseif(!current_user()): ?><p>برای درخواست تحلیل وارد حساب شوید؛ پس از ورود امتیازها را دوباره وارد کنید.</p><a class="btn btn-primary" href="<?= e(url('/login?next='.rawurlencode('/tests/'.$slug))) ?>">ورود برای تحلیل</a>
   <?php else: ?>
   <form method="post" action="<?= e(url('/tests/'.$slug)) ?>" data-bdi-ai><?= csrf_field() ?><input type="hidden" name="assessment_action" value="ai"><input type="hidden" name="version" value="<?= e($spec['version']) ?>"><input type="hidden" name="adult" value="1"><input type="hidden" name="existing_form" value="1"><input type="hidden" name="official_report" value="<?= $context['official_report']?'1':'0' ?>"><input type="hidden" name="validity" value="<?= e($context['validity']) ?>">
    <?php foreach($result['answers'] as $id=>$value): ?><input type="hidden" name="values[<?= e((string)$id) ?>]" value="<?= $value ?>"><?php endforeach; ?>
    <label class="bdi-check"><input type="checkbox" name="ai_consent" value="1" required> موافقم فقط نمره‌ها و مشخصات نسخه آزمون برای توضیح به سرویس هوش مصنوعی <?= e($provider) ?> ارسال شوند؛ نام، شماره تماس و پرونده ارسال نمی‌شود.</label><p class="bdi-note">این بخش پاسخ‌ها و تحلیل را در پرونده ذخیره نمی‌کند. پردازش داده‌های ارسالی در سرویس بیرونی تابع سیاست همان ارائه‌دهنده است.</p><button type="submit" class="btn btn-primary">دریافت تحلیل هوش مصنوعی</button>
   </form><div data-bdi-ai-status role="status" aria-live="polite"></div><div data-bdi-ai-output></div>
   <?php endif; ?>
  </article>
  <?php endif; ?>
  <article class="bdi-card"><h3>فراتر از امتیاز</h3><p>تفسیر به شرایط فرد، نحوه اجرا و هنجار مناسب وابسته است. اگر نشانه‌ها ادامه دارند یا زندگی روزمره را مختل کرده‌اند، برای ارزیابی دقیق‌تر با متخصص گفت‌وگو کنید.</p><a class="btn btn-outline" href="<?= e(url('/doctors')) ?>">گفت‌وگو با متخصص</a></article>
 </section>
 <?php endif; ?>
 <section class="bdi-card bdi-entry" id="assessment-form"><h2><?= $form?'پرسشنامه':($spec['mode']==='items'?'ورود امتیاز پاسخنامه تکمیل‌شده':'ورود نمره‌های گزارش رسمی') ?></h2>
 <p><?= e($spec['method']) ?></p>
 <?php if(!$form): ?><p class="bdi-note">هنوز سؤال‌های این آزمون منتشر نشده‌اند. فقط از پاسخنامه یا گزارش تکمیل‌شده نسخه مشخص‌شده استفاده کنید؛ بدون سؤال‌ها امتیازها را حدس نزنید.</p><?php else: ?><p><?= nl2br(e($form['instructions'])) ?></p><p class="bdi-note"><?= e($form['source']) ?></p><?php endif; ?>
 <?php if($error): ?><div class="bdi-safety" role="alert"><?= e($error) ?></div><?php endif; ?>
 <?php if($spec['mode']==='clinical' && !$clinicalOperator): ?><p>نمایش و تحلیل گزارش MMPI-2 مخصوص درمانگر یا مدیر واردشده است. نمره‌گذاری رسمی و بررسی اعتبار باید توسط متخصص انجام شود.</p><a class="btn btn-primary" href="<?= e(url('/login?next='.rawurlencode('/tests/'.$slug))) ?>">ورود متخصص</a>
 <?php else: ?>
 <form method="post" action="<?= e(url('/tests/'.$slug)) ?>" data-bdi-score data-psych-score data-safety-items="<?= e(json_encode($spec['safety_items']??[])) ?>" <?= $form?'data-bdi-questionnaire':'' ?>>
  <?= csrf_field() ?><input type="hidden" name="assessment_action" value="score"><input type="hidden" name="version" value="<?= e($spec['version']) ?>">
  <?php if($form): ?><div class="bdi-progress" data-bdi-progress hidden><div><span data-bdi-step-label></span><span data-bdi-answered></span></div><progress max="<?= $spec['count'] ?>" value="0" aria-label="پیشرفت پاسخ به پرسشنامه"></progress></div><div class="bdi-questions">
   <?php foreach($fields as $id=>$label):$selected=$values[$id]??''; ?><fieldset class="bdi-question" data-bdi-item="<?= $id ?>"><legend><?= e(to_fa_digits((string)$id).'. '.$form['items'][$id]['text']) ?></legend><?php foreach($form['items'][$id]['options'] as $option): ?><label class="bdi-option"><input type="radio" name="values[<?= $id ?>]" value="<?= $option['value'] ?>" data-score="<?= $option['value'] ?>" required <?= is_scalar($selected)&&(string)$selected===(string)$option['value']?'checked':'' ?>><span><?= e($option['text']) ?></span></label><?php endforeach; ?></fieldset><?php endforeach; ?></div><p class="bdi-answer-error" data-bdi-answer-error role="alert" hidden>لطفاً یک گزینه را انتخاب کنید.</p><div class="bdi-step-actions" data-bdi-navigation hidden><button type="button" class="btn btn-outline" data-bdi-prev>سؤال قبل</button><button type="button" class="btn btn-primary" data-bdi-next>سؤال بعد</button></div>
  <?php else: ?>
  <p class="bdi-note"><?= $spec['mode']==='items'?'رتبه خام گزینه را در دامنه تعیین‌شده وارد کنید؛ خودتان دوباره معکوس‌سازی یا دوبرابر نکنید. پاسخ خالی صفر محسوب نمی‌شود.':($slug==='neo'?'نمره خام هر عامل (صفر تا ۴۸) را از گزارش نمره‌گذاری‌شده وارد کنید؛ نمره T یا درصد را جای آن نگذارید.':'فقط نمره T هر مقیاس را از گزارش رسمی MMPI-2 وارد کنید؛ نمره خام یا نسخه RF را وارد نکنید. دامنه پشتیبانی‌شده این نمایشگر صفر تا ۱۲۰ است؛ مقادیر خارج از دامنه بدون تغییر باید توسط متخصص بررسی شوند.') ?></p>
  <div class="bdi-input-grid"><?php foreach($fields as $id=>$label):$selected=$values[$id]??''; ?><div class="bdi-input-item"><label for="assessment-<?= e((string)$id) ?>"><?= e($label) ?></label><input class="input" id="assessment-<?= e((string)$id) ?>" name="values[<?= e((string)$id) ?>]" type="number" min="<?= $inputMin ?>" max="<?= $inputMax ?>" step="1" inputmode="numeric" required value="<?= is_scalar($selected)?e((string)$selected):'' ?>"></div><?php endforeach; ?></div>
  <?php endif; ?>
  <div class="bdi-safety" data-bdi-safety role="alert" hidden><?= e(psych_safety_message()) ?></div>
  <div data-bdi-finish>
   <label class="bdi-check"><input type="checkbox" name="adult" value="1" required <?= ($_POST['adult']??'')==='1'?'checked':'' ?>> ۱۸ سال یا بیشتر دارم و می‌دانم نتیجه، تشخیص پزشکی نیست.</label>
   <?php if(!$form): ?><label class="bdi-check"><input type="checkbox" name="existing_form" value="1" required <?= ($_POST['existing_form']??'')==='1'?'checked':'' ?>> این امتیازها از پاسخنامه یا گزارش تکمیل‌شده <?= e($spec['version']) ?> هستند.</label><?php endif; ?>
   <?php if($spec['mode']!=='items'): ?><label class="bdi-check"><input type="checkbox" name="official_report" value="1" required <?= $context['official_report']?'checked':'' ?>> نمره‌ها قبلاً با روش رسمی همان نسخه محاسبه شده‌اند؛ این صفحه خودش صحت گزارش بیرونی را تأیید نمی‌کند.</label><?php endif; ?>
   <?php if($slug==='mmpi'): ?><label for="assessment-validity">وضعیت اعتبار طبق گزارش رسمی متخصص</label><select name="validity" id="assessment-validity" class="input" required><option value="">انتخاب کنید</option><?php foreach(['valid'=>'قابل تفسیر','invalid'=>'نامعتبر','uncertain'=>'نامشخص / نیازمند بررسی'] as $key=>$label): ?><option value="<?= $key ?>" <?= $context['validity']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select><?php endif; ?>
   <p class="bdi-note">با ساخت نمودار هیچ داده‌ای به هوش مصنوعی ارسال نمی‌شود. درخواست تحلیل مرحله‌ای جداگانه با رضایت شماست.</p><button type="submit" class="btn btn-primary"><?= $spec['mode']==='items'?'محاسبه امتیاز و ساخت نمودار':'ساخت نمودار گزارش واردشده' ?></button>
  </div>
 </form>
 <?php endif; ?>
 </section>
 <section class="bdi-card bdi-method"><h2>روش و منابع</h2><p><?= e($spec['method']) ?></p><p>با دریافت مجوز و نسخه معتبر، سؤال‌ها به همین موتور وصل می‌شوند. تغییر نسخه نیازمند تطبیق دوباره کلید و هنجار است.</p><ul><?php foreach($spec['sources'] as $title=>$href): ?><li><a href="<?= e($href) ?>" target="_blank" rel="noopener noreferrer"><?= e($title) ?></a></li><?php endforeach; ?></ul></section>
</section>
<?php
$content=ob_get_clean();
require __DIR__ . '/../includes/layout.php';
