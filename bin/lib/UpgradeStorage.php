<?php
declare(strict_types=1);

namespace Loxi2;

/** Persistent upgrade safety copy. LoxBerry purges both plugin config and data on upgrades. */
final class UpgradeStorage
{
    /** Hold this shared lock until the HTTP request (including streamed responses) finishes. */
    public static function request(string $data)
    {
        $root=self::root($data); $lock=self::lock($root,LOCK_SH);
        if (file_exists($root.'/active.json') || is_link($root.'/active.json')) {
            fclose($lock); throw new \RuntimeException('Plugin-Update läuft. Bitte kurz warten.');
        }
        return $lock;
    }
    public static function backup(string $config,string $data,string $operation): void
    {
        [$config,$data,$root,$key]=self::paths($config,$data,$operation);
        $lock=self::lock($root,LOCK_EX); $stage=null; $writers=[];
        try {
            if (file_exists($root.'/active.json') || is_link($root.'/active.json')) {
                throw new \RuntimeException('Eine frühere Update-Sicherung muss zuerst wiederhergestellt werden.');
            }
            $destination=$root.'/'.$key;
            if (file_exists($destination) || is_link($destination)) {
                throw new \RuntimeException('Diese Update-Operation besitzt bereits eine Sicherung.');
            }
            // Also drain writers from releases predating the HTTP request guard.
            if (is_dir($config)) { $writers[]=self::fileLock($config.'/tablets.lock'); }
            $paths=[];
            if (is_file($config.'/tablets.json') && !is_link($config.'/tablets.json')) {
                $registry=json_decode((string)file_get_contents($config.'/tablets.json'),true,32,JSON_THROW_ON_ERROR);
                foreach (array_keys($registry['tablets'] ?? []) as $id) {
                    if (!preg_match('/^[a-f0-9]{16}$/D',(string)$id)) { throw new \RuntimeException('Ungültige Tablet-Zuordnung vor dem Update.'); }
                    foreach (['state','replay'] as $kind) { $paths[$data.'/'.$kind.'_'.$id.'.lock']=true; }
                }
            }
            if (is_dir($data)) {
                foreach (new \DirectoryIterator($data) as $entry) {
                    if (preg_match('/^(state|replay)_([a-f0-9]{16})\.(?:json|lock)$/D',$entry->getFilename(),$match)) { $paths[$data.'/'.$match[1].'_'.$match[2].'.lock']=true; }
                }
            }
            if ($paths!==[] && !is_dir($data)) { self::mkdir($data); }
            ksort($paths); foreach ($paths as $path=>$unused) { $writers[]=self::fileLock($path); }
            $stage=$root.'/.stage-'.bin2hex(random_bytes(12)); self::mkdir($stage);
            self::copyTree($config,$stage.'/config'); self::copyTree($data,$stage.'/data');
            $manifest=['format'=>1,'operation'=>$key,'config'=>$config,'data'=>$data,
                'files'=>['config'=>self::inventory($stage.'/config'),'data'=>self::inventory($stage.'/data')]];
            if ($manifest['files']['config']!==self::inventory($config) || $manifest['files']['data']!==self::inventory($data)) {
                throw new \RuntimeException('Plugin-Daten wurden während der Sicherung verändert.');
            }
            self::write($stage.'/manifest.json',$manifest);
            if (!rename($stage,$destination)) { throw new \RuntimeException('Update-Sicherung kann nicht veröffentlicht werden.'); }
            $stage=null;
            self::write($root.'/active.json',['format'=>1,'operation'=>$key,'manifest_sha256'=>hash_file('sha256',$destination.'/manifest.json')]);
        } finally {
            if ($stage!==null && is_dir($stage)) { self::erase($stage); }
            foreach (array_reverse($writers) as $writer) { fclose($writer); }
            fclose($lock);
        }
    }
    /** Returns false only for a fresh installation without an outstanding update. */
    public static function restore(string $config,string $data,string $operation): bool
    {
        [$config,$data,$root,$key]=self::paths($config,$data,$operation);
        return self::restoreKey($config,$data,$root,$key);
    }
    private static function restoreKey(string $config,string $data,string $root,string $key): bool
    {
        $lock=self::lock($root,LOCK_EX); $stages=[];
        try {
            if (!file_exists($root.'/active.json') && !is_link($root.'/active.json')) { return false; }
            $manifest=self::manifest($root,$key,$config,$data);
            foreach (['data'=>$data,'config'=>$config] as $kind=>$target) {
                $current=self::inventory($target);
                if ($current!==[] && $current!==$manifest['files'][$kind]) {
                    throw new \RuntimeException('Vorhandene neue Plugin-Daten werden nicht mit einer Sicherung überschrieben.');
                }
            }
            foreach (['data'=>$data,'config'=>$config] as $kind=>$target) {
                if (self::inventory($target)===$manifest['files'][$kind] && is_dir($target)) { continue; }
                $stage=dirname($target).'/.loxi2do-restore-'.bin2hex(random_bytes(12));
                self::copyTree($root.'/'.$key.'/'.$kind,$stage); $stages[$target]=$stage;
                if (self::inventory($stage)!==$manifest['files'][$kind]) { throw new \RuntimeException('Update-Wiederherstellung ist unvollständig.'); }
            }
            // Publish data first; the registry must never authorize against incomplete data.
            foreach (['data'=>$data,'config'=>$config] as $target) {
                if (!isset($stages[$target])) { continue; }
                if (self::inventory($target)!==[]) { throw new \RuntimeException('Plugin-Ziel wurde während der Wiederherstellung verändert.'); }
                if (is_dir($target)) { self::erase($target); }
                if (!rename($stages[$target],$target)) { throw new \RuntimeException('Update-Wiederherstellung kann nicht veröffentlicht werden.'); }
            }
            foreach (['data'=>$data,'config'=>$config] as $kind=>$target) {
                if (self::inventory($target)!==$manifest['files'][$kind]) { throw new \RuntimeException('Wiederhergestellte Dateien stimmen nicht mit der Sicherung überein.'); }
            }
            return true;
        } finally {
            foreach ($stages as $stage) { if (is_dir($stage)) { self::erase($stage); } }
            fclose($lock);
        }
    }
    public static function complete(string $config,string $data,string $operation): void
    {
        [$config,$data,$root,$key]=self::paths($config,$data,$operation);
        self::completeKey($config,$data,$root,$key);
    }
    /** Explicit local recovery after an interrupted installer; never automatic on a new operation. */
    public static function recover(string $config,string $data): void
    {
        $config=self::target($config); $data=self::target($data); $root=self::root($data);
        if ($config===$data) { throw new \RuntimeException('Getrennte private Plugin-Pfade erforderlich.'); }
        $lock=self::lock($root,LOCK_EX);
        try {
            self::regular($root.'/active.json');
            $active=json_decode((string)file_get_contents($root.'/active.json'),true,8,JSON_THROW_ON_ERROR);
            $key=$active['operation'] ?? '';
            if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D',$key)) { throw new \RuntimeException('Keine gültige ausstehende Update-Sicherung.'); }
            self::manifest($root,$key,$config,$data);
        } finally { fclose($lock); }
        if (!self::restoreKey($config,$data,$root,$key)) { throw new \RuntimeException('Ausstehende Update-Sicherung fehlt.'); }
        self::completeKey($config,$data,$root,$key);
    }
    private static function completeKey(string $config,string $data,string $root,string $key): void
    {
        $lock=self::lock($root,LOCK_EX);
        try {
            $manifest=self::manifest($root,$key,$config,$data);
            foreach (['config'=>$config,'data'=>$data] as $kind=>$target) {
                if (self::inventory($target)!==$manifest['files'][$kind]) { throw new \RuntimeException('Update-Datenprüfung fehlgeschlagen; Sicherung bleibt erhalten.'); }
            }
            self::write($root.'/'.$key.'/completed.json',['format'=>1,'operation'=>$key]);
            if (!unlink($root.'/active.json')) { throw new \RuntimeException('Update-Sperre kann nicht aufgehoben werden.'); }
            // Keep the latest completed recovery copy only; never delete an outstanding one.
            foreach (new \DirectoryIterator($root) as $entry) {
                if ($entry->isDot() || $entry->getFilename()===$key || !preg_match('/^[a-f0-9]{64}$/D',$entry->getFilename())) { continue; }
                if ($entry->isDir() && !$entry->isLink() && is_file($entry->getPathname().'/completed.json')) { self::erase($entry->getPathname()); }
            }
        } finally { fclose($lock); }
    }
    private static function paths(string $config,string $data,string $operation): array
    {
        $config=self::target($config); $data=self::target($data);
        if ($config===$data || $operation==='' || strlen($operation)>8192 || strpos($operation,"\0")!==false) { throw new \RuntimeException('Ungültige Update-Operation.'); }
        return [$config,$data,self::root($data),hash('sha256',$operation)];
    }
    private static function root(string $data): string
    {
        $data=self::target($data,false); $root=dirname($data).'/.loxi2do-upgrade'; self::mkdir($root); return $root;
    }
    private static function target(string $path,bool $sameId=true): string
    {
        $path=rtrim($path,'/');
        if ($path==='' || $path[0]!=='/' || ($sameId && basename($path)!=='loxi2do') || in_array(basename($path),['.','..'],true) || strpos($path,"\0")!==false) { throw new \RuntimeException('Ungültiger privater Plugin-Pfad.'); }
        $parent=realpath(dirname($path));
        if ($parent===false || !is_dir($parent)) { throw new \RuntimeException('Privater Plugin-Basisordner fehlt.'); }
        $path=$parent.'/'.basename($path); self::directory($path); return $path;
    }
    private static function lock(string $root,int $mode)
    {
        return self::fileLock($root.'/requests.lock',$mode);
    }
    private static function fileLock(string $path,int $mode=LOCK_EX)
    {
        self::regular($path,true);
        $handle=fopen($path,'c');
        if ($handle===false) { throw new \RuntimeException('Update-Sperre ist nicht verfügbar.'); }
        if ((fileperms($path)&0777)!==0600 && !chmod($path,0600)) { fclose($handle); throw new \RuntimeException('Update-Sperre kann nicht geschützt werden.'); }
        if (!flock($handle,$mode|LOCK_NB)) { fclose($handle); throw new \RuntimeException('Plugin-Anfrage oder Update läuft; Vorgang erneut versuchen.'); }
        return $handle;
    }
    private static function manifest(string $root,string $key,string $config,string $data): array
    {
        self::regular($root.'/active.json'); $active=json_decode((string)file_get_contents($root.'/active.json'),true,8,JSON_THROW_ON_ERROR);
        if (($active['format'] ?? null)!==1 || ($active['operation'] ?? null)!==$key) { throw new \RuntimeException('Update-Sicherung gehört zu einer anderen Installation.'); }
        $snapshot=$root.'/'.$key; self::directory($snapshot); self::regular($snapshot.'/manifest.json');
        if (!hash_equals((string)($active['manifest_sha256'] ?? ''),(string)hash_file('sha256',$snapshot.'/manifest.json'))) { throw new \RuntimeException('Update-Sicherungsmanifest ist beschädigt.'); }
        $manifest=json_decode((string)file_get_contents($snapshot.'/manifest.json'),true,16,JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null)!==1 || ($manifest['operation'] ?? null)!==$key || ($manifest['config'] ?? null)!==$config || ($manifest['data'] ?? null)!==$data) { throw new \RuntimeException('Update-Sicherung passt nicht zu den privaten Plugin-Pfaden.'); }
        foreach (['config','data'] as $kind) {
            if (!is_array($manifest['files'][$kind] ?? null) || self::inventory($snapshot.'/'.$kind)!==$manifest['files'][$kind]) { throw new \RuntimeException('Update-Sicherungsdatei fehlt oder ist beschädigt.'); }
        }
        return $manifest;
    }
    private static function directory(string $path): void
    {
        if (is_link($path) || file_exists($path) && !is_dir($path)) { throw new \RuntimeException('Private Update-Ablage enthält einen Link oder unerwarteten Dateityp.'); }
    }
    private static function regular(string $path,bool $absent=false): void
    {
        if (is_link($path) || (file_exists($path) ? !is_file($path) : !$absent)) { throw new \RuntimeException('Private Update-Datei ist kein regulärer Inhalt.'); }
    }
    private static function mkdir(string $path): void
    {
        self::directory($path);
        if (!is_dir($path) && !mkdir($path,0700)) { throw new \RuntimeException('Private Update-Ablage kann nicht angelegt werden.'); }
        if ((fileperms($path)&0777)!==0700 && !chmod($path,0700)) { throw new \RuntimeException('Private Update-Ablage kann nicht geschützt werden.'); }
    }
    private static function inventory(string $path): array
    {
        self::directory($path); $files=[];
        if (!is_dir($path)) { return $files; }
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink() || !$entry->isDir() && !$entry->isFile()) { throw new \RuntimeException('Private Plugin-Dateien enthalten einen Link oder unbekannten Dateityp.'); }
            if ($entry->isFile()) {
                $name=substr($entry->getPathname(),strlen($path)+1); $hash=hash_file('sha256',$entry->getPathname());
                if ($hash===false) { throw new \RuntimeException('Private Plugin-Datei kann nicht geprüft werden.'); }
                $files[$name]=['bytes'=>$entry->getSize(),'sha256'=>$hash];
            }
        }
        ksort($files); return $files;
    }
    private static function copyTree(string $source,string $target): void
    {
        self::directory($source); self::mkdir($target);
        if (!is_dir($source)) { return; }
        foreach (new \DirectoryIterator($source) as $entry) {
            if ($entry->isDot()) { continue; }
            $destination=$target.'/'.$entry->getFilename();
            if ($entry->isLink()) { throw new \RuntimeException('Verlinkte Plugin-Dateien werden nicht gesichert.'); }
            if ($entry->isDir()) { self::copyTree($entry->getPathname(),$destination); }
            else {
                self::regular($entry->getPathname());
                if (!copy($entry->getPathname(),$destination) || !chmod($destination,0600)) { throw new \RuntimeException('Private Plugin-Datei kann nicht gesichert werden.'); }
            }
        }
    }
    private static function write(string $path,array $value): void
    {
        self::regular($path,true); $temp=dirname($path).'/.write-'.bin2hex(random_bytes(12));
        $encoded=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        try {
            if (file_put_contents($temp,$encoded,LOCK_EX)!==strlen($encoded) || !chmod($temp,0600) || !rename($temp,$path)) { throw new \RuntimeException('Update-Metadaten können nicht gespeichert werden.'); }
        } finally { if (is_file($temp)) { unlink($temp); } }
    }
    private static function erase(string $path): void
    {
        foreach (new \DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) { continue; }
            if ($entry->isDir() && !$entry->isLink()) { self::erase($entry->getPathname()); }
            elseif (!unlink($entry->getPathname())) { throw new \RuntimeException('Private temporäre Update-Datei kann nicht entfernt werden.'); }
        }
        if (!rmdir($path)) { throw new \RuntimeException('Private temporäre Update-Ablage kann nicht entfernt werden.'); }
    }
}
