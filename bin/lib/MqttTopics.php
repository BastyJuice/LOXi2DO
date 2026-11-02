<?php
declare(strict_types=1);

namespace Loxi2;

/** Public MQTT names are derived from registered labels, never from secrets or hardware identifiers. */
final class MqttTopics
{
    public static function prefix(array $tablet,array $all=[]): string
    {
        $id=$tablet['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{16}$/D',$id)) { throw new \InvalidArgumentException('Ungültige MQTT-Tablet-ID.'); }
        $records=[];
        foreach ($all as $record) {
            if (is_array($record) && is_string($record['id'] ?? null) && preg_match('/^[a-f0-9]{16}$/D',$record['id'])) { $records[$record['id']]=$record; }
        }
        $records[$id]=$tablet;
        return self::prefixMap($records)[$id];
    }
    /** @return array<string,string> Tablet registry ID => MQTT prefix without a trailing slash. */
    public static function prefixMap(array $all): array
    {
        $records=[]; $secrets=[];
        foreach ($all as $record) {
            if (!is_array($record) || !is_string($record['id'] ?? null) || !preg_match('/^[a-f0-9]{16}$/D',$record['id'])) { continue; }
            $records[$record['id']]=$record;
            if (is_string($record['token'] ?? null) && $record['token']!=='') { $secrets[]=$record['token']; }
        }
        ksort($records);
        usort($secrets,static function (string $a,string $b): int { return strlen($b)<=>strlen($a); });
        $names=[]; $hosts=[];
        foreach ($records as $id=>$record) {
            $host=is_string($record['host'] ?? null) ? trim($record['host']) : '';
            $name=is_string($record['name'] ?? null) ? trim($record['name']) : '';
            if ($name==='' || preg_match('//u',$name)!==1) { $name=$host; }
            if ($name==='') { throw new \InvalidArgumentException('Für das MQTT-Topic fehlt der Tablet-Name oder die IP.'); }
            $names[$id]=str_replace($secrets,'[Token]',$name);
            $hosts[$id]=$host;
        }
        // Re-evaluate all candidates after each suffix step. This also handles a literal
        // label such as "Flur (192.168.0.27)" colliding with another device's suffix.
        for ($round=0;$round<=count($records);$round++) {
            $groups=[];
            foreach ($names as $id=>$name) { $groups[self::segment($name)][]=$id; }
            $changed=false;
            foreach ($groups as $ids) {
                if (count($ids)<2) { continue; }
                foreach ($ids as $id) {
                    if ($hosts[$id]==='') { throw new \InvalidArgumentException('Gleiche Tablet-Namen benötigen unterschiedliche Tablet-IPs.'); }
                    $names[$id].=' ('.$hosts[$id].')'; $changed=true;
                }
            }
            if (!$changed) {
                $result=[];
                foreach ($names as $id=>$name) { $result[$id]='loxi2do/'.self::segment($name); }
                return $result;
            }
        }
        throw new \InvalidArgumentException('Die MQTT-Namen der Tablets sind nicht eindeutig.');
    }
    private static function segment(string $name): string
    {
        if (preg_match('//u',$name)!==1 || preg_match('/[\p{Cc}\x{2028}\x{2029}]/u',$name)) { throw new \InvalidArgumentException('Ungültiger MQTT-Tablet-Name.'); }
        // Escape percent first so the representation remains injective. Spaces and umlauts stay readable.
        return strtr($name,['%'=>'%25','/'=>'%2F','+'=>'%2B','#'=>'%23']);
    }
    public static function validPrefix(string $prefix): bool
    {
        return strlen($prefix)<=65500 && preg_match('#^loxi2do/[^/\x00-\x1f\x7f+\#]+$#uD',$prefix)===1
            && preg_match('/[\p{Cc}\x{2028}\x{2029}]/u',$prefix)!==1;
    }
    public static function currentTopic(string $topic): bool
    {
        return strlen($topic)<=65535 && preg_match('#^loxi2do/[^/\x00-\x1f\x7f+\#]+/(?:Info|Akku|WLAN|Display|System|Audio|Berechtigungen|Dienst)(?:/(?:[a-z][a-z0-9_]{0,63}|[0-9]{1,4}))+$#uD',$topic)===1
            && preg_match('/[\p{Cc}\x{2028}\x{2029}]/u',$topic)!==1;
    }
    public static function legacyId(string $topic): ?string
    {
        return preg_match('#^/?loxi2do/(?:(?:0|o)/)?([a-f0-9]{16})/(?:Info|Akku|WLAN|Display|System|Audio|Berechtigungen|Dienst)(?:/(?:[a-z][a-z0-9_]{0,63}|[0-9]{1,4}))+$#D',$topic,$match)===1 ? $match[1] : null;
    }
    public static function topicPrefix(string $topic): ?string
    {
        if (!self::currentTopic($topic)) { return null; }
        return substr($topic,0,(int)strpos($topic,'/',strlen('loxi2do/')));
    }
}
