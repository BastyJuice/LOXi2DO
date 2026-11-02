<?php
declare(strict_types=1);

namespace Loxi2;

require_once __DIR__ . '/TabletStore.php';
require_once __DIR__ . '/FleetState.php';
require_once __DIR__ . '/RemoteClient.php';
require_once __DIR__ . '/ApkUpdate.php';

/** Public transport endpoint; every operation requires the exact registered tablet IP and HMAC. */
final class PullApi
{
    public const PATH = '/plugins/loxi2do/api.php';
    private TabletStore $tablets;
    private string $dataDirectory;
    private ?string $ramDirectory;
    private $mqttPublisher;
    private ?string $apkDirectory;
    public function __construct(TabletStore $tablets, string $dataDirectory, ?string $ramDirectory = null, ?callable $mqttPublisher = null, ?string $apkDirectory = null) { $this->tablets=$tablets; $this->dataDirectory=rtrim($dataDirectory,'/'); $this->ramDirectory=$ramDirectory; $this->mqttPublisher=$mqttPublisher; $this->apkDirectory=$apkDirectory; }
    public function handle(string $method, string $path, string $source, array $headers, string $body): array
    {
        $headers = array_change_key_case($headers,CASE_LOWER);
        $tablet = null; $nonce = ''; $registryLock=null;
        try {
            if ($method !== 'POST' || $path !== self::PATH || strlen($body)>16777216) { return $this->response(400,['ok'=>false,'error'=>'Ungültige Anfrage.']); }
            // Keep topic ownership stable until retained migration/publication is complete.
            // Registry edits take the corresponding exclusive lock.
            $registryLock=$this->tablets->sharedLock();
            $allTablets=$this->tablets->all();
            foreach ($allTablets as $candidate) {
                if (($candidate['host'] ?? null) === $source) { $tablet=$candidate; break; }
            }
            if ($tablet === null || !is_string($tablet['token'] ?? null) || $tablet['token'] === '') { return $this->response(401,['ok'=>false,'error'=>'Nicht autorisiert.']); }
            $timestamp=$headers['x-loxi2-timestamp'] ?? ''; $nonce=$headers['x-loxi2-nonce'] ?? ''; $signature=$headers['x-loxi2-signature'] ?? '';
            if (!is_string($timestamp) || !preg_match('/^[0-9]{1,12}$/D',$timestamp) || abs(time()-(float)$timestamp)>300
                || !is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/D',$nonce)
                || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D',$signature)
                || !hash_equals(RemoteClient::signature($tablet['token'],$method,$path,$timestamp,$nonce,hash('sha256',$body)),$signature)) {
                return $this->response(401,['ok'=>false,'error'=>'Nicht autorisiert.']);
            }
            $request=json_decode($body,true,48,JSON_THROW_ON_ERROR);
            if (!is_array($request) || ($request['api'] ?? null)!==1) { throw new \InvalidArgumentException('Unbekannte API-Version.'); }
            if (($request['op'] ?? null)==='update') {
                if (strlen($body)>1024 || count($request)!==2 || array_diff(array_keys($request),['op','api'])!==[]) {
                    throw new \InvalidArgumentException('Ungültige Update-Abfrage.');
                }
                if ($this->ramDirectory===null) { throw new \RuntimeException('RAM-Speicher für Update-Abfragen ist nicht verfügbar.'); }
                $this->consumeNonce($tablet['id'],$nonce,(int)$timestamp,true);
                return $this->response(200,['ok'=>true,'api'=>1,'update'=>ApkUpdate::read($this->apkDirectory)],$tablet['token'],$nonce);
            }
            $telemetry=($request['op'] ?? null)==='telemetry';
            if ($telemetry && (strlen($body)>TelemetryState::MAX_BYTES || !is_array($request['status'] ?? null)
                || array_diff(array_keys($request),['op','api','status'])!==[])) { throw new \InvalidArgumentException('Ungültige Statusmeldung.'); }
            if ($telemetry) {
                $clean=TelemetryState::cleanStatus($request['status'],$tablet['token'],true);
                if ($this->ramDirectory===null) { throw new \RuntimeException('RAM-Speicher für Statusmeldungen ist nicht verfügbar.'); }
                $this->consumeNonce($tablet['id'],$nonce,(int)$timestamp,true);
                $cache=new TelemetryState($this->ramDirectory,$tablet['id'],$this->mqttPublisher,null,$this->dataDirectory);
                $accepted=$cache->accept($clean,$tablet,$allTablets);
                return $this->response(200,['ok'=>true,'api'=>1,'pending'=>null,'mqtt'=>$accepted['mqtt']],$tablet['token'],$nonce);
            }
            // Full configuration exchanges retain the durable history and revision-bound queue.
            $state = new FleetState($this->dataDirectory,$tablet['id'],$this->ramDirectory);
            $this->consumeNonce($tablet['id'],$nonce,(int)$timestamp);
            if (($request['op'] ?? null)==='sync') {
                if (isset($request['ack']['error']) && is_string($request['ack']['error'])) { $request['ack']['error']=str_replace($tablet['token'],'[Token]',$request['ack']['error']); }
                if (is_array($request['status'] ?? null)) { $request['status']=TelemetryState::cleanStatus($request['status'],$tablet['token']); }
                $pending=$state->sync($request);
                // A failed/missing broker or RAM cache must never reject an accepted configuration ACK.
                try { (new TelemetryState($this->ramDirectory,$tablet['id'],$this->mqttPublisher,null,$this->dataDirectory))->accept($request['status'] ?? [],$tablet,$allTablets); }
                catch (\Throwable $unavailable) { /* The durable report remains available. */ }
                if ($pending!==null) { $pending['settings']=(object)$pending['settings']; $pending['actions']=(object)$pending['actions']; }
                // Registry metadata is independent of the 25 app settings and revision-bound queue.
                // An empty name explicitly restores the tablet's native system-name fallback.
                return $this->response(200,['ok'=>true,'api'=>1,'tablet_name'=>$tablet['name'] ?? '', 'pending'=>$pending],$tablet['token'],$nonce);
            }
            if (($request['op'] ?? null)==='sound') {
                if (!is_string($request['asset'] ?? null) || !is_string($request['pending_id'] ?? null)) { throw new \InvalidArgumentException('Sound-Auftrag fehlt.'); }
                $file=$state->sound($request['asset'],$request['pending_id']);
                $hash=hash_file('sha256',$file); $bytes=filesize($file);
                if ($hash===false || $bytes===false || $bytes<1 || $bytes>52428800) { throw new \RuntimeException('Sounddatei ist nicht verfügbar.'); }
                return ['status'=>200,'file'=>$file,'body'=>'','headers'=>[
                    'Content-Type'=>'application/octet-stream','Content-Length'=>(string)$bytes,'Cache-Control'=>'no-store',
                    'X-LOXi2-Signature'=>RemoteClient::responseHashSignature($tablet['token'],$nonce,200,$hash),
                ]];
            }
            throw new \InvalidArgumentException('Unbekannte Operation.');
        } catch (\Throwable $error) {
            $status=$error instanceof \InvalidArgumentException || $error instanceof \JsonException ? 400 : 409;
            $message=substr($error->getMessage(),0,1000);
            if ($tablet !== null) { $message=str_replace($tablet['token'],'[Token]',$message); }
            return $this->response($status,['ok'=>false,'error'=>$message],$tablet['token'] ?? null,$nonce);
        } finally { if (is_resource($registryLock)) { fclose($registryLock); } }
    }
    private function response(int $status, array $value, ?string $token=null, string $nonce=''): array
    {
        $body=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        $headers=['Content-Type'=>'application/json; charset=utf-8','Content-Length'=>(string)strlen($body),'Cache-Control'=>'no-store'];
        if ($token!==null && preg_match('/^[a-f0-9]{32}$/D',$nonce)) { $headers['X-LOXi2-Signature']=RemoteClient::responseSignature($token,$nonce,$status,$body); }
        return ['status'=>$status,'body'=>$body,'headers'=>$headers];
    }
    private function consumeNonce(string $id,string $nonce,int $timestamp,bool $telemetry=false): void
    {
        $directory=$this->dataDirectory;
        if ($telemetry && $this->ramDirectory!==null) {
            $candidate=rtrim($this->ramDirectory,'/').'/management_cache';
            if ((!is_dir($candidate) && !@mkdir($candidate,0700,true) && !is_dir($candidate)) || !is_writable($candidate)) { throw new \RuntimeException('RAM-Anfrageprüfung ist nicht verfügbar.'); }
            @chmod($candidate,0700); $directory=$candidate;
        }
        $prefix=$directory.'/replay_'.$id; $lock=fopen($prefix.'.lock','c');
        if ($lock===false) { throw new \RuntimeException('Anfrage kann nicht geprüft werden.'); }
        chmod($prefix.'.lock',0600);
        try {
            if (!flock($lock,LOCK_EX)) { throw new \RuntimeException('Anfrageprüfung ist belegt.'); }
            $cache=[];
            if (is_file($prefix.'.json')) {
                if (filesize($prefix.'.json')>262144) { throw new \RuntimeException('Anfrageprüfung ist nicht verfügbar.'); }
                $cache=json_decode((string)file_get_contents($prefix.'.json'),true,16,JSON_THROW_ON_ERROR);
                if (!is_array($cache)) { throw new \RuntimeException('Anfrageprüfung ist nicht verfügbar.'); }
            }
            $cutoff=time()-300;
            foreach ($cache as $key=>$seen) { if (!is_int($seen) || $seen<$cutoff) { unset($cache[$key]); } }
            if (isset($cache[$nonce])) { throw new \InvalidArgumentException('Anfrage wurde bereits verarbeitet.'); }
            if (count($cache)>=1024) { throw new \RuntimeException('Zu viele Anfragen; später erneut versuchen.'); }
            $cache[$nonce]=$timestamp;
            $temporary=tempnam($directory,'.replay-');
            if ($temporary===false) { throw new \RuntimeException('Anfrageprüfung kann nicht gespeichert werden.'); }
            try {
                chmod($temporary,0600); $encoded=json_encode($cache,JSON_THROW_ON_ERROR);
                if (file_put_contents($temporary,$encoded)!==strlen($encoded) || !rename($temporary,$prefix.'.json')) { throw new \RuntimeException('Anfrageprüfung kann nicht gespeichert werden.'); }
            } finally { if (is_file($temporary)) { unlink($temporary); } }
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
