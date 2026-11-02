<?php
declare(strict_types=1);

namespace Loxi2;

/** One-time private copy for the changed plugin identifier; never removes the old installation. */
final class LegacyMigration
{
    public static function copy(string $config, string $data): string
    {
        $config = self::target($config); $data = self::target($data);
        if ($config===$data) { throw new \InvalidArgumentException('Konfiguration und Daten benötigen getrennte private Zielordner.'); }
        $oldConfig = dirname($config).'/loxi2hq'; $oldData = dirname($data).'/loxi2hq';
        self::directory($oldConfig); self::directory($oldData);
        if (!is_file($oldConfig.'/tablets.json')) { return 'none'; }
        $locks = []; $stages = []; $published = [];
        try {
            $locks[] = self::lock(dirname($config).'/.loxi2do-migration.lock');
            if (!self::emptyTarget($config) || !self::emptyTarget($data)) { return 'existing'; }
            $locks[] = self::lock($oldConfig.'/tablets.lock');
            $registry = self::readRegistry($oldConfig.'/tablets.json');
            $ids = array_map('strval',array_keys($registry['tablets'])); sort($ids);
            foreach ($ids as $id) {
                if (!is_string($id) || preg_match('/^[a-f0-9]{16}$/D', $id)!==1) {
                    throw new \RuntimeException('Ungültige Gerätezuordnung in der bisherigen Konfiguration.');
                }
                if (is_dir($oldData)) {
                    $locks[] = self::lock($oldData.'/replay_'.$id.'.lock');
                    $locks[] = self::lock($oldData.'/state_'.$id.'.lock');
                }
            }
            foreach ([$config,$data] as $destination) {
                $stage = dirname($destination).'/.loxi2do-import-'.bin2hex(random_bytes(12));
                if (!mkdir($stage,0700)) { throw new \RuntimeException('Private Migrationsablage konnte nicht angelegt werden.'); }
                $stages[$destination] = $stage;
            }
            self::file($oldConfig.'/tablets.json',$stages[$config].'/tablets.json');
            if (is_dir($oldData)) { self::data($oldData,$stages[$data],$ids); }
            // Both copies are complete before publishing. Publish the registry last, so a
            // new endpoint cannot authorize a tablet against an incomplete data directory.
            if (!self::emptyTarget($config) || !self::emptyTarget($data)) {
                throw new \RuntimeException('Die neue Plugin-Ablage wurde während der Übernahme verändert.');
            }
            foreach ([$data,$config] as $destination) {
                $existed = is_dir($destination);
                self::removeEmpty($destination);
                if (!rename($stages[$destination],$destination)) {
                    if ($existed && !is_dir($destination)) { mkdir($destination,0700); }
                    throw new \RuntimeException('Die private Übernahme konnte nicht veröffentlicht werden.');
                }
                $published[$destination] = $existed;
            }
            return 'copied';
        } catch (\Throwable $error) {
            foreach (array_reverse(array_keys($published)) as $destination) {
                if (!rename($destination,$stages[$destination])) {
                    throw new \RuntimeException('Übernahme unvollständig; bisherige Daten sind unverändert erhalten.',0,$error);
                }
                if ($published[$destination]) { mkdir($destination,0700); }
            }
            throw $error;
        } finally {
            foreach ($stages as $stage) { if (is_dir($stage)) { self::erase($stage); } }
            foreach (array_reverse($locks) as $lock) { flock($lock,LOCK_UN); fclose($lock); }
        }
    }

    private static function target(string $path): string
    {
        $path = rtrim($path,'/');
        if (basename($path)!=='loxi2do' || $path==='' || $path[0]!=='/' || strpos($path,"\0")!==false) {
            throw new \InvalidArgumentException('Ungültiger privater Plugin-Zielordner.');
        }
        $parent = realpath(dirname($path));
        if ($parent===false || !is_dir($parent)) { throw new \RuntimeException('Privater Plugin-Basisordner fehlt.'); }
        $target = $parent.'/loxi2do'; self::directory($target); return $target;
    }
    private static function directory(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
            throw new \RuntimeException('Plugin-Ablage enthält einen unerwarteten Dateityp oder Link.');
        }
    }
    private static function emptyTarget(string $path): bool
    {
        self::directory($path);
        if (!is_dir($path)) { return true; }
        foreach (new \DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) { continue; }
            if ($entry->isLink() || !$entry->isDir() || !self::emptyTarget($entry->getPathname())) { return false; }
        }
        return true;
    }
    private static function lock(string $path)
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) { throw new \RuntimeException('Unsichere bisherige Dateisperre.'); }
        $handle = fopen($path,'c');
        if ($handle===false) { throw new \RuntimeException('Bisherige Ablage kann nicht gesperrt werden.'); }
        chmod($path,0600);
        if (!flock($handle,LOCK_EX|LOCK_NB)) { fclose($handle); throw new \RuntimeException('Bisheriges Plugin ist beschäftigt. Übernahme nach Ende der Bearbeitung erneut starten.'); }
        return $handle;
    }
    private static function readRegistry(string $path): array
    {
        if (is_link($path) || !is_file($path) || filesize($path)>1048576) { throw new \RuntimeException('Bisherige Geräteregistrierung ist nicht verfügbar.'); }
        $raw = file_get_contents($path);
        if ($raw===false) { throw new \RuntimeException('Bisherige Geräteregistrierung kann nicht gelesen werden.'); }
        $value = json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($value) || !is_array($value['tablets'] ?? null) || count($value['tablets'])>128) {
            throw new \RuntimeException('Bisherige Geräteregistrierung ist ungültig.');
        }
        return $value;
    }
    private static function file(string $source,string $target): void
    {
        if (is_link($source) || !is_file($source)) { throw new \RuntimeException('Bisherige private Datei ist kein regulärer Inhalt.'); }
        $before = hash_file('sha256',$source);
        if ($before===false || !copy($source,$target) || !chmod($target,0600)
            || !hash_equals($before,(string)hash_file('sha256',$target)) || !hash_equals($before,(string)hash_file('sha256',$source))) {
            throw new \RuntimeException('Private Datei wurde während der Übernahme verändert oder konnte nicht kopiert werden.');
        }
    }
    private static function data(string $source,string $target,array $ids): void
    {
        foreach ($ids as $id) {
            foreach (['state_','replay_'] as $prefix) {
                $file = $prefix.$id.'.json';
                if (file_exists($source.'/'.$file) || is_link($source.'/'.$file)) { self::file($source.'/'.$file,$target.'/'.$file); }
            }
            $history = $source.'/history_'.$id; self::directory($history);
            if (is_dir($history)) {
                $destination = $target.'/history_'.$id; mkdir($destination,0700);
                foreach (new \DirectoryIterator($history) as $entry) {
                    if ($entry->isDot()) { continue; }
                    if (preg_match('/^backup_[a-f0-9]{32}\.json$/D',$entry->getFilename())!==1) { throw new \RuntimeException('Unbekannter Inhalt im bisherigen Sicherungsordner.'); }
                    self::file($entry->getPathname(),$destination.'/'.$entry->getFilename());
                }
            }
        }
        $assets = $source.'/assets'; self::directory($assets);
        if (is_dir($assets)) {
            mkdir($target.'/assets',0700);
            foreach (new \DirectoryIterator($assets) as $entry) {
                if ($entry->isDot()) { continue; }
                if (preg_match('/^[a-f0-9]{32}$/D',$entry->getFilename())!==1) { throw new \RuntimeException('Unbekannter Inhalt im bisherigen Soundordner.'); }
                self::file($entry->getPathname(),$target.'/assets/'.$entry->getFilename());
            }
        }
    }
    private static function removeEmpty(string $path): void
    {
        if (!is_dir($path)) { return; }
        if (!self::emptyTarget($path)) { throw new \RuntimeException('Vorhandene neue Plugin-Daten werden nicht überschrieben.'); }
        foreach (new \DirectoryIterator($path) as $entry) { if (!$entry->isDot()) { self::removeEmpty($entry->getPathname()); } }
        if (!rmdir($path)) { throw new \RuntimeException('Leere Zielablage konnte nicht vorbereitet werden.'); }
    }
    private static function erase(string $path): void
    {
        foreach (new \DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) { continue; }
            if ($entry->isDir() && !$entry->isLink()) { self::erase($entry->getPathname()); } else { unlink($entry->getPathname()); }
        }
        rmdir($path);
    }
}
