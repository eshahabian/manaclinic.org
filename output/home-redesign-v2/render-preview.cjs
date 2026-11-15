const fs=require('fs');
const {PhpNode}=require('../access-update/tools/node_modules/php-wasm/PhpNode');
(async()=>{
 const php=new PhpNode({version:'8.3'});let output='';
 php.addEventListener('output',e=>output+=e.detail.join(''));
 await php.mkdir('/app');await php.mkdir('/app/pages');await php.mkdir('/app/includes');
 await php.writeFile('/app/includes/daily_quotes.php','<?php function daily_quote_for_date(){return "";}');
 await php.writeFile('/app/includes/public_workshop_banners.php','');
 await php.writeFile('/app/includes/layout.php',`<?php echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/style.css">'.$pageHead.'</head><body><header class="container-page" style="padding:1.5rem 0;display:flex;justify-content:space-between;border-bottom:1px solid var(--line)"><strong>مانا کلینیک</strong><nav>خانه　 خدمات　 درمانگران　 مقالات</nav></header><main>'.$content.'</main></body></html>';`);
 let page=fs.readFileSync('cpanel/pages/home.php','utf8');
 page=page.replace(/\$doctors = \$pdo->query\([\s\S]*?\$pageTitle =/,"$doctors=[];$articles=[];$pageTitle =");
 await php.writeFile('/app/pages/home.php',page);
 const code=await php.run(`<?php function url($s){return $s;}function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}function assistant_enabled(){return false;} require '/app/pages/home.php';`);
 if(code!==0||/Fatal error|Parse error|Warning:/.test(output))throw Error(output);
 fs.writeFileSync('output/home-redesign-v2/preview.html',output);
 console.log('PASS PHP 8.3 rendered actual homepage template; no fatal errors or warnings');
})();
