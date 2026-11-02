<?php
declare(strict_types=1);

namespace Loxi2;

// The LoxBerry SDK initializes globals and must be loaded at file scope.
// MQTT availability must never prevent telemetry acceptance/configuration.
try {
    if (!function_exists('mqtt_connectiondetails')) {
        $loxi2MqttLibrary=stream_resolve_include_path('loxberry_io.php');
        if ($loxi2MqttLibrary!==false) { require_once $loxi2MqttLibrary; }
    }
} catch (\Throwable $loxi2MqttLoadError) {}
unset($loxi2MqttLibrary,$loxi2MqttLoadError);

/** Direct MQTT 3.1.1 batch using LoxBerry's existing broker settings and PHP streams. */
final class MqttPublisher
{
    private const DEADLINE_SECONDS=2.5;
    private static string $lastTransport='unavailable';
    private static string $lastError='';
    private static bool $deliveryConfirmed=false;

    public static function transport(): string { return self::$lastTransport; }
    public static function lastError(): string { return self::$lastError; }
    /** Confirms broker acceptance of every QoS1 publish, not consumption by subscribers. */
    public static function deliveryConfirmed(): bool { return self::$deliveryConfirmed; }

    public static function publish(array $messages): bool
    {
        self::$lastTransport='unavailable'; self::$lastError=''; self::$deliveryConfirmed=false;
        if ($messages===[]) { return true; }
        $socket=null;
        try {
            self::validateMessages($messages);
            $deadline=microtime(true)+self::DEADLINE_SECONDS;
            $socket=self::open($deadline);
            $identifier=0;
            foreach ($messages as $topic=>$value) {
                $identifier++;
                // Retain + QoS1: empty values remove a retained leaf on the broker.
                self::writeSocket($socket,self::packet(0x33,self::mqttString($topic).pack('n',$identifier).$value),$deadline);
                if (self::readSocket($socket,4,$deadline)!=="\x40\x02".pack('n',$identifier)) {
                    throw new \RuntimeException('Der MQTT-Broker hat eine ungültige Veröffentlichungsbestätigung gesendet.');
                }
            }
            self::writeSocket($socket,"\xe0\x00",$deadline);
            self::$lastTransport='direct_php_mqtt'; self::$deliveryConfirmed=true;
            return true;
        } catch (\Throwable $failed) {
            self::failure($failed);
            return false;
        } finally { if (is_resource($socket)) { fclose($socket); } }
    }

    /**
     * Read only this tablet's legacy retained branches for one-time layout migration.
     * QoS0 subscriptions + a PING response bound the snapshot; no ongoing listener remains.
     * Nothing is published or deleted by this method.
     * @return array<string,string>|null Null means discovery could not be completed safely.
     */
    public static function retainedLegacy(string $id): ?array
    {
        self::$lastTransport='unavailable'; self::$lastError=''; self::$deliveryConfirmed=false;
        if (!preg_match('/^[a-f0-9]{16}$/D',$id)) {
            self::$lastError='Ungültige MQTT-Tablet-ID.'; return null;
        }
        $socket=null;
        try {
            $deadline=microtime(true)+self::DEADLINE_SECONDS;
            $socket=self::open($deadline);
            $filters=['/loxi2do/'.$id.'/#','/loxi2do/0/'.$id.'/#','/loxi2do/o/'.$id.'/#',
                'loxi2do/0/'.$id.'/#','loxi2do/o/'.$id.'/#'];
            $subscription=pack('n',1);
            foreach ($filters as $filter) { $subscription.=self::mqttString($filter)."\x00"; }
            self::writeSocket($socket,self::packet(0x82,$subscription),$deadline);
            // Mosquitto queues the retained snapshot before processing the following PING.
            self::writeSocket($socket,"\xc0\x00",$deadline);
            $acknowledged=false; $messages=[]; $bytes=0; $packets=0;
            while (true) {
                if (++$packets>2050) { throw new \RuntimeException('MQTT-Altbestände sind ungültig oder zu groß.'); }
                [$header,$body]=self::readPacket($socket,$deadline);
                if ($header===0x90) {
                    if ($acknowledged || $body!==pack('n',1).str_repeat("\x00",count($filters))) {
                        throw new \RuntimeException('MQTT-Altbestände konnten nicht gelesen werden.');
                    }
                    $acknowledged=true;
                } elseif ($header===0x30 || $header===0x31) {
                    if (strlen($body)<2) { throw new \RuntimeException('MQTT-Altbestände sind ungültig oder zu groß.'); }
                    $length=unpack('n',substr($body,0,2))[1];
                    if ($length===0 || strlen($body)<2+$length) { throw new \RuntimeException('MQTT-Altbestände sind ungültig oder zu groß.'); }
                    $topic=substr($body,2,$length); $payload=substr($body,2+$length);
                    if (!self::legacyTopic($topic,$id) || strlen($payload)>4096 || preg_match('//u',$payload)!==1) {
                        throw new \RuntimeException('MQTT-Altbestände sind ungültig oder zu groß.');
                    }
                    // A concurrently delivered live update is not evidence of retained content.
                    if ($header===0x31) {
                        if (isset($messages[$topic])) { $bytes-=strlen($topic)+strlen($messages[$topic]); }
                        $messages[$topic]=$payload; $bytes+=strlen($topic)+strlen($payload);
                        if (count($messages)>1024 || $bytes>524288) {
                            throw new \RuntimeException('MQTT-Altbestände sind ungültig oder zu groß.');
                        }
                    }
                } elseif ($header===0xd0 && $body==='') {
                    if (!$acknowledged) { throw new \RuntimeException('MQTT-Altbestände konnten nicht gelesen werden.'); }
                    break;
                } else { throw new \RuntimeException('MQTT-Altbestände konnten nicht gelesen werden.'); }
            }
            if (strlen(json_encode($messages,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>524288) {
                throw new \RuntimeException('MQTT-Altbestände sind ungültig oder zu groß.');
            }
            self::writeSocket($socket,"\xe0\x00",$deadline);
            return $messages;
        } catch (\Throwable $failed) { self::failure($failed); return null; }
        finally { if (is_resource($socket)) { fclose($socket); } }
    }

    private static function validateMessages(array $messages): void
    {
        if (count($messages)>1024) { throw new \RuntimeException('Ungültige MQTT-Statuswerte.'); }
        foreach ($messages as $topic=>$value) {
            $valid=is_string($topic) && (self::canonicalTopic($topic) || $value==='' && self::legacyTopic($topic));
            if (!$valid || strlen($topic)>65535 || !is_string($value) || strlen($value)>4096 || preg_match('//u',$value)!==1) {
                throw new \RuntimeException('Ungültige MQTT-Statuswerte.');
            }
        }
        if (strlen(json_encode($messages,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>524288) {
            throw new \RuntimeException('Ungültige MQTT-Statuswerte.');
        }
    }
    private static function canonicalTopic(string $topic): bool
    {
        return preg_match('~^loxi2do/([^/+#\x00-\x1f\x7f]+)/(?:Info|Akku|WLAN|Display|System|Audio|Berechtigungen|Dienst)(?:/(?:[a-z][a-z0-9_]{0,63}|[0-9]{1,4}))+$~uD',$topic)===1
            && preg_match('/[\p{Cc}\x{2028}\x{2029}]/u',$topic)!==1;
    }
    private static function legacyTopic(string $topic,?string $id=null): bool
    {
        $device=$id===null ? '[a-f0-9]{16}' : preg_quote($id,'~');
        return preg_match('~^(?:/loxi2do/(?:[0o]/)?|loxi2do/[0o]/)'.$device.'/(?:Info|Akku|WLAN|Display|System|Audio|Berechtigungen|Dienst)(?:/(?:[a-z][a-z0-9_]{0,63}|[0-9]{1,4}))+$~D',$topic)===1;
    }
    private static function readPacket($socket,float $deadline): array
    {
        $header=ord(self::readSocket($socket,1,$deadline)); $length=0; $multiplier=1;
        for ($i=0;$i<4;$i++) {
            $byte=ord(self::readSocket($socket,1,$deadline)); $length+=($byte&127)*$multiplier;
            if ($length>8192) { throw new \RuntimeException('MQTT-Altbestände sind ungültig oder zu groß.'); }
            if (($byte&128)===0) { return [$header,$length===0 ? '' : self::readSocket($socket,$length,$deadline)]; }
            $multiplier*=128;
        }
        throw new \RuntimeException('MQTT-Altbestände sind ungültig oder zu groß.');
    }
    private static function failure(\Throwable $failed): void
    {
        // Never expose raw broker, SDK or PHP messages: they may include credentials.
        $safe=$failed instanceof \RuntimeException && get_class($failed)===\RuntimeException::class ? $failed->getMessage() : '';
        $known=['Ungültige MQTT-Statuswerte.','Die MQTT-Broker-Einstellungen sind ungültig.',
            'Die MQTT-Funktionen des LoxBerry sind nicht verfügbar.','Die MQTT-CA-Datei ist nicht lesbar.',
            'Die MQTT-Verbindung konnte nicht hergestellt werden.','Die MQTT-TLS-Verbindung konnte nicht geprüft werden.',
            'Der MQTT-Broker hat eine ungültige Verbindungsantwort gesendet.','Der MQTT-Broker hat die Anmeldung abgelehnt.',
            'Der MQTT-Broker hat die Verbindung abgelehnt.','Der MQTT-Broker hat eine ungültige Veröffentlichungsbestätigung gesendet.',
            'Die MQTT-Verbindung wurde unterbrochen.','Die MQTT-Verbindung konnte nicht geprüft werden.',
            'MQTT-Veröffentlichung nach 2,5 Sekunden abgebrochen.','MQTT-Altbestände konnten nicht gelesen werden.',
            'MQTT-Altbestände sind ungültig oder zu groß.'];
        self::$lastError=in_array($safe,$known,true) ? $safe : 'MQTT-Veröffentlichung fehlgeschlagen.';
    }
    /** Open and authenticate one native connection. Caller owns the returned socket. */
    private static function open(float $deadline)
    {
        $socket=null;
        try {
            $connection=self::connection();
            $host=$connection['host'];
            $socketHost=filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false ? '['.$host.']' : $host;
            $ssl=[];
            if ($connection['tls']) {
                $ssl=['verify_peer'=>true,'verify_peer_name'=>$connection['verify_name'],'peer_name'=>$host,
                    'SNI_enabled'=>true,'disable_compression'=>true,
                    'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0)];
                if ($connection['ca_file']!=='') { $ssl['cafile']=$connection['ca_file']; }
                else { $ssl['capath']='/etc/ssl/certs'; }
            }
            $socket=@stream_socket_client('tcp://'.$socketHost.':'.$connection['port'],$errorCode,$errorText,
                self::remaining($deadline),STREAM_CLIENT_CONNECT,stream_context_create($ssl===[] ? [] : ['ssl'=>$ssl]));
            if (!is_resource($socket)) { throw new \RuntimeException('Die MQTT-Verbindung konnte nicht hergestellt werden.'); }
            stream_set_blocking($socket,false);
            if ($connection['tls']) {
                do {
                    self::remaining($deadline);
                    $ready=@stream_socket_enable_crypto($socket,true,$ssl['crypto_method']);
                    if ($ready===false) { throw new \RuntimeException('Die MQTT-TLS-Verbindung konnte nicht geprüft werden.'); }
                    if ($ready===0) { self::waitSocket($socket,false,$deadline); }
                } while ($ready!==true);
            }
            $flags=0x02; // Clean session; no subscriptions or will.
            $payload=self::mqttString('loxi2do-'.bin2hex(random_bytes(6)));
            if ($connection['user']!=='') { $flags|=0x80; $payload.=self::mqttString($connection['user']); }
            if ($connection['password']!=='') { $flags|=0x40; $payload.=self::mqttString($connection['password']); }
            self::writeSocket($socket,self::packet(0x10,"\x00\x04MQTT\x04".chr($flags)."\x00\x0a".$payload),$deadline);
            $ack=self::readSocket($socket,4,$deadline);
            if (substr($ack,0,3)!=="\x20\x02\x00") { throw new \RuntimeException('Der MQTT-Broker hat eine ungültige Verbindungsantwort gesendet.'); }
            $code=ord($ack[3]);
            if ($code!==0) {
                throw new \RuntimeException($code===4 || $code===5
                    ? 'Der MQTT-Broker hat die Anmeldung abgelehnt.' : 'Der MQTT-Broker hat die Verbindung abgelehnt.');
            }
            return $socket;
        } catch (\Throwable $failed) {
            if (is_resource($socket)) { fclose($socket); }
            throw $failed;
        }
    }

    private static function connection(): array
    {
        if (!function_exists('mqtt_connectiondetails')) { throw new \RuntimeException('Die MQTT-Funktionen des LoxBerry sind nicht verfügbar.'); }
        $creds=\mqtt_connectiondetails();
        if (!is_array($creds)) { throw new \RuntimeException('Die MQTT-Broker-Einstellungen sind ungültig.'); }
        $host=self::text($creds['brokerhost'] ?? '',253);
        if (substr($host,0,1)==='[' && substr($host,-1)===']') { $host=substr($host,1,-1); }
        if ($host==='' || (filter_var($host,FILTER_VALIDATE_IP)===false
            && !preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\.?$/Di',$host))) {
            throw new \RuntimeException('Die MQTT-Broker-Einstellungen sind ungültig.');
        }
        $tls=self::boolean($creds['tls'] ?? false);
        $port=$tls ? ($creds['tls_brokerport'] ?? $creds['brokerport'] ?? 8883) : ($creds['brokerport'] ?? 1883);
        if ((!is_int($port) && !is_string($port)) || !preg_match('/^[0-9]{1,5}$/D',(string)$port) || (int)$port<1 || (int)$port>65535) {
            throw new \RuntimeException('Die MQTT-Broker-Einstellungen sind ungültig.');
        }
        $user=self::text($creds['brokeruser'] ?? '',1000); $password=self::text($creds['brokerpass'] ?? '',1000);
        if ($password!=='' && $user==='') { throw new \RuntimeException('Die MQTT-Broker-Einstellungen sind ungültig.'); }
        $verify=self::boolean($creds['tls_verify'] ?? true);
        $ca=$tls ? self::text($creds['tls_cafile'] ?? '',4096) : '';
        if ($ca!=='' && (!is_file($ca) || !is_readable($ca))) { throw new \RuntimeException('Die MQTT-CA-Datei ist nicht lesbar.'); }
        return ['host'=>$host,'port'=>(int)$port,'user'=>$user,'password'=>$password,'tls'=>$tls,'verify_name'=>$verify,'ca_file'=>$ca];
    }

    private static function text($value,int $maximum): string
    {
        if ((!is_string($value) && !is_int($value)) || strlen((string)$value)>$maximum
            || preg_match('/[\x00-\x1f\x7f]/',(string)$value) || preg_match('//u',(string)$value)!==1) {
            throw new \RuntimeException('Die MQTT-Broker-Einstellungen sind ungültig.');
        }
        return (string)$value;
    }
    private static function boolean($value): bool
    {
        $result=filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
        if ($result===null || (!is_bool($value) && !is_int($value) && !is_string($value))) { throw new \RuntimeException('Die MQTT-Broker-Einstellungen sind ungültig.'); }
        return $result;
    }
    private static function mqttString(string $value): string { return pack('n',strlen($value)).$value; }
    private static function packet(int $type,string $body): string
    {
        $length=strlen($body); $header=chr($type);
        do { $byte=$length%128; $length=intdiv($length,128); $header.=chr($byte | ($length>0 ? 0x80 : 0)); } while ($length>0);
        return $header.$body;
    }
    private static function remaining(float $deadline): float
    {
        $remaining=$deadline-microtime(true);
        if ($remaining<=0) { throw new \RuntimeException('MQTT-Veröffentlichung nach 2,5 Sekunden abgebrochen.'); }
        return $remaining;
    }
    private static function waitSocket($socket,bool $writing,float $deadline): void
    {
        $remaining=self::remaining($deadline); $seconds=(int)$remaining;
        $read=$writing ? [] : [$socket]; $write=$writing ? [$socket] : []; $except=[];
        $ready=@stream_select($read,$write,$except,$seconds,(int)(($remaining-$seconds)*1000000));
        if ($ready===false) { throw new \RuntimeException('Die MQTT-Verbindung konnte nicht geprüft werden.'); }
        if ($ready===0) { throw new \RuntimeException('MQTT-Veröffentlichung nach 2,5 Sekunden abgebrochen.'); }
    }
    private static function writeSocket($socket,string $data,float $deadline): void
    {
        $offset=0; $length=strlen($data);
        while ($offset<$length) {
            self::waitSocket($socket,true,$deadline);
            $written=@fwrite($socket,substr($data,$offset,65536));
            if ($written===false || ($written===0 && feof($socket))) { throw new \RuntimeException('Die MQTT-Verbindung wurde unterbrochen.'); }
            $offset+=$written;
        }
    }
    private static function readSocket($socket,int $length,float $deadline): string
    {
        $data='';
        while (strlen($data)<$length) {
            self::waitSocket($socket,false,$deadline);
            $chunk=@fread($socket,$length-strlen($data));
            if ($chunk===false || ($chunk==='' && feof($socket))) { throw new \RuntimeException('Die MQTT-Verbindung wurde unterbrochen.'); }
            $data.=$chunk;
        }
        return $data;
    }
}
