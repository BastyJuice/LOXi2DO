<?php
declare(strict_types=1);

// This public URL is deliberately outside htmlauth; tablet requests authenticate by IP and HMAC.
ini_set('display_errors','0');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
try {
    require_once 'loxberry_system.php';
    require_once $lbpbindir . '/lib/UpgradeStorage.php';
    try { $upgradeRequest=Loxi2\UpgradeStorage::request($lbpdatadir); }
    catch (Throwable $upgrading) {
        http_response_code(503); header('Retry-After: 30'); header('Content-Type: application/json');
        echo '{"ok":false,"error":"LOXi2DO wird aktualisiert. Bitte später erneut versuchen."}'; exit;
    }
    require_once $lbpbindir . '/lib/PullApi.php';
    require_once $lbpbindir . '/lib/RamStorage.php';
    $length=$_SERVER['CONTENT_LENGTH'] ?? '';
    if ($length!=='' && (!ctype_digit($length) || (float)$length>16777216)) { http_response_code(413); exit; }
    $input=fopen('php://input','rb');
    if ($input===false) { throw new RuntimeException('Input unavailable.'); }
    $body=stream_get_contents($input,16777217); fclose($input);
    if ($body===false || strlen($body)>16777216) { http_response_code(413); exit; }
    $headers=[];
    foreach (['TIMESTAMP','NONCE','SIGNATURE'] as $part) { $headers['x-loxi2-'.strtolower($part)]=$_SERVER['HTTP_X_LOXI2_'.$part] ?? ''; }
    $ramDirectory=Loxi2\RamStorage::choose($lbplogdir);
    $api=new Loxi2\PullApi(new Loxi2\TabletStore($lbpconfigdir),$lbpdatadir,$ramDirectory,null,__DIR__);
    $response=$api->handle($_SERVER['REQUEST_METHOD'] ?? '',$_SERVER['REQUEST_URI'] ?? '',$_SERVER['REMOTE_ADDR'] ?? '',$headers,$body);
    http_response_code($response['status']);
    foreach ($response['headers'] as $name=>$value) { header($name.': '.$value); }
    if (isset($response['file'])) {
        $stream=fopen($response['file'],'rb');
        if ($stream!==false) { while (!feof($stream) && !connection_aborted()) { $chunk=fread($stream,65536); if ($chunk===false) { break; } echo $chunk; } fclose($stream); }
    } else { echo $response['body']; }
} catch (Throwable $error) {
    http_response_code(500); header('Content-Type: application/json'); echo '{"ok":false,"error":"LOXi2DO ist nicht verfügbar."}';
}
