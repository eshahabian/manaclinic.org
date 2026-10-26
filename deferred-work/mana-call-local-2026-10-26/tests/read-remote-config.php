<?php
// Read-only FTPS inspection. Never print configuration values or curl errors.
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$connection = json_decode(file_get_contents($root . '/.vscode/sftp.json'), true, 512, JSON_THROW_ON_ERROR);
$path = rtrim((string) ($connection['remotePath'] ?? '/'), '/') . '/config.php';
$ch = curl_init('ftp://' . $connection['host'] . ':' . ($connection['port'] ?? 21) . $path);
curl_setopt_array($ch, [CURLOPT_USERNAME => $connection['username'], CURLOPT_PASSWORD => $connection['password'],
    CURLOPT_USE_SSL => CURLUSESSL_ALL, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 35]);
if (is_file(__DIR__.'/runtime/windows-roots.pem')) curl_setopt($ch, CURLOPT_CAINFO, __DIR__.'/runtime/windows-roots.pem');
$raw = curl_exec($ch);
$errorCode = curl_errno($ch);
$responseCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$ok = is_string($raw) && str_starts_with(ltrim($raw), '<?php');
curl_close($ch);
if (!$ok) { echo "READ_ONLY_FTPS_CONFIG_UNAVAILABLE curl=$errorCode response=$responseCode\n"; exit(2); }
if (!is_dir(__DIR__ . '/private')) mkdir(__DIR__ . '/private', 0700);
file_put_contents(__DIR__ . '/private/remote-config.php', $raw);
$config = require __DIR__ . '/private/remote-config.php';
$selected = [];
foreach (['cloud' => 'livekit_', 'self' => 'livekit_self_'] as $provider => $prefix) {
    $cfg = ['url' => (string) ($config[$prefix.'url'] ?? ''), 'key' => (string) ($config[$prefix.'api_key'] ?? ''),
        'secret' => (string) ($config[$prefix.'api_secret'] ?? '')];
    $selected[$provider] = $cfg;
    echo $provider . ' configured=' . ($cfg['url'] !== '' && $cfg['key'] !== '' && $cfg['secret'] !== '' ? 'yes' : 'no') . "\n";
}
file_put_contents(__DIR__ . '/private/backends.json', json_encode($selected, JSON_THROW_ON_ERROR));
