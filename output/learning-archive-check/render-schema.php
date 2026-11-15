<?php
function url($s){return $s;}
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
http_response_code(200);
$learningSeries='schemas';$learningSeason=1;$learningEpisode=1;
if(($argv[1]??'')==='series')require __DIR__.'/pages/learning.php';
else require __DIR__.'/pages/learning_lesson.php';
