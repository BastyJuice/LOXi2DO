<?php
declare(strict_types=1);

namespace Loxi2;

/** Read-only release metadata. Trust is supplied by the signed API response, never by public JSON alone. */
final class ApkUpdate
{
    public const MANIFEST = 'LOXi2-update.json';
    public const APK = 'LOXi2.apk';
    public const APK_PATH = '/plugins/loxi2do/LOXi2.apk';
    public const MAX_APK_BYTES = 104857600;

    /** Missing, incomplete or mismatched releases are not offered to tablets. No runtime files are written. */
    public static function read(?string $directory): ?array
    {
        try {
            if ($directory === null || $directory === '' || strpos($directory,"\0") !== false) { return null; }
            $root=realpath($directory);
            if ($root === false || !is_dir($root)) { return null; }
            $manifest=$root.'/'.self::MANIFEST; $apk=$root.'/'.self::APK;
            if (is_link($manifest) || is_link($apk) || !is_file($manifest) || !is_file($apk)) { return null; }
            clearstatcache(true,$manifest); clearstatcache(true,$apk);
            $size=filesize($manifest);
            if ($size === false || $size < 2 || $size > 8192) { return null; }
            $raw=@file_get_contents($manifest,false,null,0,8193);
            if (!is_string($raw) || strlen($raw) > 8192) { return null; }
            $value=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
            if (!is_array($value)) { return null; }
            $keys=array_keys($value); sort($keys);
            $expected=['apk_path','bytes','min_sdk','package_name','sha256','version_code','version_name'];
            if ($keys !== $expected || ($value['package_name'] ?? null) !== 'de.loxi2' || ($value['apk_path'] ?? null) !== self::APK_PATH
                || !is_int($value['version_code']) || $value['version_code'] < 1 || $value['version_code'] > 2147483647
                || !is_int($value['min_sdk']) || $value['min_sdk'] < 1 || $value['min_sdk'] > 1000
                || !is_int($value['bytes']) || $value['bytes'] < 1 || $value['bytes'] > self::MAX_APK_BYTES
                || !is_string($value['version_name']) || trim($value['version_name']) === '' || strlen($value['version_name']) > 160
                || preg_match('//u',$value['version_name']) !== 1 || preg_match('/[\p{Cc}\x{2028}\x{2029}]/u',$value['version_name'])
                || !is_string($value['sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$value['sha256'])) { return null; }
            $actual=filesize($apk);
            if ($actual !== $value['bytes']) { return null; }
            $hash=@hash_file('sha256',$apk);
            clearstatcache(true,$apk);
            if (!is_string($hash) || !hash_equals($value['sha256'],$hash) || filesize($apk) !== $value['bytes']) { return null; }
            return $value;
        } catch (\Throwable $unavailable) { return null; }
    }
}
