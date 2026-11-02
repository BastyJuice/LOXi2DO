<?php
declare(strict_types=1);

namespace Loxi2;

require_once __DIR__ . '/MqttPublisher.php';
require_once __DIR__ . '/MqttTopics.php';

/** Latest observations and MQTT delivery cache only; never a configuration or history writer. */
final class TelemetryState
{
    public const MAX_BYTES = 131072;
    private ?string $directory;
    private string $id;
    private $publisher;
    private bool $nativePublisher;
    private $clock;
    private ?string $ledgerPath;
    private $legacyProbe;
    public function __construct(?string $ramDirectory, string $id, ?callable $publisher = null, ?callable $clock = null, ?string $dataDirectory = null, ?callable $legacyProbe = null)
    {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) { throw new \InvalidArgumentException('Ungültige Tablet-ID.'); }
        $this->id=$id;
        $this->directory=$ramDirectory===null ? null : rtrim($ramDirectory,'/').'/management_cache';
        if ($this->directory!==null && ((!is_dir($this->directory) && !@mkdir($this->directory,0700,true) && !is_dir($this->directory)) || !is_writable($this->directory))) { $this->directory=null; }
        if ($this->directory!==null) { @chmod($this->directory,0700); }
        $this->publisher=$publisher ?? [MqttPublisher::class,'publish'];
        $this->nativePublisher=$publisher===null;
        $this->clock=$clock ?? static function (): int { return time(); };
        $this->ledgerPath=$dataDirectory===null ? null : rtrim($dataDirectory,'/').'/mqtt_topics_'.$id.'.json';
        $this->legacyProbe=$legacyProbe ?? ($this->nativePublisher ? [MqttPublisher::class,'retainedLegacy'] : null);
    }
    public function read(): array
    {
        if ($this->directory===null) { return []; }
        $path=$this->path();
        if (!is_file($path)) { return []; }
        if (filesize($path)>1048576) { return []; }
        $value=json_decode((string)@file_get_contents($path),true,20);
        return is_array($value) ? $value : [];
    }
    public static function cleanStatus(array $status, string $token = '', bool $requireOrdering = false): array
    {
        if (strlen(json_encode($status,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR))>self::MAX_BYTES) { throw new \InvalidArgumentException('Statusmeldung ist größer als 128 KiB.'); }
        $nodes=0;
        $clean=self::cleanValue($status,0,$nodes,$token);
        $present=isset($clean['report_session']) || isset($clean['report_sequence']) || isset($clean['sampled_at_ms']);
        if ($requireOrdering || $present) {
            if (!is_string($clean['report_session'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$clean['report_session'])
                || !self::orderingNumber($clean['report_sequence'] ?? null)
                || !self::orderingNumber($clean['sampled_at_ms'] ?? null)) { throw new \InvalidArgumentException('Status-Reihenfolge fehlt oder ist ungültig.'); }
        }
        if (isset($clean['telemetry'])) {
            if (!is_array($clean['telemetry'])) { throw new \InvalidArgumentException('Telemetrie muss ein Objekt sein.'); }
            foreach ($clean['telemetry'] as $group=>$fields) {
                if (!in_array($group,['info','akku','wlan','display','system','audio','berechtigungen','dienst'],true) || !is_array($fields) && $fields!==null) {
                    throw new \InvalidArgumentException('Unbekannte Telemetriegruppe.');
                }
            }
        }
        return $clean;
    }
    /** json_decode represents epoch milliseconds as float on 32-bit PHP; never truncate to int. */
    private static function orderingNumber($value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float)$value)
            && $value>=0 && $value<=9007199254740991 && floor((float)$value)==$value;
    }
    private static function cleanValue($value,int $depth,int &$nodes,string $token)
    {
        if (++$nodes>4096 || $depth>8) { throw new \InvalidArgumentException('Statusmeldung ist zu tief oder enthält zu viele Werte.'); }
        if (is_array($value)) {
            if (count($value)>512) { throw new \InvalidArgumentException('Statusgruppe ist zu groß.'); }
            $clean=[];
            foreach ($value as $key=>$item) {
                if (is_int($key) && ($key<0 || $key>9999)) { throw new \InvalidArgumentException('Ungültiger Status-Index.'); }
                if (!is_int($key) && (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$key))) { throw new \InvalidArgumentException('Ungültiger Status-Schlüssel.'); }
                if (is_string($key) && preg_match('/token|password|passwd|secret|credential|authorization|cookie|api_key|private_key|signing_key|(?:^|_)(?:on|off)_url$/i',$key)) { continue; }
                $clean[$key]=self::cleanValue($item,$depth+1,$nodes,$token);
            }
            return $clean;
        }
        if (is_string($value)) {
            if (strlen($value)>4096 || preg_match('//u',$value)!==1) { throw new \InvalidArgumentException('Status-Text ist ungültig oder zu lang.'); }
            return $token==='' ? $value : str_replace($token,'[Token]',$value);
        }
        if ($value===null || is_bool($value) || is_int($value) || is_float($value) && is_finite($value)) { return $value; }
        throw new \InvalidArgumentException('Ungültiger Status-Wert.');
    }
    /** Status is committed to RAM before attempting MQTT. A broker failure never discards it. */
    public function accept(array $status, array $tablet, array $allTablets = []): array
    {
        if ($this->directory===null) { throw new \RuntimeException('RAM-Speicher für Statusmeldungen ist nicht verfügbar.'); }
        $status=self::cleanStatus($status,(string)($tablet['token'] ?? ''));
        $lock=@fopen($this->directory.'/telemetry_'.$this->id.'.lock','c');
        if ($lock===false) { throw new \RuntimeException('Statusmeldung kann nicht gesperrt werden.'); }
        @chmod($this->directory.'/telemetry_'.$this->id.'.lock',0600);
        try {
            if (!flock($lock,LOCK_EX)) { throw new \RuntimeException('Statusmeldung ist belegt.'); }
            $state=$this->read(); $now=($this->clock)();
            if (self::newer($state,$status,$now)) {
                $old=$state['status']['report_session'] ?? null; $next=$status['report_session'] ?? null;
                if (is_string($old) && is_string($next) && $old!==$next) {
                    $retired=is_array($state['retired_sessions'] ?? null) ? $state['retired_sessions'] : [];
                    $retired[]=$old; $state['retired_sessions']=array_slice(array_values(array_unique($retired)),-16);
                }
                $state['status']=$status;
                $state['last_observed']=$now;
            }
            $state['last_seen']=$now;
            // A damaged MQTT journal or naming failure must not discard the observation.
            $this->write($state);
            $prefix=MqttTopics::prefix($tablet,$allTablets);
            $topicTablet=$tablet; $topicTablet['mqtt_prefix']=$prefix;
            $desired=self::topics($state['status'] ?? $status,$topicTablet,$now);
            $published=is_array($state['published'] ?? null) ? $state['published'] : [];
            $prefixes=MqttTopics::prefixMap($allTablets ?: [$tablet]);
            $owners=[]; foreach ($prefixes as $owner=>$assigned) { $owners[$assigned]=(string)$owner; }
            $ledger=$this->readLedger();
            $known=[];
            foreach (array_merge($ledger['topics'] ?? [],array_keys($published)) as $topic) {
                if (is_string($topic) && $this->mayClear($topic,$owners)) { $known[$topic]=true; }
            }
            $legacyChecked=($ledger['legacy_checked'] ?? false)===true || $this->legacyProbe===null;
            if (!$legacyChecked && $now>=(int)($state['next_legacy_probe'] ?? 0)) {
                try { $legacy=($this->legacyProbe)($this->id); } catch (\Throwable $unavailable) { $legacy=null; }
                if (is_array($legacy)) {
                    foreach ($legacy as $topic=>$unused) {
                        if (is_string($topic) && MqttTopics::legacyId($topic)===$this->id && $this->mayClear($topic,$owners)) { $known[$topic]=true; }
                    }
                    $legacyChecked=true; $state['next_legacy_probe']=0;
                } else { $state['next_legacy_probe']=$now+60; }
            }
            $refresh=$now-(int)($state['last_full_publish'] ?? 0)>=60;
            $pending=[];
            foreach ($desired as $topic=>$payload) { if ($refresh || !array_key_exists($topic,$published) || $published[$topic]!==$payload) { $pending[$topic]=$payload; } }
            foreach ($known as $topic=>$unused) { if (!array_key_exists($topic,$desired)) { $pending[$topic]=''; } }
            $mqtt=is_array($state['mqtt'] ?? null) ? $state['mqtt'] : [];
            $mqtt=array_replace(['available'=>false,'state'=>'pending','message'=>'MQTT-Übertragung ausstehend','last_published'=>null,'last_attempt'=>null],$mqtt);
            $mqtt['topic_prefix']=$prefix; $mqtt['pending_topics']=count($pending);
            $mqtt['migration_pending']=!$legacyChecked;
            $state['mqtt']=$mqtt; $this->write($state);
            // Persist names before sending: a partial publish followed by loss of RAM must
            // still leave every possibly-retained name available for later cleanup.
            $journal=array_values(array_unique(array_merge(array_keys($known),array_keys($desired)))); sort($journal);
            $this->writeLedger($journal,$legacyChecked);
            if ($pending!==[] && $now>=(int)($state['next_attempt'] ?? 0)) {
                $state['mqtt']['last_attempt']=$now;
                try {
                    foreach (self::batches($pending) as $batch) {
                        if (($this->publisher)($batch)!==true) { throw new \RuntimeException('MQTT nicht verfügbar.'); }
                    }
                    $state['published']=$desired;
                    $state['mqtt']['available']=true; $state['mqtt']['state']='published';
                    $confirmed=$this->nativePublisher && MqttPublisher::deliveryConfirmed();
                    $state['mqtt']['message']=$confirmed ? 'Vom MQTT-Broker bestätigt (QoS 1)' : 'An MQTT übergeben; Zustellung ist unbestätigt';
                    if (!$legacyChecked) { $state['mqtt']['message'].=' Alte MQTT-Topics werden noch geprüft.'; }
                    $state['mqtt']['last_published']=$now; $state['mqtt']['pending_topics']=0;
                    $state['mqtt']['delivery_confirmed']=$confirmed;
                    $state['mqtt']['transport']=$this->nativePublisher ? MqttPublisher::transport() : 'custom';
                    $state['next_attempt']=0;
                    if ($refresh) { $state['last_full_publish']=$now; }
                    $current=array_keys($desired); sort($current); $this->writeLedger($current,$legacyChecked);
                } catch (\Throwable $unavailable) {
                    $state['mqtt']['available']=false; $state['mqtt']['state']='unavailable';
                    $reason=$this->nativePublisher ? MqttPublisher::lastError() : '';
                    $state['mqtt']['message']=($reason!=='' ? $reason : 'MQTT nicht erreichbar oder LoxBerry-MQTT nicht eingerichtet.').' Letzter Status bleibt im RAM.';
                    $state['mqtt']['delivery_confirmed']=false;
                    $state['mqtt']['transport']=$this->nativePublisher ? MqttPublisher::transport() : 'custom';
                    $state['next_attempt']=$now+5;
                }
                $this->write($state);
            } elseif ($pending===[]) {
                $current=array_keys($desired); sort($current); $this->writeLedger($current,$legacyChecked);
            }
            return $state;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private static function newer(array $state,array $status,int $now): bool
    {
        $old=$state['status'] ?? null;
        if (!is_array($old)) { return true; }
        if (!isset($status['report_session'])) {
            // Legacy app reports remain supported, but cannot replace a fresh streaming observation.
            return !isset($old['report_session']) || $now-(int)($state['last_seen'] ?? 0)>90;
        }
        if (($old['report_session'] ?? null)===$status['report_session']) { return $status['report_sequence']>($old['report_sequence'] ?? -1); }
        if (in_array($status['report_session'],$state['retired_sessions'] ?? [],true)) { return false; }
        // A new app process may legitimately have a corrected clock; old retired processes remain barred.
        return ($status['sampled_at_ms'] ?? 0)>=($old['sampled_at_ms'] ?? 0)-300000;
    }
    public static function topics(array $status,array $tablet,int $now): array
    {
        $id=(string)($tablet['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/D',$id)) { throw new \InvalidArgumentException('Ungültige MQTT-Tablet-ID.'); }
        $prefix=is_string($tablet['mqtt_prefix'] ?? null) ? $tablet['mqtt_prefix'] : MqttTopics::prefix($tablet);
        if (!MqttTopics::validPrefix($prefix)) { throw new \InvalidArgumentException('Ungültige MQTT-Topic-Basis.'); }
        $base=$prefix.'/'; $result=[];
        $groups=is_array($status['telemetry'] ?? null) ? $status['telemetry'] : [];
        $groups['info']=array_replace(['system_name'=>$status['name'] ?? null,'model'=>$status['model'] ?? null,'android_api'=>$status['android_api'] ?? null,'app_version'=>$status['version'] ?? null],$groups['info'] ?? []);
        $groups['info']['name']=trim((string)($tablet['name'] ?? ''))!=='' ? $tablet['name'] : ($status['name'] ?? null);
        $groups['info']['tablet_id']=$id; $groups['info']['ip']=$tablet['host'] ?? null; $groups['info']['last_seen']=$now;
        $groups['info']['sampled_at_ms']=$status['sampled_at_ms'] ?? null;
        $groups['akku']=array_replace(['percent'=>$status['battery_percent'] ?? null,'plugged'=>$status['charging'] ?? null,'care'=>$status['battery_care'] ?? []],$groups['akku'] ?? []);
        $groups['berechtigungen']=array_replace($status['permissions'] ?? [],$groups['berechtigungen'] ?? []);
        $groups['dienst']=array_replace($status['receiver'] ?? [],$groups['dienst'] ?? []);
        foreach (['info'=>'Info','akku'=>'Akku','wlan'=>'WLAN','display'=>'Display','system'=>'System','audio'=>'Audio','berechtigungen'=>'Berechtigungen','dienst'=>'Dienst'] as $key=>$group) {
            if (isset($groups[$key]) && is_array($groups[$key])) { self::flatten($result,$base.$group,$groups[$key]); }
        }
        if (count($result)>1024) { throw new \InvalidArgumentException('Zu viele MQTT-Statuswerte.'); }
        $token=(string)($tablet['token'] ?? '');
        if ($token!=='') { foreach ($result as &$payload) { $payload=str_replace($token,'[Token]',$payload); } unset($payload); }
        ksort($result); return $result;
    }
    private static function flatten(array &$result,string $prefix,$value): void
    {
        if (is_array($value)) {
            foreach ($value as $key=>$item) {
                if ((!is_int($key) && !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',(string)$key)) || preg_match('/token|password|passwd|secret|credential|authorization|cookie|api_key|private_key|signing_key|(?:^|_)(?:on|off)_url$/i',(string)$key)) { continue; }
                self::flatten($result,$prefix.'/'.$key,$item);
            }
        } elseif ($value===null) { $result[$prefix]=''; }
        elseif (is_bool($value)) { $result[$prefix]=$value?'1':'0'; }
        elseif (is_string($value) || is_int($value) || is_float($value)) { $result[$prefix]=(string)$value; }
    }
    private function mayClear(string $topic,array $owners): bool
    {
        $prefix=MqttTopics::topicPrefix($topic);
        if ($prefix!==null && isset($owners[$prefix]) && $owners[$prefix]!==$this->id) { return false; }
        $legacy=MqttTopics::legacyId($topic);
        // An ID-shaped registered name is valid, but cannot become authority to clear
        // an unclaimed legacy branch belonging to another tablet.
        if ($legacy!==null && $legacy!==$this->id && ($prefix===null || ($owners[$prefix] ?? null)!==$this->id)) { return false; }
        return MqttTopics::currentTopic($topic) || $legacy===$this->id;
    }
    /** Keep each migration batch inside the transport's count and encoded-byte limits. */
    private static function batches(array $messages): \Generator
    {
        $batch=[]; $bytes=2;
        foreach ($messages as $topic=>$value) {
            $entry=json_encode((string)$topic,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).':'.json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $extra=strlen($entry)+($batch===[] ? 0 : 1);
            if ($batch!==[] && (count($batch)>=1024 || $bytes+$extra>524288)) { yield $batch; $batch=[]; $bytes=2; $extra=strlen($entry); }
            if ($bytes+$extra>524288) { throw new \RuntimeException('Ein MQTT-Statuswert ist zu groß.'); }
            $batch[$topic]=$value; $bytes+=$extra;
        }
        if ($batch!==[]) { yield $batch; }
    }
    private function readLedger(): array
    {
        if ($this->ledgerPath===null || !file_exists($this->ledgerPath) && !is_link($this->ledgerPath)) { return []; }
        if (is_link($this->ledgerPath) || !is_file($this->ledgerPath) || filesize($this->ledgerPath)>4194304) { throw new \RuntimeException('MQTT-Topic-Sicherung ist nicht lesbar.'); }
        $value=json_decode((string)file_get_contents($this->ledgerPath),true,8,JSON_THROW_ON_ERROR);
        if (!is_array($value) || ($value['format'] ?? null)!==1 || ($value['tablet_id'] ?? null)!==$this->id
            || !is_array($value['topics'] ?? null) || count($value['topics'])>16384 || !is_bool($value['legacy_checked'] ?? null)) {
            throw new \RuntimeException('MQTT-Topic-Sicherung gehört nicht zu diesem Tablet.');
        }
        foreach ($value['topics'] as $topic) {
            if (!is_string($topic) || !MqttTopics::currentTopic($topic) && MqttTopics::legacyId($topic)!==$this->id) { throw new \RuntimeException('MQTT-Topic-Sicherung enthält ungültige Einträge.'); }
        }
        return $value;
    }
    private function writeLedger(array $topics,bool $legacyChecked): void
    {
        if ($this->ledgerPath===null) { return; }
        if (count($topics)>16384) { throw new \RuntimeException('Zu viele ausstehende MQTT-Topic-Namen.'); }
        $value=['format'=>1,'tablet_id'=>$this->id,'topics'=>$topics,'legacy_checked'=>$legacyChecked];
        $encoded=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
        if (strlen($encoded)>4194304) { throw new \RuntimeException('MQTT-Topic-Sicherung ist zu groß.'); }
        if (is_link($this->ledgerPath)) { throw new \RuntimeException('MQTT-Topic-Sicherung ist kein regulärer Inhalt.'); }
        if (is_file($this->ledgerPath) && file_get_contents($this->ledgerPath)===$encoded) { return; }
        $directory=dirname($this->ledgerPath);
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) { throw new \RuntimeException('MQTT-Topic-Sicherung kann nicht angelegt werden.'); }
        if ((fileperms($directory)&0777)!==0700) { chmod($directory,0700); }
        $temporary=tempnam($directory,'.mqtt-topics-');
        if ($temporary===false) { throw new \RuntimeException('MQTT-Topic-Sicherung kann nicht vorbereitet werden.'); }
        try {
            chmod($temporary,0600);
            $file=fopen($temporary,'wb');
            if ($file===false) { throw new \RuntimeException('MQTT-Topic-Sicherung kann nicht geschrieben werden.'); }
            try {
                if (fwrite($file,$encoded)!==strlen($encoded) || !fflush($file)) { throw new \RuntimeException('MQTT-Topic-Sicherung ist unvollständig.'); }
                if (function_exists('fsync') && !fsync($file)) { throw new \RuntimeException('MQTT-Topic-Sicherung konnte nicht bestätigt werden.'); }
            } finally { fclose($file); }
            if (!rename($temporary,$this->ledgerPath)) { throw new \RuntimeException('MQTT-Topic-Sicherung kann nicht veröffentlicht werden.'); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }
    private function path(): string { return $this->directory.'/telemetry_'.$this->id.'.json'; }
    private function write(array $state): void
    {
        $encoded=json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        if (strlen($encoded)>1048576) { throw new \RuntimeException('Status-Cache ist zu groß.'); }
        $tmp=tempnam($this->directory,'.telemetry-');
        if ($tmp===false) { throw new \RuntimeException('Status-Cache ist nicht verfügbar.'); }
        try { chmod($tmp,0600); if (file_put_contents($tmp,$encoded)!==strlen($encoded) || !rename($tmp,$this->path())) { throw new \RuntimeException('Status-Cache kann nicht gespeichert werden.'); } }
        finally { if (is_file($tmp)) { @unlink($tmp); } }
    }
}
