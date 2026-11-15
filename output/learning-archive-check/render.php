<?php
function url($s){return $s;}
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
http_response_code(200);
$mode=$argv[1];
if($mode==='hub') require __DIR__.'/pages/learning.php';
elseif($mode==='legacy') require __DIR__.'/pages/couples_therapy.php';
else {
 $learningSeries=$mode==='empty'?'schemas':($mode==='invalid-series'?'missing':'couples-therapy');
 if($mode==='series'||$mode==='empty'||$mode==='invalid-series') require __DIR__.'/pages/learning.php';
 else {$learningSeason=1;$learningEpisode=$mode==='invalid-episode'?999:1;require __DIR__.'/pages/learning_lesson.php';}
}
