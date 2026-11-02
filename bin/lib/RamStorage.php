<?php
declare(strict_types=1);

namespace Loxi2;

/** Verifies the actual Linux mount; PC/VM LoxBerry log directories may be persistent. */
final class RamStorage
{
    public static function choose(?string $preferred): ?string
    {
        $mounts=@file_get_contents('/proc/mounts');
        if (!is_string($mounts) || $mounts==='') { return null; }
        $fallback='/dev/shm/loxi2do-'.substr(hash('sha256',$preferred ?? ''),0,12);
        foreach ([$preferred,$fallback] as $candidate) {
            if (!is_string($candidate) || $candidate==='' || $candidate[0]!=='/' || strpos($candidate,"\0")!==false) { continue; }
            $path=$candidate; $suffix=[];
            while (!file_exists($path) && !is_link($path) && dirname($path)!==$path) { array_unshift($suffix,basename($path)); $path=dirname($path); }
            $real=realpath($path);
            if ($real===false) { continue; }
            $resolved=rtrim($real,'/').($suffix?'/'.implode('/',$suffix):'');
            if ($resolved==='') { $resolved='/'; }
            if (self::isRamPath($resolved,$mounts) && is_dir($real) && is_writable($real)) { return $resolved; }
        }
        return null;
    }

    /** Longest covering mount wins: a persistent submount inside tmpfs is not RAM. */
    public static function isRamPath(string $path,string $mounts): bool
    {
        if ($path==='' || $path[0]!=='/') { return false; }
        $best=-1; $ram=false;
        foreach (preg_split('/\r?\n/',$mounts) as $line) {
            $fields=preg_split('/[ \t]+/',trim($line));
            if (count($fields)<3) { continue; }
            $decode=static function (string $value): string { return strtr($value,['\\040'=>' ','\\011'=>"\t",'\\012'=>"\n",'\\134'=>'\\']); };
            $point=rtrim($decode($fields[1]),'/');
            if ($point!=='' && $path!==$point && strpos($path,$point.'/')!==0) { continue; }
            if (strlen($point)<$best) { continue; }
            $best=strlen($point);
            $ram=in_array($fields[2],['tmpfs','ramfs'],true) || preg_match('#^/dev/zram[0-9]+$#D',$decode($fields[0]))===1;
        }
        return $ram;
    }
}
