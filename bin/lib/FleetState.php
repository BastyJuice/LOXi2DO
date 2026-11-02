<?php
declare(strict_types=1);

namespace Loxi2;

require_once __DIR__ . '/ConfigForm.php';
require_once __DIR__ . '/TelemetryState.php';

/** Private per-tablet reported state and an offline-capable, revision-bound desired queue. */
final class FleetState
{
    private string $directory;
    private string $id;
    private array $retiredAssets = [];
    private array $retiredBackups = [];
    private array $createdFiles = [];
    private ?string $ramDirectory;
    private TelemetryState $telemetry;
    public function __construct(string $directory, string $id, ?string $ramDirectory = null)
    {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) { throw new \InvalidArgumentException('Ungültige Tablet-ID.'); }
        $this->directory = rtrim($directory, '/'); $this->id = $id;
        $this->telemetry=new TelemetryState($ramDirectory,$id);
        $this->ramDirectory = $ramDirectory === null ? null : rtrim($ramDirectory,'/') . '/management_cache';
        foreach ([$this->directory, $this->directory . '/assets', $this->directory . '/history_' . $this->id] as $path) {
            if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) { throw new \RuntimeException('Plugin-Datenordner kann nicht angelegt werden.'); }
            if ((fileperms($path) & 0777) !== 0700) { @chmod($path, 0700); }
        }
        if ($this->ramDirectory !== null) {
            if ((!is_dir($this->ramDirectory) && !@mkdir($this->ramDirectory,0700,true) && !is_dir($this->ramDirectory))
                || !is_writable($this->ramDirectory)) { $this->ramDirectory=null; }
            if ($this->ramDirectory!==null) { @chmod($this->ramDirectory,0700); }
        }
    }
    public function read(): array
    {
        $value=$this->readPersistent();
        $latest=$this->telemetry->read();
        if (is_array($latest['status'] ?? null) && ($latest['last_seen'] ?? 0)>=($value['last_seen'] ?? 0)) {
            $value['status']=$latest['status']; $value['last_seen']=$latest['last_seen'];
            $value['last_observed']=$latest['last_observed'] ?? $latest['last_seen'];
        }
        if (is_array($latest['mqtt'] ?? null)) { $value['mqtt']=$latest['mqtt']; }
        return $value;
    }
    private function readPersistent(): array
    {
        $file = $this->directory . '/state_' . $this->id . '.json';
        if (!is_file($file)) { return ['reported'=>null, 'pending'=>null, 'last_seen'=>null, 'result'=>null, 'history'=>[], 'sound_assets'=>[], 'issued_sound_jobs'=>[]]; }
        if (filesize($file) > 33554432) { throw new \RuntimeException('Tablet-Zustand ist zu groß.'); }
        $value = json_decode((string) file_get_contents($file), true, 48, JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new \RuntimeException('Tablet-Zustand ist ungültig.'); }
        return array_replace(['history'=>[], 'sound_assets'=>[], 'issued_sound_jobs'=>[]],$value);
    }
    public static function etag(array $state): string
    {
        return hash('sha256', "UI\n" . ($state['reported']['revision'] ?? '') . "\n" . ($state['pending']['id'] ?? ''));
    }
    public function snapshot(): array
    {
        return self::effective($this->read());
    }
    public function diagnostics(): array
    {
        $state=$this->read(); $result=['enabled'=>(bool)($state['reported']['settings']['diagnostics_enabled'] ?? false),'entries'=>''];
        if ($this->ramDirectory === null || !$result['enabled']) { return $result; }
        $file=$this->ramDirectory.'/diagnostics_'.$this->id.'.json';
        if (is_file($file) && filesize($file)<=262144) {
            $decoded=json_decode((string)file_get_contents($file),true);
            if (is_array($decoded)) { return $decoded; }
        }
        return $result;
    }
    public static function effective(array $state): array
    {
        if (!isset($state['reported']) || !is_array($state['reported'])) { throw new \RuntimeException('Dieses Tablet hat sich noch nicht gemeldet. Management auf dem Tablet aktivieren; die erste Konfigurationsprüfung erfolgt beim Start, danach zu jeder vollen Minute.'); }
        $snapshot = $state['reported'];
        $snapshot['tablet_revision'] = $snapshot['revision'];
        $snapshot['revision'] = self::etag($state);
        $snapshot['settings'] = array_replace($snapshot['settings'], $state['pending']['settings'] ?? []);
        $snapshot['actions'] = array_replace($snapshot['actions'], $state['pending']['actions'] ?? []);
        $snapshot['management'] = self::metadata($state);
        return $snapshot;
    }
    public static function metadata(array $state): array
    {
        return ['last_seen'=>$state['last_seen'] ?? null, 'pending_id'=>$state['pending']['id'] ?? null,
            'pending_base_revision'=>$state['pending']['base_revision'] ?? null,
            'pending_sounds'=>count($state['pending']['sounds'] ?? []), 'result'=>$state['result'] ?? null];
    }
    public function queue(string $revision, array $settings, array $actions = [], ?array $sound = null): array
    {
        return $this->mutate(function (array &$state) use ($revision, $settings, $actions, $sound): array {
            $this->checkRevision($state, $revision);
            $pending = $state['pending'] ?? ['settings'=>[], 'actions'=>[], 'sounds'=>[]];
            $pending['id'] = bin2hex(random_bytes(16));
            $pending['base_revision'] = $state['reported']['revision'];
            $pending['settings'] = array_replace($pending['settings'], $settings);
            $this->validateBatteryChange($state, $pending['settings']);
            $pending['actions'] = array_replace($pending['actions'], $actions);
            if ($sound !== null) {
                $keep = [];
                foreach ($pending['sounds'] as $old) {
                    if ($old['id'] === $sound['id']) { $this->deleteAsset($old['asset']); } else { $keep[] = $old; }
                }
                $keep[] = $sound; $pending['sounds'] = $keep;
            }
            $state['pending'] = $pending;
            $state['result'] = ['id'=>$pending['id'], 'state'=>'queued', 'error'=>'', 'time'=>time()];
            return self::effective($state);
        });
    }
    public function control(string $revision, bool $discard): array
    {
        return $this->mutate(function (array &$state) use ($revision, $discard): array {
            $this->checkRevision($state, $revision);
            if (empty($state['pending'])) { throw new \RuntimeException('Es gibt keine ausstehende Änderung.'); }
            if ($discard) {
                foreach ($state['pending']['sounds'] ?? [] as $sound) { $this->deleteAsset($sound['asset']); }
                $state['pending'] = null;
                $state['result'] = ['state'=>'discarded', 'error'=>'', 'time'=>time()];
            } else {
                $this->validateBatteryChange($state, $state['pending']['settings']);
                $state['pending']['id'] = bin2hex(random_bytes(16));
                $state['pending']['base_revision'] = $state['reported']['revision'];
                $state['result'] = ['id'=>$state['pending']['id'], 'state'=>'queued', 'error'=>'', 'time'=>time()];
            }
            return self::effective($state);
        });
    }
    public function sync(array $poll): ?array
    {
        $snapshot = $poll['snapshot'] ?? null;
        if (!is_array($snapshot) || ($snapshot['api'] ?? null) !== 1 || !is_string($snapshot['revision'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $snapshot['revision']) || !is_array($snapshot['settings'] ?? null)
            || !is_array($snapshot['actions'] ?? null)) { throw new \InvalidArgumentException('Tablet-Meldung ist unvollständig.'); }
        return $this->mutate(function (array &$state) use ($poll, $snapshot): ?array {
            $clean=$this->cleanSnapshot($snapshot);
            if (is_array($state['reported'] ?? null)) {
                if (self::fingerprint($state['reported'])!==self::fingerprint($clean)) { $this->capture($state,$state['reported'],'auto'); }
            } elseif (empty($state['history'])) { $this->capture($state,$clean,'auto','Erste Tablet-Meldung'); }
            $ack = $poll['ack'] ?? null;
            $ackId=is_array($ack) && is_string($ack['id'] ?? null) ? $ack['id'] : '';
            $matchesCurrent=$ackId!=='' && ($state['pending']['id'] ?? null)===$ackId;
            $issued=$state['issued_sound_jobs'][$ackId] ?? null;
            if ($matchesCurrent || is_array($issued)) {
                if (!in_array($ack['state'] ?? null,['applied','error','conflict'],true)) { throw new \InvalidArgumentException('Ungültige Bestätigung.'); }
                if ($ack['state']==='applied') {
                    $this->bindAppliedSounds($state,$clean,is_array($issued) ? $issued['sounds'] : ($state['pending']['sounds'] ?? []));
                }
                if (is_array($issued)) {
                    foreach ($issued['sounds'] as $sound) { $this->deleteAsset($sound['asset']); }
                    unset($state['issued_sound_jobs'][$ackId]);
                }
                if ($matchesCurrent) {
                    $message=isset($ack['error']) && is_string($ack['error']) ? substr($ack['error'],0,1000) : '';
                    $state['result']=['id'=>$ackId,'state'=>$ack['state'],'error'=>$message,'time'=>time()];
                    if ($ack['state']==='applied') {
                        foreach ($state['pending']['sounds'] ?? [] as $sound) { $this->deleteAsset($sound['asset']); }
                        $state['pending']=null;
                    }
                }
                // An older delivered job can acquire its archive binding without touching a newer desired job/result.
            }
            // Incoming reports never change pending.base_revision; conflicts need an explicit UI decision.
            $state['reported'] = $snapshot;
            $state['status'] = is_array($poll['status'] ?? null) ? $poll['status'] : [];
            $state['apps'] = is_array($poll['apps'] ?? null) ? $poll['apps'] : [];
            if ($this->ramDirectory !== null) {
                $diagnostics=is_array($poll['diagnostics'] ?? null) ? $poll['diagnostics'] : ['enabled'=>false,'entries'=>''];
                $encoded=json_encode($diagnostics,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
                if (strlen($encoded)<=262144) {
                    $temporary=tempnam($this->ramDirectory,'.diagnostics-');
                    if ($temporary!==false) {
                        chmod($temporary,0600);
                        if (file_put_contents($temporary,$encoded)===strlen($encoded)) { rename($temporary,$this->ramDirectory.'/diagnostics_'.$this->id.'.json'); }
                        if (is_file($temporary)) { unlink($temporary); }
                    }
                }
            }
            unset($state['diagnostics']);
            $state['last_seen'] = time();
            if (!empty($state['pending']['sounds'])) {
                $pendingId=$state['pending']['id'];
                if (!isset($state['issued_sound_jobs'][$pendingId])) {
                    $state['issued_sound_jobs'][$pendingId]=['sounds'=>$state['pending']['sounds'],'issued_at'=>time()];
                    while (count($state['issued_sound_jobs'])>7) {
                        $oldest=array_key_first($state['issued_sound_jobs']); $retired=$state['issued_sound_jobs'][$oldest];
                        foreach ($retired['sounds'] as $sound) { $this->deleteAsset($sound['asset']); }
                        unset($state['issued_sound_jobs'][$oldest]);
                    }
                }
            }
            return $state['pending'];
        });
    }
    public function stageSound(int $id, string $name, string $extension, string $revision, string $file): array
    {
        $size = filesize($file);
        if ($id < 0 || $id > 999999999 || trim($name) === '' || strlen($name) > 400 || preg_match('/[\x00-\x1f\x7f]/', $name)
            || !preg_match('/^[a-z0-9]{1,8}$/D', $extension) || $size === false || $size < 1 || $size > 52428800) {
            throw new \InvalidArgumentException('Sound benötigt eine gültige ID, Name, Endung und 1 Byte bis 50 MiB.');
        }
        $asset = bin2hex(random_bytes(16)); $path = $this->assetPath($asset);
        if (!copy($file, $path)) { throw new \RuntimeException('Sounddatei kann nicht bereitgestellt werden.'); }
        chmod($path,0600);
        try {
            $hash = hash_file('sha256',$path);
            if ($hash === false) { throw new \RuntimeException('Sounddatei kann nicht geprüft werden.'); }
            return $this->queue($revision, [], [], ['id'=>$id,'name'=>$name,'extension'=>$extension,'asset'=>$asset,'sha256'=>$hash,'bytes'=>$size]);
        } catch (\Throwable $error) { if (is_file($path)) { unlink($path); } throw $error; }
    }
    public function sound(string $asset, string $pendingId): string
    {
        $state = $this->read();
        $sounds=($state['pending']['id'] ?? null)===$pendingId
            ? ($state['pending']['sounds'] ?? []) : ($state['issued_sound_jobs'][$pendingId]['sounds'] ?? null);
        if (!is_array($sounds)) { throw new \InvalidArgumentException('Sound-Auftrag ist nicht mehr aktuell.'); }
        foreach ($sounds as $sound) {
            if ($sound['asset'] === $asset) {
                $path = $this->assetPath($asset);
                if (!is_file($path)) { throw new \RuntimeException('Sounddatei fehlt.'); }
                return $path;
            }
        }
        throw new \InvalidArgumentException('Sounddatei ist für dieses Tablet nicht freigegeben.');
    }
    public function history(): array
    {
        $state=$this->read(); $result=[];
        foreach ($state['history'] ?? [] as $entry) { $result[]=$this->historySummary($state,$entry); }
        return $result;
    }
    public function saveBackup(string $revision, string $label=''): array
    {
        if (strlen($label)>400 || preg_match('/[\x00-\x1f\x7f]/',$label)) { throw new \InvalidArgumentException('Backup-Bezeichnung ist ungültig.'); }
        return $this->mutate(function (array &$state) use ($revision,$label): array {
            $this->checkRevision($state,$revision);
            $entry=$this->capture($state,$state['reported'],'manual',trim($label));
            return $this->historySummary($state,$entry);
        });
    }
    public function restoreBackup(string $revision, string $backupId): array
    {
        return $this->mutate(function (array &$state) use ($revision,$backupId): array {
            $this->checkRevision($state,$revision); $this->requireNoPending($state);
            $entry=$this->findBackup($state,$backupId); $snapshot=$this->loadBackup($entry);
            return $this->queueRestoration($state,$snapshot,[],$backupId);
        });
    }
    public function exportBackup(string $backupId): array
    {
        $state=$this->read(); $entry=$this->findBackup($state,$backupId); $snapshot=$this->loadBackup($entry);
        $bindings=[];
        foreach ($snapshot['actions']['sound'] as $sound) {
            $known=$state['sound_assets'][$sound['file']] ?? null;
            if (is_array($known) && $this->assetUsable($known)) {
                $bindings[]=['id'=>$sound['id'],'name'=>$sound['name'],'extension'=>$known['extension'],'sha256'=>$known['sha256'],'bytes'=>$known['bytes']];
            }
        }
        $summary=$this->historySummary($state,$entry);
        return ['format'=>'loxi2do-config-backup','version'=>1,'created_at'=>$entry['created_at'],'source'=>$entry['source'],'label'=>$entry['label'],
            'snapshot'=>$snapshot,'audio_included'=>false,'sound_assets'=>$bindings,'restorable'=>$summary['restorable'],'integrity_checked'=>false,'missing_sounds'=>$summary['missing_sounds']];
    }
    public function importBackup(string $revision, array $document): array
    {
        $allowed=['format','version','created_at','source','label','snapshot','audio_included','sound_assets','restorable','integrity_checked','missing_sounds'];
        foreach (array_keys($document) as $key) { if (!in_array($key,$allowed,true)) { throw new \InvalidArgumentException('Unbekanntes Feld in der Backup-Datei.'); } }
        if (!in_array($document['format'] ?? null,['loxi2do-config-backup','loxi2hq-config-backup'],true) || ($document['version'] ?? null)!==1 || ($document['audio_included'] ?? null)!==false
            || !is_array($document['snapshot'] ?? null)) { throw new \InvalidArgumentException('Keine unterstützte LOXi2DO-Konfigurationssicherung.'); }
        if (isset($document['integrity_checked']) && !is_bool($document['integrity_checked'])) { throw new \InvalidArgumentException('Backup-Prüfstatus ist ungültig.'); }
        $snapshot=$this->cleanSnapshot($document['snapshot']);
        $bindings=$document['sound_assets'] ?? [];
        if (!is_array($bindings) || array_values($bindings)!==$bindings || count($bindings)>256) { throw new \InvalidArgumentException('Sound-Metadaten im Backup sind ungültig.'); }
        $seen=[]; $soundRows=[];
        foreach ($snapshot['actions']['sound'] as $row) { $soundRows[$row['id']]=$row; }
        foreach ($bindings as $binding) {
            if (!is_array($binding) || array_diff(array_keys($binding),['id','name','extension','sha256','bytes'])
                || !is_int($binding['id'] ?? null) || $binding['id']<0 || $binding['id']>999999999 || isset($seen[$binding['id']])
                || !is_string($binding['name'] ?? null) || strlen($binding['name'])>400 || !is_string($binding['extension'] ?? null)
                || !preg_match('/^[a-z0-9]{1,8}$/D',$binding['extension']) || !is_string($binding['sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D',$binding['sha256']) || !is_int($binding['bytes'] ?? null)
                || $binding['bytes']<1 || $binding['bytes']>52428800) { throw new \InvalidArgumentException('Sound-Metadaten im Backup sind ungültig.'); }
            if (!isset($soundRows[$binding['id']]) || pathinfo($soundRows[$binding['id']]['file'],PATHINFO_EXTENSION)!==$binding['extension']) { throw new \InvalidArgumentException('Sound-Metadaten gehören nicht zu den gesicherten Sound-IDs.'); }
            $seen[$binding['id']]=true;
        }
        return $this->mutate(function (array &$state) use ($revision,$snapshot,$bindings): array {
            $this->checkRevision($state,$revision); $this->requireNoPending($state);
            return $this->queueRestoration($state,$snapshot,$bindings,null);
        });
    }
    private function queueRestoration(array &$state, array $snapshot, array $bindings, ?string $backupId): array
    {
        // Retain archived bytes and semantics. Only the delivery patch omits the retired
        // three-key policy when the target already uses independent V2 controls.
        $batteryNotice=null;
        if (ConfigForm::supportsBatteryManagement($state['reported'] ?? [])
            && array_intersect(array_keys(ConfigForm::legacyBatteryFields()),array_keys($snapshot['settings']))) {
            foreach (array_keys(ConfigForm::legacyBatteryFields()) as $key) { unset($snapshot['settings'][$key]); }
            $batteryNotice=ConfigForm::LEGACY_BATTERY_RESTORE_NOTICE;
        }
        $this->validateBatteryChange($state, $snapshot['settings']);
        $current=$this->currentFiles($state); $actions=$snapshot['actions']; $downloads=[]; $usedAssets=[]; $metadata=[];
        foreach ($bindings as $binding) { $metadata[$binding['id']]=$binding; }
        foreach ($actions['sound'] as $index=>$sound) {
            // Local filenames are usable only when this exact private file is still reported by this tablet.
            $known=$state['sound_assets'][$sound['file']] ?? null;
            if (isset($current[$sound['file']]) && !isset($metadata[$sound['id']])) { continue; }
            if (isset($current[$sound['file']]) && isset($metadata[$sound['id']]) && is_array($known)) {
                $provided=$metadata[$sound['id']];
                if ($known['sha256']===$provided['sha256'] && $known['bytes']===$provided['bytes'] && $known['extension']===$provided['extension']) { continue; }
            }
            if (isset($metadata[$sound['id']])) {
                $provided=$metadata[$sound['id']]; $known=null;
                foreach ($state['sound_assets'] ?? [] as $candidate) {
                    if ($candidate['sha256']===$provided['sha256'] && $candidate['bytes']===$provided['bytes'] && $candidate['extension']===$provided['extension'] && $this->assetUsable($candidate)) { $known=$candidate; break; }
                }
            }
            if (!is_array($known) || !$this->assetUsable($known) || !hash_equals($known['sha256'],(string)hash_file('sha256',$this->assetPath($known['asset'])))) {
                throw new \RuntimeException('Sound ID '.$sound['id'].' ('.$sound['name'].') ist nicht mehr auf dem Tablet vorhanden und wurde nicht in LOXi2DO archiviert. Audiodatei zuerst erneut bereitstellen; Restore wurde nicht vorgemerkt.');
            }
            if (isset($usedAssets[$known['asset']])) {
                // Android binds one unique asset to one sound ID in a deployment.
                $clone=bin2hex(random_bytes(16)); $path=$this->assetPath($clone);
                if (!copy($this->assetPath($known['asset']),$path)) { throw new \RuntimeException('Archivierte Audiodatei kann nicht bereitgestellt werden.'); }
                chmod($path,0600); $this->createdFiles[]=$path; $known['asset']=$clone;
            }
            $usedAssets[$known['asset']]=true;
            $downloads[]=['id'=>$sound['id'],'name'=>$sound['name'],'extension'=>$known['extension'],'asset'=>$known['asset'],'sha256'=>$known['sha256'],'bytes'=>$known['bytes']];
        }
        $pending=['id'=>bin2hex(random_bytes(16)),'base_revision'=>$state['reported']['revision'],'settings'=>$snapshot['settings'],'actions'=>$actions,'sounds'=>$downloads];
        $state['pending']=$pending;
        $state['result']=['id'=>$pending['id'],'state'=>'queued','error'=>'','time'=>time(),'operation'=>'restore'];
        if ($batteryNotice!==null) { $state['result']['warning']=$batteryNotice; }
        if ($backupId!==null) { $state['result']['backup_id']=$backupId; }
        return self::effective($state);
    }
    private function requireNoPending(array $state): void
    {
        if (!empty($state['pending'])) { throw new \RuntimeException('Es gibt bereits eine vorgemerkte Änderung. Diese zuerst übernehmen lassen oder bewusst verwerfen.'); }
    }
    private function findBackup(array $state, string $backupId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$backupId)) { throw new \InvalidArgumentException('Ungültige Backup-ID.'); }
        foreach ($state['history'] ?? [] as $entry) { if (($entry['id'] ?? null)===$backupId) { return $entry; } }
        throw new \InvalidArgumentException('Diese Sicherung ist nicht mehr unter den letzten sieben Ständen vorhanden.');
    }
    private function historySummary(array $state, array $entry): array
    {
        $result=['id'=>$entry['id'],'created_at'=>$entry['created_at'],'source'=>$entry['source'],'label'=>$entry['label'],'revision'=>$entry['revision'],
            'current'=>false,'settings_count'=>0,'action_counts'=>[],'sound_count'=>0,'restorable'=>false,'integrity_checked'=>false,'missing_sounds'=>[]];
        try {
            $snapshot=$this->loadBackup($entry); $current=$this->currentFiles($state);
            $result['current']=isset($state['reported']) && self::fingerprint($snapshot)===self::fingerprint($state['reported']);
            $result['settings_count']=count($snapshot['settings']);
            foreach ($snapshot['actions'] as $kind=>$rows) { $result['action_counts'][$kind]=count($rows); }
            $result['sound_count']=count($snapshot['actions']['sound']);
            foreach ($snapshot['actions']['sound'] as $sound) {
                if (!isset($current[$sound['file']]) && !$this->assetUsable($state['sound_assets'][$sound['file']] ?? null)) {
                    $result['missing_sounds'][]=['id'=>$sound['id'],'name'=>$sound['name'],'file'=>$sound['file'],'reason'=>'Audiodatei weder aktuell auf dem Tablet noch im LOXi2DO-Archiv verfügbar.'];
                }
            }
            $result['restorable']=count($result['missing_sounds'])===0;
        } catch (\Throwable $missing) { $result['unavailable_reason']='Sicherungsdatei ist nicht verfügbar oder ungültig.'; }
        return $result;
    }
    private function capture(array &$state, array $snapshot, string $source, string $label=''): array
    {
        $snapshot=$this->cleanSnapshot($snapshot); $fingerprint=self::fingerprint($snapshot); $existing=null; $keep=[];
        foreach ($state['history'] ?? [] as $entry) {
            if (($entry['fingerprint'] ?? null)===$fingerprint) { $existing=$entry; } else { $keep[]=$entry; }
        }
        if ($existing===null) {
            $id=bin2hex(random_bytes(16)); $path=$this->backupPath($id);
            $encoded=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if (strlen($encoded)>16777216) { throw new \RuntimeException('Ein Sicherungsstand ist größer als 16 MiB.'); }
            $this->atomicWrite($path,$encoded); $this->createdFiles[]=$path;
            $existing=['id'=>$id,'revision'=>$snapshot['revision'],'fingerprint'=>$fingerprint,'created_at'=>time(),'source'=>$source,'label'=>$label,
                'sound_files'=>array_values(array_unique(array_column($snapshot['actions']['sound'],'file')))];
        } else {
            $existing['created_at']=time();
            if ($source==='manual') { $existing['source']='manual'; $existing['label']=$label; }
        }
        array_unshift($keep,$existing);
        while (count($keep)>7) { $removed=array_pop($keep); $this->retiredBackups[]=$this->backupPath($removed['id']); }
        $state['history']=$keep;
        return $existing;
    }
    private function loadBackup(array $entry): array
    {
        $path=$this->backupPath($entry['id']);
        if (!is_file($path) || filesize($path)>16777216) { throw new \RuntimeException('Sicherungsdatei ist nicht verfügbar oder zu groß.'); }
        $value=json_decode((string)file_get_contents($path),true,48,JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new \RuntimeException('Sicherungsdatei ist ungültig.'); }
        return $this->cleanSnapshot($value);
    }
    private function backupPath(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) { throw new \InvalidArgumentException('Ungültige Backup-ID.'); }
        return $this->directory.'/history_'.$this->id.'/backup_'.$id.'.json';
    }
    private function cleanSnapshot(array $snapshot): array
    {
        foreach (array_keys($snapshot) as $key) { if (!in_array($key,['api','ok','revision','settings','actions','capabilities'],true)) { throw new \InvalidArgumentException('Backup enthält unerlaubte Konfigurationsfelder.'); } }
        if (($snapshot['api'] ?? null)!==1 || !is_string($snapshot['revision'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$snapshot['revision'])
            || !is_array($snapshot['settings'] ?? null) || !is_array($snapshot['actions'] ?? null)) { throw new \InvalidArgumentException('Backup-Konfiguration ist unvollständig.'); }
        $known=[];
        foreach (ConfigForm::groups() as $group) { foreach ($group[1] as $key=>$field) { $known[$key]=$field; } }
        $required = array_diff_key($known, array_flip(ConfigForm::batteryKeys()));
        $known = array_replace($known, ConfigForm::legacyBatteryFields());
        if (array_diff(array_keys($snapshot['settings']),array_keys($known)) || array_diff(array_keys($required),array_keys($snapshot['settings']))) {
            throw new \InvalidArgumentException('Backup muss genau die unterstützten App-Einstellungen enthalten; Token, Vertrauensfreigaben und Laufzeitdaten sind ausgeschlossen.');
        }
        ConfigForm::validateBatterySettings($snapshot['settings']);
        foreach ($known as $key=>$field) {
            if (!array_key_exists($key, $snapshot['settings'])) { continue; }
            $value=$snapshot['settings'][$key];
            if ($field[1]==='bool') { if (!is_bool($value)) { throw new \InvalidArgumentException('Backup-Schalter hat einen ungültigen Typ.'); } }
            elseif ($field[1]==='number') { if (!is_int($value) || $value<$field[2] || $value>$field[3]) { throw new \InvalidArgumentException('Backup-Zahl ist ungültig.'); } }
            elseif (!is_string($value) || strlen($value)>262144 || strpos($value,"\0")!==false
                || ($field[1]==='select' && !array_key_exists($value,$field[2]))) { throw new \InvalidArgumentException('Backup-Text ist ungültig.'); }
        }
        $kinds=['sound','notification','speech','alert','intent'];
        if (array_diff(array_keys($snapshot['actions']),$kinds) || array_diff($kinds,array_keys($snapshot['actions']))) { throw new \InvalidArgumentException('Backup-Aktionsgruppen sind unvollständig.'); }
        $soundIds=[];
        foreach ($snapshot['actions'] as $kind=>$rows) {
            if (!is_array($rows) || array_values($rows)!==$rows || count($rows)>256) { throw new \InvalidArgumentException('Backup-Aktionsliste ist ungültig.'); }
            $seen=[];
            $allowed=['id','name'];
            if ($kind==='sound') { $allowed[]='file'; }
            elseif ($kind==='speech') { $allowed[]='text'; }
            elseif ($kind==='notification' || $kind==='alert') { $allowed=array_merge($allowed,['title','text','sound']); }
            else { $allowed=array_merge($allowed,['package','action','mode','data','mime','extras']); }
            foreach ($rows as $row) {
                if (!is_array($row) || array_diff(array_keys($row),$allowed) || array_diff($allowed,array_keys($row))
                    || !is_int($row['id'] ?? null) || $row['id']<0 || $row['id']>999999999 || isset($seen[$row['id']])) { throw new \InvalidArgumentException('Backup-Aktions-ID oder Felder sind ungültig.'); }
                $seen[$row['id']]=true;
                foreach ($row as $key=>$value) {
                    if ($key==='id') { continue; }
                    if ($key==='sound') { if (!is_int($value) || $value< -1 || $value>999999999) { throw new \InvalidArgumentException('Backup-Soundreferenz ist ungültig.'); } continue; }
                    if (!is_string($value) || strpos($value,"\0")!==false || strlen($value)>32768) { throw new \InvalidArgumentException('Backup-Aktionsfeld ist ungültig.'); }
                }
                if (trim($row['name'])==='' || strlen($row['name'])>400) { throw new \InvalidArgumentException('Backup-Aktionsname ist ungültig.'); }
                if ($kind==='sound') {
                    if (!preg_match('/^audio_[a-f0-9]{32}\.[a-z0-9]{1,8}$/D',$row['file'])) { throw new \InvalidArgumentException('Backup enthält keinen gültigen privaten Soundnamen.'); }
                    $soundIds[$row['id']]=true;
                }
                if ($kind==='intent' && (!in_array($row['mode'],['activity','broadcast'],true)
                    || !preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/D',$row['package'])
                    || $row['package']==='de.loxi2' || !preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/D',$row['action']))) {
                    throw new \InvalidArgumentException('Backup-Intent ist ungültig.');
                }
            }
        }
        foreach (['notification','alert'] as $kind) { foreach ($snapshot['actions'][$kind] as $row) {
            if ($row['sound']>=0 && !isset($soundIds[$row['sound']])) { throw new \InvalidArgumentException('Backup verweist auf einen fehlenden Sound.'); }
        } }
        return ['api'=>1,'revision'=>$snapshot['revision'],'settings'=>$snapshot['settings'],'actions'=>$snapshot['actions']];
    }
    /** Never reinterpret a legacy percentage-mode preference as an external charging command. */
    private function validateBatteryChange(array $state, array $changes): void
    {
        $reported=$state['reported'] ?? []; $settings=$reported['settings'] ?? [];
        $new=array_intersect(ConfigForm::batteryKeys(),array_keys($changes));
        $legacy=array_intersect(array_keys(ConfigForm::legacyBatteryFields()),array_keys($changes));
        if (!$new && !$legacy) { return; }
        if ($new) {
            if ($legacy || !ConfigForm::supportsBatteryManagement($reported)) {
                throw new \RuntimeException('Die neue Akku-Steuerung benötigt die aktuelle APK und einen erfolgreichen Managementabgleich.');
            }
            $merged=array_replace($settings,$changes); ConfigForm::validateBatterySettings($merged);
            foreach (['battery_care_mode_enabled','charge_control_disable_care_while_charging'] as $key) {
                if (array_key_exists($key,$changes) && $changes[$key]===true
                    && ($reported['capabilities']['battery_care_supported'] ?? false)!==true) {
                    throw new \RuntimeException('Dieses Tablet meldet keinen unterstützten Fire-Akkuschonmodus. Die externe Ladebegrenzung bleibt verfügbar.');
                }
            }
            return;
        }
        if (ConfigForm::supportsBatteryManagement($reported)) {
            throw new \RuntimeException('Diese Sicherung verwendet die frühere Akku-Prozentautomatik. Sie wird nicht in HTTP-Ladebefehle umgedeutet. Dauerbetrieb zuerst mit den neuen Einstellungen konfigurieren.');
        }
        if (($reported['capabilities']['battery_care_settings'] ?? false)!==true
            || count(array_intersect(array_keys(ConfigForm::legacyBatteryFields()),array_keys($settings)))!==3) {
            throw new \RuntimeException('Dieses Tablet unterstützt die früheren Akku-Einstellungen nicht.');
        }
        $merged=array_replace($settings,$changes); ConfigForm::validateBatterySettings($merged);
        if ($merged['battery_care_enabled'] && ($reported['capabilities']['battery_care_supported'] ?? false)!==true) {
            throw new \RuntimeException('Die gemeldete Tablet-Firmware unterstützt den Amazon-Akkuschonmodus nicht.');
        }
    }
    private static function canonical($value): string
    {
        if (is_array($value)) {
            if ($value!==[] && array_values($value)!==$value) { ksort($value,SORT_STRING); }
            $parts=[];
            foreach ($value as $key=>$item) { $parts[$key]=json_decode(self::canonical($item),true,48,JSON_THROW_ON_ERROR); }
            return json_encode($parts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }
        return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    private static function fingerprint(array $snapshot): string
    {
        return hash('sha256',self::canonical(['settings'=>$snapshot['settings'],'actions'=>$snapshot['actions']]));
    }
    private function currentFiles(array $state): array
    {
        $files=[];
        foreach ($state['reported']['actions']['sound'] ?? [] as $sound) {
            if (is_array($sound) && is_string($sound['file'] ?? null)) { $files[$sound['file']]=true; }
        }
        return $files;
    }
    private function assetUsable($metadata): bool
    {
        return is_array($metadata) && is_string($metadata['asset'] ?? null) && preg_match('/^[a-f0-9]{32}$/D',$metadata['asset'])
            && is_int($metadata['bytes'] ?? null) && $metadata['bytes']>0 && $metadata['bytes']<=52428800
            && is_string($metadata['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D',$metadata['sha256'])
            && is_string($metadata['extension'] ?? null) && preg_match('/^[a-z0-9]{1,8}$/D',$metadata['extension'])
            && is_file($this->assetPath($metadata['asset'])) && filesize($this->assetPath($metadata['asset']))===$metadata['bytes'];
    }
    private function bindAppliedSounds(array &$state,array $snapshot,array $sounds): void
    {
        foreach ($sounds as $sound) {
            foreach ($snapshot['actions']['sound'] as $actual) {
                if ($actual['id']===$sound['id'] && preg_match('/^audio_[a-f0-9]{32}\\.' . preg_quote($sound['extension'],'/') . '$/D',$actual['file'])) {
                    $state['sound_assets'][$actual['file']]=$sound;
                }
            }
        }
    }
    private function pruneAssets(array &$state): void
    {
        $references=$this->currentFiles($state);
        foreach ($state['history'] ?? [] as $entry) { foreach ($entry['sound_files'] ?? [] as $file) { $references[$file]=true; } }
        foreach ($state['pending']['actions']['sound'] ?? [] as $sound) { if (is_string($sound['file'] ?? null)) { $references[$sound['file']]=true; } }
        foreach ($state['sound_assets'] ?? [] as $file=>$metadata) {
            if (!isset($references[$file])) { $this->deleteAsset($metadata['asset']); unset($state['sound_assets'][$file]); }
        }
    }
    private function referencedAssets(array $state): array
    {
        $keep=[];
        foreach ($state['sound_assets'] ?? [] as $metadata) { if (is_string($metadata['asset'] ?? null)) { $keep[$metadata['asset']]=true; } }
        foreach ($state['pending']['sounds'] ?? [] as $metadata) { if (is_string($metadata['asset'] ?? null)) { $keep[$metadata['asset']]=true; } }
        foreach ($state['issued_sound_jobs'] ?? [] as $job) {
            foreach ($job['sounds'] as $metadata) { if (is_string($metadata['asset'] ?? null)) { $keep[$metadata['asset']]=true; } }
        }
        return $keep;
    }
    private function atomicWrite(string $path,string $encoded): void
    {
        $temporary=tempnam(dirname($path),'.backup-');
        if ($temporary===false) { throw new \RuntimeException('Sicherungsdatei kann nicht erstellt werden.'); }
        try {
            chmod($temporary,0600);
            if (file_put_contents($temporary,$encoded)!==strlen($encoded) || !rename($temporary,$path)) { throw new \RuntimeException('Sicherungsdatei konnte nicht atomar gespeichert werden.'); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }
    private function assetPath(string $asset): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $asset)) { throw new \InvalidArgumentException('Ungültige Sound-ID.'); }
        return $this->directory . '/assets/' . $asset;
    }
    private function deleteAsset(string $asset): void { $this->retiredAssets[] = $this->assetPath($asset); }
    private function checkRevision(array $state, string $revision): void
    {
        if (empty($state['reported']) || !hash_equals(self::etag($state), $revision)) { throw new \RuntimeException('Diese Ansicht ist veraltet. Den gemeldeten Tablet-Stand erneut laden und die Änderung prüfen.'); }
    }
    private function mutate(callable $change)
    {
        $this->retiredAssets = []; $this->retiredBackups=[]; $this->createdFiles=[]; $committed=false;
        $lockPath = $this->directory . '/state_' . $this->id . '.lock';
        $lock = fopen($lockPath,'c');
        if ($lock === false) { throw new \RuntimeException('Tablet-Zustand kann nicht gesperrt werden.'); }
        chmod($lockPath,0600);
        try {
            if (!flock($lock,LOCK_EX)) { throw new \RuntimeException('Tablet-Zustand ist gesperrt.'); }
            // Fast telemetry lives only in RAM. Browser/configuration mutations must never persist it.
            $state = $this->readPersistent(); $result = $change($state);
            $this->pruneAssets($state); $keepAssets=$this->referencedAssets($state);
            $encoded = json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
            if (strlen($encoded)>33554432) { throw new \RuntimeException('Tablet-Zustand ist größer als 32 MiB.'); }
            $temporary = tempnam($this->directory,'.state-');
            if ($temporary === false) { throw new \RuntimeException('Tablet-Zustand kann nicht gespeichert werden.'); }
            try {
                chmod($temporary,0600);
                if (file_put_contents($temporary,$encoded)!==strlen($encoded) || !rename($temporary,$this->directory.'/state_'.$this->id.'.json')) { throw new \RuntimeException('Tablet-Zustand konnte nicht atomar gespeichert werden.'); }
            } finally { if (is_file($temporary)) { unlink($temporary); } }
            $committed=true;
            foreach ($this->retiredAssets as $retired) { if (!isset($keepAssets[basename($retired)]) && is_file($retired)) { @unlink($retired); } }
            foreach ($this->retiredBackups as $retired) { if (is_file($retired)) { @unlink($retired); } }
            $this->retiredAssets = [];
            return $result;
        } finally {
            if (!$committed) { foreach ($this->createdFiles as $created) { if (is_file($created)) { @unlink($created); } } }
            flock($lock,LOCK_UN); fclose($lock);
        }
    }
}
