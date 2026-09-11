<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/video_call_engine.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode(['engine' => mana_video_call_engine()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
