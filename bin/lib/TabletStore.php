<?php
declare(strict_types=1);

namespace Loxi2;

/** Credentials are kept outside the web root, never returned in public device lists. */
final class TabletStore
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/');
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Plugin-Konfigurationsordner kann nicht angelegt werden.');
        }
        if ((fileperms($this->directory) & 0777) !== 0700) { @chmod($this->directory, 0700); }
    }

    public static function host(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException('Eine numerische private IPv4-Adresse des Tablets eingeben.');
        }
        $bytes = array_map('intval', explode('.', $host));
        if (!($bytes[0] === 10 || ($bytes[0] === 172 && $bytes[1] >= 16 && $bytes[1] <= 31)
            || ($bytes[0] === 192 && $bytes[1] === 168))) {
            throw new \InvalidArgumentException('Tablet-Adressen müssen im privaten Netz 10/8, 172.16/12 oder 192.168/16 liegen.');
        }
        return $host;
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        $value = $this->read('tablets.json', ['tablets' => []]);
        return isset($value['tablets']) && is_array($value['tablets']) ? $value['tablets'] : [];
    }

    public function get(string $id): array
    {
        self::id($id);
        $all = $this->all();
        if (!isset($all[$id])) { throw new \InvalidArgumentException('Tablet wurde nicht gefunden.'); }
        return $all[$id];
    }

    /** Keep tablet names and ownership stable throughout a publication or migration. */
    public function sharedLock()
    {
        $path=$this->directory.'/tablets.lock';
        if (is_link($path) || file_exists($path) && !is_file($path)) {
            throw new \RuntimeException('Tablet-Verwaltung kann nicht sicher gesperrt werden.');
        }
        $lock=fopen($path,'c');
        if ($lock===false) { throw new \RuntimeException('Tablet-Verwaltung kann nicht gesperrt werden.'); }
        if ((fileperms($path)&0777)!==0600 && !chmod($path,0600)) {
            fclose($lock); throw new \RuntimeException('Tablet-Sperre kann nicht geschützt werden.');
        }
        if (!flock($lock,LOCK_SH)) {
            fclose($lock); throw new \RuntimeException('Tablet-Verwaltung ist nicht verfügbar.');
        }
        return $lock;
    }

    public function save(string $id, string $name, string $host, string $token): string
    {
        if (preg_match('//u', $name) !== 1) {
            throw new \InvalidArgumentException('Tablet-Name muss gültiger UTF-8-Text sein.');
        }
        if (preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $name)) {
            throw new \InvalidArgumentException('Tablet-Name darf keine Steuerzeichen oder Zeilenumbrüche enthalten.');
        }
        $name = trim($name);
        if (strlen($name) > 160) {
            throw new \InvalidArgumentException('Tablet-Name ist ungültig oder länger als 160 UTF-8-Bytes.');
        }
        $host = self::host(trim($host));
        if (strlen($token) > 512 || preg_match('/[\x00-\x1f\x7f]/', $token)) {
            throw new \InvalidArgumentException('Token ist ungültig.');
        }
        if ($id !== '') { self::id($id); }
        return $this->mutate(function (array &$data) use ($id, $name, $host, $token): string {
            $all = $data['tablets'] ?? [];
            $key = $id === '' ? bin2hex(random_bytes(8)) : $id;
            if ($id !== '' && !isset($all[$id])) { throw new \InvalidArgumentException('Tablet wurde bereits entfernt.'); }
            $secret = $token === '' ? ($all[$key]['token'] ?? '') : $token;
            if ($secret === '') { throw new \InvalidArgumentException('Den in LOXi2DO gespeicherten Token eingeben.'); }
            foreach ($all as $other => $tablet) {
                if ((string) $other !== $key && $tablet['host'] === $host) {
                    throw new \InvalidArgumentException('Diese Tablet-Adresse ist bereits eingetragen.');
                }
            }
            if (!isset($all[$key]) && count($all) >= 128) { throw new \InvalidArgumentException('Höchstens 128 Tablets pro Plugin.'); }
            $all[$key] = ['id' => $key, 'name' => $name, 'host' => $host, 'token' => $secret];
            $data['tablets'] = $all;
            return $key;
        });
    }

    public function remove(string $id): void
    {
        self::id($id);
        $this->mutate(function (array &$data) use ($id): string { unset($data['tablets'][$id]); return ''; });
    }

    public static function publicDevice(array $device): array
    {
        unset($device['token']);
        return $device;
    }

    /** Empty dashboard names remain empty on the wire; the UI still needs a useful label. */
    public static function label(array $device): string
    {
        $name = is_string($device['name'] ?? null) ? trim($device['name']) : '';
        $host = is_string($device['host'] ?? null) ? trim($device['host']) : '';
        return $name !== '' ? $name : ($host !== '' ? $host : 'Tablet');
    }

    private static function id(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) { throw new \InvalidArgumentException('Tablet-ID ist ungültig.'); }
    }

    private function read(string $file, array $default): array
    {
        $path = $this->directory . '/' . $file;
        if (!is_file($path)) { return $default; }
        if (filesize($path) > 1048576) { throw new \RuntimeException('Plugin-Konfiguration ist zu groß.'); }
        $text = file_get_contents($path);
        if ($text === false) { throw new \RuntimeException('Plugin-Konfiguration kann nicht gelesen werden.'); }
        $result = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($result)) { throw new \RuntimeException('Plugin-Konfiguration ist ungültig.'); }
        return $result;
    }

    private function mutate(callable $change): string
    {
        $lock = fopen($this->directory . '/tablets.lock', 'c');
        if ($lock === false) { throw new \RuntimeException('Plugin-Konfiguration kann nicht gesperrt werden.'); }
        @chmod($this->directory . '/tablets.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) { throw new \RuntimeException('Plugin-Konfiguration ist gesperrt.'); }
            $data = $this->read('tablets.json', ['tablets' => []]);
            $result = $change($data);
            $temporary = tempnam($this->directory, '.tablets-');
            if ($temporary === false) { throw new \RuntimeException('Konfiguration kann nicht gespeichert werden.'); }
            try {
                chmod($temporary, 0600);
                $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
                if (file_put_contents($temporary, $encoded) !== strlen($encoded)
                    || !rename($temporary, $this->directory . '/tablets.json')) {
                    throw new \RuntimeException('Konfiguration konnte nicht atomar gespeichert werden.');
                }
            } finally { if (is_file($temporary)) { unlink($temporary); } }
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
