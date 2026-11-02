<?php
declare(strict_types=1);

namespace Loxi2;

require_once __DIR__.'/FleetState.php';

/**
 * Read-only dashboard projection. Never constructs FleetState (its constructor prepares directories).
 *
 * snapshot(): {server_time,total,online,offline,unknown,pending,mqtt_ok,devices:[]}
 * device: {id,name,host,model,app_version,last_seen,last_sync,age,connection_state,
 * battery_percent,charging,plugged,wifi_ssid,wifi_rssi,display_on,udp_running,
 * mqtt:{state,message,delivery_confirmed,last_published,pending_topics},
 * management:{state,message,pending}}. Missing measurements are null.
 * MQTT confirmation describes the last broker acknowledgement, not current subscriber availability.
 */
final class FleetOverview
{
    public static function snapshot(array $tablets,string $dataDirectory,?string $ramDirectory,?int $now=null): array
    {
        $now=$now ?? time();
        $result=['server_time'=>$now,'total'=>0,'online'=>0,'offline'=>0,'unknown'=>0,'pending'=>0,'mqtt_ok'=>0,'devices'=>[]];
        $secrets=[];
        foreach ($tablets as $tablet) {
            if (is_array($tablet) && is_string($tablet['token'] ?? null) && $tablet['token']!=='') { $secrets[]=$tablet['token']; }
        }
        // Replace long secrets first in case one registered token is a prefix of another.
        usort($secrets,static function (string $a,string $b): int { return strlen($b)<=>strlen($a); });
        foreach ($tablets as $tablet) {
            if (!is_array($tablet) || !is_string($tablet['id'] ?? null) || !preg_match('/^[a-f0-9]{16}$/D',$tablet['id'])) { continue; }
            $id=$tablet['id']; $readError=false;
            $state=self::read(rtrim($dataDirectory,'/').'/state_'.$id.'.json',33554432,48,$readError);
            $lastSync=self::timestamp($state['last_seen'] ?? null);
            $latest=$ramDirectory===null ? [] : self::read(rtrim($ramDirectory,'/').'/management_cache/telemetry_'.$id.'.json',1048576,20,$readError);
            // Match FleetState::read: only a newer RAM observation replaces the persistent report.
            if (is_array($latest['status'] ?? null) && (self::timestamp($latest['last_seen'] ?? null) ?? 0)>=($lastSync ?? 0)) {
                $state['status']=$latest['status']; $state['last_seen']=$latest['last_seen'] ?? null;
            }
            if (is_array($latest['mqtt'] ?? null)) { $state['mqtt']=$latest['mqtt']; }
            $status=is_array($state['status'] ?? null) ? $state['status'] : [];
            $telemetry=is_array($status['telemetry'] ?? null) ? $status['telemetry'] : [];
            $info=is_array($telemetry['info'] ?? null) ? $telemetry['info'] : [];
            $battery=is_array($telemetry['akku'] ?? null) ? $telemetry['akku'] : [];
            $wifi=is_array($telemetry['wlan'] ?? null) ? $telemetry['wlan'] : [];
            $display=is_array($telemetry['display'] ?? null) ? $telemetry['display'] : [];
            $service=is_array($telemetry['dienst'] ?? null) ? $telemetry['dienst'] : [];
            $receiver=is_array($status['receiver'] ?? null) ? $status['receiver'] : [];
            $lastSeen=self::timestamp($state['last_seen'] ?? null);
            $age=$lastSeen===null ? null : max(0,$now-$lastSeen);
            $connection=$age===null ? 'unknown' : ($age<=45 ? 'online' : 'offline');
            $host=self::text($tablet['host'] ?? null,$secrets,80) ?? '';
            $name=self::text($tablet['name'] ?? null,$secrets,160)
                ?? self::text($info['system_name'] ?? $status['name'] ?? null,$secrets,160) ?? ($host!=='' ? $host : 'Tablet');
            $mqtt=self::mqtt(is_array($state['mqtt'] ?? null) ? $state['mqtt'] : [],$secrets);
            $management=self::management($state,$secrets,$readError);
            $device=['id'=>$id,'name'=>$name,'host'=>$host,
                'model'=>self::text($info['model'] ?? $status['model'] ?? null,$secrets,160),
                'app_version'=>self::text($info['app_version'] ?? $status['version'] ?? null,$secrets,160),
                'last_seen'=>$lastSeen,'last_sync'=>$lastSync,'age'=>$age,'connection_state'=>$connection,
                'battery_percent'=>self::number(array_key_exists('percent',$battery) ? $battery['percent'] : ($status['battery_percent'] ?? null),0,100),
                // Older top-level charging means plugged, not actual battery charging.
                'charging'=>self::boolean($battery['charging'] ?? null),
                'plugged'=>self::boolean(array_key_exists('plugged',$battery) ? $battery['plugged'] : ($status['charging'] ?? null)),
                'wifi_ssid'=>self::text($wifi['ssid'] ?? null,$secrets,160),
                'wifi_rssi'=>self::number(array_key_exists('signal_dbm',$wifi) ? $wifi['signal_dbm'] : ($wifi['rssi_dbm'] ?? null),-200,0),
                'display_on'=>self::boolean($display['interactive'] ?? null),
                'udp_running'=>self::boolean(array_key_exists('udp_running',$service) ? $service['udp_running'] : ($receiver['udp_running'] ?? null)),
                'mqtt'=>$mqtt,'management'=>$management];
            $result['devices'][]=$device; $result['total']++; $result[$connection]++;
            if ($management['pending']) { $result['pending']++; }
            if ($mqtt['state']==='published' && $mqtt['delivery_confirmed']) { $result['mqtt_ok']++; }
        }
        return $result;
    }

    private static function mqtt(array $mqtt,array $secrets): array
    {
        $state=in_array($mqtt['state'] ?? null,['published','pending','unavailable'],true) ? $mqtt['state'] : 'unknown';
        $confirmed=$state==='published' && ($mqtt['delivery_confirmed'] ?? null)===true;
        return ['state'=>$state,'message'=>self::text($mqtt['message'] ?? null,$secrets,500),
            'delivery_confirmed'=>$confirmed,'last_published'=>self::timestamp($mqtt['last_published'] ?? null),
            'pending_topics'=>self::number($mqtt['pending_topics'] ?? null,0,1024)];
    }

    private static function management(array $state,array $secrets,bool $readError): array
    {
        $pendingState=is_array($state['pending'] ?? null) ? $state['pending'] : null;
        if ($pendingState!==null && !is_array($pendingState['sounds'] ?? null)) { $pendingState['sounds']=[]; }
        $meta=FleetState::metadata(['last_seen'=>$state['last_seen'] ?? null,'pending'=>$pendingState,
            'result'=>is_array($state['result'] ?? null) ? $state['result'] : null]);
        $pending=is_string($meta['pending_id']) && $meta['pending_id']!=='';
        $last=is_array($meta['result']) ? $meta['result'] : [];
        $state='idle'; $message='Keine Änderung ausstehend';
        if ($pending) {
            $state='pending'; $message='Änderung vorgemerkt';
            if (($last['state'] ?? null)==='conflict') { $state='conflict'; $message='Konflikt – Entscheidung nötig'; }
            elseif (($last['state'] ?? null)==='error') { $state='error'; $message='Fehler – Änderung ausstehend'; }
        } elseif (($last['state'] ?? null)==='applied') { $state='applied'; $message='Übernommen'; }
        elseif (($last['state'] ?? null)==='discarded') { $state='discarded'; $message='Vormerkung verworfen'; }
        if ($readError) { $state='error'; $message='Ein gemeldeter Status ist nicht lesbar'; }
        if ($state==='error' && !$readError) {
            $detail=self::text($last['error'] ?? null,$secrets,320);
            if ($detail!==null) { $message.=' · '.$detail; }
        }
        return ['state'=>$state,'message'=>$message,'pending'=>$pending];
    }

    private static function read(string $path,int $maximum,int $depth,bool &$error): array
    {
        if (!file_exists($path) && !is_link($path)) { return []; }
        if (is_link($path) || !is_file($path)) { $error=true; return []; }
        $size=@filesize($path);
        if (!is_int($size) || $size>$maximum) { $error=true; return []; }
        $text=@file_get_contents($path,false,null,0,$maximum+1);
        if (!is_string($text) || strlen($text)>$maximum) { $error=true; return []; }
        try { $value=json_decode($text,true,$depth,JSON_THROW_ON_ERROR); }
        catch (\Throwable $invalid) { $error=true; return []; }
        if (!is_array($value)) { $error=true; return []; }
        return $value;
    }

    private static function text($value,array $secrets,int $maximum): ?string
    {
        if (!is_string($value) || preg_match('//u',$value)!==1) { return null; }
        $value=str_replace($secrets,'[Token]',$value);
        $value=preg_replace('/[\p{Cc}\x{2028}\x{2029}]/u',' ',$value);
        $value=trim((string)$value);
        if ($value==='') { return null; }
        if (strlen($value)>$maximum) {
            $value=substr($value,0,$maximum);
            // Keep valid UTF-8 without depending on mbstring.
            while ($value!=='' && preg_match('//u',$value)!==1) { $value=substr($value,0,-1); }
        }
        return $value;
    }
    private static function boolean($value): ?bool { return is_bool($value) ? $value : null; }
    private static function number($value,int $minimum,int $maximum)
    {
        return (is_int($value) || is_float($value)) && is_finite((float)$value) && $value>=$minimum && $value<=$maximum ? $value : null;
    }
    private static function timestamp($value): ?int
    {
        return (is_int($value) || is_float($value)) && is_finite((float)$value) && $value>0 && $value<=PHP_INT_MAX && floor((float)$value)==$value ? (int)$value : null;
    }
}
