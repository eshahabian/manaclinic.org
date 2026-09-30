<?php
declare(strict_types=1);
@ini_set('display_errors', '0');
header_remove('X-Powered-By');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
session_name('mana_invitation');
session_set_cookie_params(['lifetime'=>0,'path'=>'/invite/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
if (empty($_SESSION['invite_csrf'])) {
    $_SESSION['invite_csrf'] = bin2hex(random_bytes(32));
}
function invite_response(int $code, bool $ok, string $message): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>$ok,'message'=>$message], JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = $_POST['csrf'] ?? '';
    $message = $_POST['message'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['invite_csrf'], $token)) {
        invite_response(403, false, 'صفحه را دوباره باز کن و تلاش کن.');
    }
    if (!is_string($message) || strlen($message) > 20000 || trim($message) === '') {
        invite_response(422, false, 'پیام باید بین ۱ تا ۵۰۰۰ حرف باشد.');
    }
    $message = trim($message);
    if (function_exists('mb_strlen') && mb_strlen($message, 'UTF-8') > 5000) {
        invite_response(422, false, 'پیام باید حداکثر ۵۰۰۰ حرف باشد.');
    }
    $hash = hash('sha256', $message);
    if (($_SESSION['invite_sent_hash'] ?? '') === $hash) {
        invite_response(200, true, 'این پیام قبلاً برای ارسال پذیرفته شد 💌');
    }
    if (time() - (int) ($_SESSION['invite_last_attempt'] ?? 0) < 30) {
        invite_response(429, false, '۳۰ ثانیه صبر کن و دوباره تلاش کن.');
    }
    // Shared per-IP limit. The recipient is fixed; SMTP credentials stay on the server.
    $limitFile = sys_get_temp_dir() . '/mana-invite-' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . '.json';
    $fp = @fopen($limitFile, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        invite_response(503, false, 'ارسال فعلاً در دسترس نیست؛ متن پیامت محفوظ است.');
    }
    $attempts = json_decode(stream_get_contents($fp) ?: '[]', true);
    $attempts = array_values(array_filter(is_array($attempts) ? $attempts : [], static fn($t): bool => is_int($t) && $t > time()-3600));
    if (count($attempts) >= 5) {
        flock($fp, LOCK_UN); fclose($fp);
        invite_response(429, false, 'تعداد تلاش‌ها زیاد شده؛ کمی بعد دوباره امتحان کن.');
    }
    $attempts[] = time();
    rewind($fp); ftruncate($fp, 0); fwrite($fp, json_encode($attempts));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    $_SESSION['invite_last_attempt'] = time();
    try {
        $configFile = dirname(__DIR__) . '/config.php';
        if (!is_file($configFile)) throw new RuntimeException('Missing site config');
        $config = require $configFile;
        require_once dirname(__DIR__) . '/includes/mail.php';
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['db_host'], $config['db_name'], $config['db_charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
        ]);
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'));
        $result = mail_send($pdo, 'eshahabian@gmail.com', 'پیام جدید از دعوت‌نامه', '<div dir="rtl" style="font-family:Tahoma;line-height:1.9">' . $safeMessage . '</div>', $message);
        if (empty($result['ok'])) {
            error_log('Mana invitation: SMTP submission failed.');
            invite_response(502, false, 'ارسال تأیید نشد؛ متن پیامت محفوظ است. کمی بعد دوباره تلاش کن.');
        }
        $_SESSION['invite_sent_hash'] = $hash;
        invite_response(200, true, 'پیامت برای ارسال پذیرفته شد 💌');
    } catch (Throwable $e) {
        error_log('Mana invitation: mail backend unavailable.');
        invite_response(503, false, 'ارسال فعلاً در دسترس نیست؛ متن پیامت محفوظ است.');
    }
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)) {
    header('Allow: GET, HEAD, POST');
    invite_response(405, false, 'روش درخواست مجاز نیست.');
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#fff7fa">
  <title>دعوت به شام ❤️</title>
  <style>
    :root{--ink:#482332;--muted:#835e6c;--rose:#e94373;--rose2:#ff7898;--paper:rgba(255,255,255,.78);--line:rgba(147,56,88,.14)}
    *{box-sizing:border-box}
    html{min-height:100%;margin:0}
    body{min-height:100vh;margin:0;display:grid;place-items:center;overflow-x:hidden;padding:22px;font-family:Tahoma,"Segoe UI",sans-serif;color:var(--ink);background:radial-gradient(circle at 15% 12%,#ffe4ec 0 9%,transparent 32%),radial-gradient(circle at 88% 85%,#ffd6e2 0 8%,transparent 30%),linear-gradient(145deg,#fffafd,#fff2f6)}
    body:before,body:after{content:"";position:fixed;border-radius:50%;filter:blur(2px);pointer-events:none}
    body:before{width:240px;height:240px;left:-90px;bottom:-80px;background:rgba(255,128,161,.16)}
    body:after{width:190px;height:190px;right:-70px;top:-60px;background:rgba(233,67,115,.10)}
    .petals{position:fixed;inset:0;overflow:hidden;pointer-events:none}
    .petals i{position:absolute;top:-30px;width:13px;height:20px;border-radius:90% 10% 90% 10%;background:#f5a1b7;opacity:.35;animation:fall linear infinite}
    .petals i:nth-child(1){left:8%;animation-duration:11s}.petals i:nth-child(2){left:25%;animation-duration:14s;animation-delay:-5s}.petals i:nth-child(3){left:52%;animation-duration:12s;animation-delay:-9s}.petals i:nth-child(4){left:73%;animation-duration:16s;animation-delay:-4s}.petals i:nth-child(5){left:91%;animation-duration:13s;animation-delay:-7s}
    .page-shell{direction:ltr;position:relative;z-index:1;width:min(1380px,100%);display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,610px) minmax(0,1fr);align-items:center}
    .side-portrait{position:relative;z-index:0;width:100%;height:auto;max-height:82vh;object-fit:contain;pointer-events:none;filter:saturate(.86) drop-shadow(0 24px 35px rgba(91,32,54,.18));-webkit-mask-image:linear-gradient(to bottom,#000 76%,transparent 100%);mask-image:linear-gradient(to bottom,#000 76%,transparent 100%)}
    .side-portrait--emad{grid-column:1;justify-self:end}
    .side-portrait--her{grid-column:3;justify-self:start}
    .card{direction:rtl;position:relative;z-index:2;isolation:isolate;overflow:hidden;width:min(610px,100%);min-height:520px;display:grid;place-items:center;padding:clamp(30px,7vw,64px) clamp(20px,6vw,50px);border:1px solid rgba(255,255,255,.9);border-radius:38px;background:rgba(255,255,255,.82);box-shadow:0 30px 80px rgba(123,39,70,.15),inset 0 0 0 1px var(--line);backdrop-filter:blur(18px);text-align:center}
    .card:after{content:"";position:absolute;inset:0;z-index:1;background:radial-gradient(circle at 50% 45%,rgba(255,255,255,.96) 0 25%,rgba(255,250,252,.73) 55%,rgba(255,245,248,.18) 100%);pointer-events:none}
    .inside-portraits{position:absolute;inset:0;z-index:0;overflow:hidden;pointer-events:none}
    .inside-portrait{position:absolute;bottom:-5%;width:auto;object-fit:contain;filter:saturate(.78) drop-shadow(0 18px 24px rgba(94,38,58,.16));-webkit-mask-image:linear-gradient(to bottom,#000 70%,transparent 100%);mask-image:linear-gradient(to bottom,#000 70%,transparent 100%)}
    .inside-portrait--emad{left:-18%;height:91%;opacity:.34}
    .inside-portrait--her{right:-18%;height:83%;opacity:.37}
    .view{position:relative;z-index:2;width:100%;animation:enter .55s cubic-bezier(.2,.8,.2,1)}
    .hidden,[hidden]{display:none!important}
    .heart{display:inline-grid;place-items:center;width:92px;height:92px;margin-bottom:24px;border-radius:50%;background:linear-gradient(145deg,#fff,#ffe3eb);box-shadow:0 14px 35px rgba(233,67,115,.16);font-size:48px;animation:beat 1.55s ease-in-out infinite}
    .eyebrow{margin:0 0 10px;color:var(--rose);font-size:13px;font-weight:700;letter-spacing:.03em}
    h1,h2{margin:0;line-height:1.55}h1{font-size:clamp(25px,6vw,38px)}h2{font-size:clamp(28px,7vw,42px)}
    .hint{min-height:28px;margin:18px 0 8px;color:var(--muted);font-size:14px}
    .actions{min-height:150px;display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap;padding:28px 8px 6px}
    button{border:0;font:inherit;font-weight:800;cursor:pointer;touch-action:manipulation;transition:transform .22s ease,opacity .22s ease,box-shadow .22s ease}
    button:focus-visible{outline:3px solid rgba(233,67,115,.25);outline-offset:4px}
    #yes{z-index:2;padding:15px 30px;border-radius:18px;color:#fff;background:linear-gradient(135deg,var(--rose),var(--rose2));box-shadow:0 12px 26px rgba(233,67,115,.28)}
    #yes:hover{box-shadow:0 16px 34px rgba(233,67,115,.36)}
    #no{min-width:72px;padding:14px 25px;border:1px solid var(--line);border-radius:18px;color:var(--muted);background:rgba(255,255,255,.75)}
    #who{padding:14px 22px;border:1px solid rgba(233,67,115,.18);border-radius:18px;color:var(--ink);background:rgba(255,242,247,.92);box-shadow:0 8px 20px rgba(145,47,80,.08)}
    .who-reveal{min-height:82px;display:grid;place-items:center;margin-top:5px;overflow:visible}
    #whoHand{display:inline-block;font-size:44px;line-height:1;opacity:0;transform:scale(.45);transform-origin:center;filter:drop-shadow(0 8px 12px rgba(93,35,55,.18));transition:transform .22s cubic-bezier(.2,.8,.2,1),opacity .2s ease}
    #whoHand.show{opacity:1}
    .shake{animation:shake .28s ease}
    .bouquet{font-size:78px;filter:drop-shadow(0 15px 16px rgba(150,50,82,.16));animation:float 2.5s ease-in-out infinite}
    .thanks{max-width:460px;margin:12px auto 0;font-size:clamp(16px,4vw,19px);line-height:2;color:#684253}
    .flowers{margin:20px 0;font-size:24px;letter-spacing:5px}
    .ask-time{max-width:440px;margin:0 auto;padding:17px 18px;border:1px solid var(--line);border-radius:20px;background:linear-gradient(135deg,#fff,#fff3f7);font-size:17px;font-weight:800;line-height:1.9}
    .message-form{max-width:440px;margin:16px auto 0;padding:16px;border:1px solid rgba(233,67,115,.18);border-radius:22px;background:rgba(255,255,255,.86);box-shadow:0 12px 30px rgba(126,42,72,.09)}
    .message-form textarea{display:block;width:100%;min-height:105px;resize:vertical;border:1px solid rgba(147,56,88,.2);border-radius:16px;padding:14px;font:inherit;font-size:15px;line-height:1.8;color:var(--ink);background:#fffafc;outline:none}
    .message-form textarea:focus{border-color:var(--rose);box-shadow:0 0 0 3px rgba(233,67,115,.1)}
    .message-form textarea::placeholder{color:#a47c8b}
    .send-message{width:100%;margin-top:10px;padding:13px 18px;border-radius:15px;color:#fff;background:linear-gradient(135deg,var(--rose),var(--rose2));box-shadow:0 10px 22px rgba(233,67,115,.22)}
    .form-note{margin:9px 0 0;color:var(--muted);font-size:11px;line-height:1.7}
    .signature{margin-top:18px;color:#a97b8d;font-size:12px}
    @keyframes enter{from{opacity:0;transform:translateY(12px) scale(.97)}to{opacity:1;transform:none}}
    @keyframes beat{0%,100%{transform:scale(1)}50%{transform:scale(1.09)}}
    @keyframes float{0%,100%{transform:translateY(0) rotate(-2deg)}50%{transform:translateY(-9px) rotate(2deg)}}
    @keyframes shake{0%,100%{translate:0}25%{translate:-6px}75%{translate:6px}}
    @keyframes fall{to{transform:translate(90px,110vh) rotate(540deg)}}
    @media(max-width:820px){body{display:block;padding:14px}.page-shell{margin:0 auto;grid-template-columns:1fr 1fr;align-items:end;row-gap:0}.side-portrait{grid-row:1;width:min(46vw,280px);max-height:280px;opacity:.9;margin-bottom:-30px}.side-portrait--emad{grid-column:1;justify-self:end;transform:translateX(5%)}.side-portrait--her{grid-column:2;justify-self:start;transform:translateX(-5%)}.card{grid-column:1/-1;grid-row:2;width:100%;min-height:500px}}
    @media(max-width:480px){body{padding:10px}.page-shell{width:100%}.side-portrait{width:49vw;max-height:230px;margin-bottom:-22px;opacity:.88}.card{min-height:480px;padding:30px 16px 26px;border-radius:26px}.heart{width:76px;height:76px;margin-bottom:18px;font-size:40px}h1{font-size:clamp(22px,7vw,29px)}.actions{gap:10px;min-height:150px;padding:22px 2px 4px}.flowers{letter-spacing:1px}.inside-portrait--emad{left:-36%;height:72%;opacity:.24}.inside-portrait--her{right:-34%;height:66%;opacity:.27}.thanks{font-size:15px;line-height:1.9}.ask-time{padding:14px 12px;font-size:15px}.bouquet{font-size:64px}}
    @media(prefers-reduced-motion:reduce){*{animation:none!important;scroll-behavior:auto!important}}
  </style>
</head>
<body>
  <div class="petals" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div>
  <div class="page-shell">
    <img class="side-portrait side-portrait--emad" src="assets/emad-side-v2.png" alt="" aria-hidden="true">
    <main class="card">
    <div class="inside-portraits" aria-hidden="true">
      <img class="inside-portrait inside-portrait--emad" src="assets/emad-cutout.png" alt="">
      <img class="inside-portrait inside-portrait--her" src="assets/her-cutout.png" alt="">
    </div>
    <section id="question" class="view">
      <div class="heart" aria-hidden="true">❤️</div>
      <p class="eyebrow">یه دعوت کوچیک برای یه آدم خیلی خاص</p>
      <h1>به نظرت میتونم اون مخاطب خاص را به صبحانه ،ناهار یا شام دعوت کنم ؟</h1>
      <p id="hint" class="hint" aria-live="polite">منتظر جوابتم 😌</p>
      <div class="actions">
        <button id="yes" type="button">آره 😍</button>
        <button id="no" type="button">نه 🙈</button>
        <button id="who" type="button">مخاطب خاص کیه؟</button>
      </div>
      <div class="who-reveal" aria-live="polite">
        <span id="whoHand" role="img" aria-label="خودت">🫵</span>
      </div>
    </section>

    <section id="accepted" class="view hidden" aria-live="polite">
      <div class="bouquet" aria-hidden="true">💐</div>
      <h2>می‌دونستم! 😍</h2>
      <p class="thanks">مرسی که قبول کردی ❤️ قول میدم یه شب خیلی قشنگ برات بسازم. حالا فقط مونده آماده شی که بریم یه قرار دونفره‌ی دوست‌داشتنی 🌹</p>
      <div class="flowers" aria-hidden="true">🌷 ❤️ 🌷 ❤️ 🌷</div>
      <p class="ask-time">می‌دونم وقتت خیلی پر هستش؛ روز . ساعتش رو خودت بگو</p>
      <form class="message-form" action="index.php" method="POST">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['invite_csrf'], ENT_QUOTES, 'UTF-8') ?>">
        
        
        
        
        <textarea maxlength="5000" name="message" placeholder="هرچی میخوای بهم بگو ...." aria-label="پیام برای عماد" required></textarea>
        <button class="send-message" type="submit">ارسال پیام 💌</button>
        <p class="form-note">با زدن ارسال، پیام برای عماد فرستاده می‌شود.</p>
        <p id="sendStatus" role="status" aria-live="polite" class="form-note"></p>
        <a id="emailFallback" hidden href="mailto:eshahabian@gmail.com">ارسال با برنامه ایمیل 💌</a>
      </form>
      <p class="signature">منتظر قرار قشنگمونم ✨</p>
    </section>
    </main>
    <img class="side-portrait side-portrait--her" src="assets/her-side-v2.png" alt="" aria-hidden="true">
  </div>

  <script>
    const messageForm=document.querySelector('.message-form');
    const messageBox=messageForm.querySelector('textarea');
    const emailFallback=document.querySelector('#emailFallback');
    const draftKey='invitation-message-draft';
    try { messageBox.value=sessionStorage.getItem(draftKey)||''; } catch (_) {}
    function saveMessage(){
      try { sessionStorage.setItem(draftKey,messageBox.value); } catch (_) {}
      emailFallback.href='mailto:eshahabian@gmail.com?subject='+encodeURIComponent('پیام از دعوت‌نامه')+'&body='+encodeURIComponent(messageBox.value);
    }
    messageBox.addEventListener('input',saveMessage);
    let sending=false;
    messageForm.addEventListener('submit',async(event)=>{
      event.preventDefault();
      if(sending || !messageForm.reportValidity() || !messageBox.value.trim())return;
      saveMessage();
      const button=messageForm.querySelector('.send-message');
      const status=document.querySelector('#sendStatus');
      sending=true;
      button.disabled=true;
      button.textContent='در حال ارسال…';
      status.textContent='';
      const submittedText=messageBox.value;
      try {
        const response=await fetch(messageForm.action,{
          method:'POST',credentials:'same-origin',body:new FormData(messageForm),
          headers:{'Accept':'application/json'}
        });
        const data=await response.json();
        if(!response.ok || data.ok!==true)throw new Error(data.message || 'ارسال تأیید نشد؛ متن پیامت محفوظ است.');
        status.textContent=data.message;
        button.textContent='ثبت شد 💌';
        try {
          if(messageBox.value===submittedText)sessionStorage.removeItem(draftKey);
        } catch (_) {}
        button.dataset.sent=submittedText;
      }catch(error){
        status.textContent=error instanceof SyntaxError || error instanceof TypeError
          ? 'ارتباط با سرور برقرار نشد؛ تحویل پیام تأیید نشده و متن پیامت محفوظ است.'
          : error.message;
        button.textContent='تلاش دوباره 💌';
      }finally{
        sending=false;
        button.disabled=button.dataset.sent===messageBox.value;
      }
    });
    messageBox.addEventListener('input',()=>{
      const button=messageForm.querySelector('.send-message');
      if(!sending){
        button.disabled=button.dataset.sent===messageBox.value;
        button.textContent=button.disabled?'ثبت شد 💌':'ارسال پیام 💌';
      }
    });
    saveMessage();
    const question=document.querySelector('#question');
    const accepted=document.querySelector('#accepted');
    const yes=document.querySelector('#yes');
    const no=document.querySelector('#no');
    const who=document.querySelector('#who');
    const whoHand=document.querySelector('#whoHand');
    const hint=document.querySelector('#hint');
    const texts=['مطمئنی؟ 😏','یه بار دیگه فکر کن 😌','فکر کنم اشتباهی روی نه زدی 😂','آره داره بزرگ‌تر میشه‌ها 😍','بازم نه؟ 🥺','من هنوز منتظرم 😄'];
    let count=0;
    let whoCount=0;

    yes.addEventListener('click',()=>{
      question.classList.add('hidden');
      accepted.classList.remove('hidden');
      document.title='می‌دونستم! 😍';
    });

    no.addEventListener('click',()=>{
      count++;
      const maxYesScale=window.innerWidth<520?1.65:2.5;
      const yesScale=Math.min(1+count*.13,maxYesScale);
      const noScale=Math.max(1-count*.065,.3);
      yes.style.transform=`scale(${yesScale})`;
      no.style.transform=`scale(${noScale})`;
      no.style.opacity=String(Math.max(1-count*.02,.48));
      hint.textContent=texts[(count-1)%texts.length];
      no.classList.remove('shake');
      void no.offsetWidth;
      no.classList.add('shake');
    });

    who.addEventListener('click',()=>{
      whoCount++;
      const maxHandScale=window.innerWidth<520?2.15:3.15;
      const handScale=Math.min(.75+whoCount*.34,maxHandScale);
      whoHand.classList.add('show');
      whoHand.style.transform=`scale(${handScale})`;
    });
  </script>
</body>
</html>
