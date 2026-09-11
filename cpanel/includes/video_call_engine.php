<?php
declare(strict_types=1);

function mana_video_call_engine_path(): string
{
    return dirname(__DIR__) . '/storage/video-call-engine.json';
}

function mana_video_call_engine(): string
{
    $path = mana_video_call_engine_path();
    if (!is_file($path)) {
        return 'livekit';
    }
    $raw = @file_get_contents($path);
    $data = json_decode((string) $raw, true);
    $mode = is_array($data) ? (string) ($data['engine'] ?? '') : '';
    return $mode === 'legacy' ? 'legacy' : 'livekit';
}

function mana_video_call_engine_set(string $mode): bool
{
    $mode = $mode === 'legacy' ? 'legacy' : 'livekit';
    $path = mana_video_call_engine_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $path . '.tmp';
    $json = json_encode([
        'engine' => $mode,
        'updated_at' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || @file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}
