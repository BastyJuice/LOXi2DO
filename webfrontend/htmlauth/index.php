<?php
declare(strict_types=1);

$explicitDeviceRequested=isset($_GET['device']) && is_string($_GET['device']) && $_GET['device']!=='';

require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once $lbpbindir . '/lib/UpgradeStorage.php';
try { $upgradeRequest=Loxi2\UpgradeStorage::request($lbpdatadir); }
catch (Throwable $upgrading) {
    http_response_code(503); header('Retry-After: 30'); header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'LOXi2DO wird aktualisiert. Bitte später erneut versuchen.'; exit;
}
require_once $lbpbindir . '/lib/TabletStore.php';
require_once $lbpbindir . '/lib/RemoteClient.php';
require_once $lbpbindir . '/lib/ConfigForm.php';
require_once $lbpbindir . '/lib/RamStorage.php';
require_once $lbpbindir . '/lib/FleetOverview.php';
require_once $lbpbindir . '/lib/MqttTopics.php';

use Loxi2\TabletStore;
use Loxi2\RemoteClient;
use Loxi2\ConfigForm;
use Loxi2\FleetState;
use Loxi2\RamStorage;

$ramDirectory=RamStorage::choose($lbplogdir);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('loxi2do_session');
    session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax']);
    session_start();
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
if (!isset($_SESSION['loxi2do_csrf'])) { $_SESSION['loxi2do_csrf'] = bin2hex(random_bytes(32)); }

function h($value): string { return htmlspecialchars(is_scalar($value) || $value === null ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function post(string $name, string $default = ''): string {
    $value = $_POST[$name] ?? $default;
    if (!is_string($value)) { throw new InvalidArgumentException('Ungültiger Formularwert.'); }
    return $value;
}
function query(string $name, string $default = ''): string { $value = $_GET[$name] ?? $default; return is_string($value) ? $value : $default; }
function csrf(): void { echo '<input type="hidden" name="csrf" value="' . h($_SESSION['loxi2do_csrf']) . '">'; }
function hidden(string $name, string $value): void { echo '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">'; }
function redacted(Throwable $error, array $tablets): string {
    $message = $error->getMessage();
    foreach ($tablets as $tablet) { if (!empty($tablet['token'])) { $message = str_replace($tablet['token'], '[Token]', $message); } }
    return substr($message, 0, 1500);
}
function remember(string $id, array $snapshot, bool $cache = true): void {
    if (($snapshot['api'] ?? null) !== 1 || !isset($snapshot['revision']) || !is_string($snapshot['revision'])
        || !preg_match('/^[a-f0-9]{64}$/D', $snapshot['revision']) || !isset($snapshot['settings'], $snapshot['actions'])
        || !is_array($snapshot['settings']) || !is_array($snapshot['actions'])) {
        throw new RuntimeException('Tablet-Konfiguration ist unvollständig oder verwendet eine unbekannte API-Version.');
    }
    if (!$cache) { return; }
    unset($_SESSION['loxi2do_snapshots'][$id]);
    $_SESSION['loxi2do_snapshots'][$id] = $snapshot;
    while (count($_SESSION['loxi2do_snapshots']) > 2) { array_shift($_SESSION['loxi2do_snapshots']); }
}
function cached(string $id, string $revision): array {
    $snapshot = $_SESSION['loxi2do_snapshots'][$id] ?? null;
    if (!is_array($snapshot) || !hash_equals($snapshot['revision'], $revision)) {
        throw new RuntimeException('Diese Ansicht ist nicht mehr aktuell. Den gemeldeten Tablet-Stand erneut laden und die Änderung prüfen.');
    }
    return $snapshot;
}
function batteryCareEditable(array $snapshot): bool {
    return ConfigForm::supportsBatteryManagement($snapshot);
}
function fireCareEditable(array $snapshot): bool {
    return batteryCareEditable($snapshot) && ($snapshot['capabilities']['battery_care_supported'] ?? false)===true;
}
function editableSettings(array $snapshot): array {
    $settings=$snapshot['settings'];
    foreach (array_keys(ConfigForm::legacyBatteryFields()) as $key) { unset($settings[$key]); }
    if (!batteryCareEditable($snapshot)) {
        foreach (ConfigForm::batteryKeys() as $key) { unset($settings[$key]); }
    } elseif (!fireCareEditable($snapshot)) {
        unset($settings['battery_care_mode_enabled'],$settings['charge_control_disable_care_while_charging']);
    }
    return $settings;
}
function targets(): array {
    $values = $_POST['targets'] ?? [];
    if (!is_array($values) || count($values) < 1 || count($values) > 8) { throw new InvalidArgumentException('Ein bis acht Ziel-Tablets auswählen.'); }
    foreach ($values as $id) { if (!is_string($id) || !preg_match('/^[a-f0-9]{16}$/D', $id)) { throw new InvalidArgumentException('Ungültige Tablet-Auswahl.'); } }
    return array_values(array_unique($values));
}
function listPost(string $name): array {
    $values = $_POST[$name] ?? [];
    if (!is_array($values) || count($values) > 32) { throw new InvalidArgumentException('Ungültige Gruppenauswahl.'); }
    foreach ($values as $value) { if (!is_string($value)) { throw new InvalidArgumentException('Ungültige Gruppenauswahl.'); } }
    return array_values(array_unique($values));
}
function stateLabel(array $meta): string {
    $result = $meta['result']['state'] ?? '';
    if (!empty($meta['pending_id'])) {
        if ($result === 'conflict') { return 'Konflikt – Entscheidung nötig'; }
        if ($result === 'error') { return 'Fehler – Änderung ausstehend'; }
        return 'Änderung vorgemerkt';
    }
    if ($result === 'applied') { return 'Übernommen'; }
    if ($result === 'discarded') { return 'Vormerkung verworfen'; }
    return 'Keine Änderung ausstehend';
}
/** Read-only admin view; neither polling nor opening this panel queues configuration. */
function liveStatus(array $device, string $dataDirectory, ?string $ramDirectory): array {
    $state = (new FleetState($dataDirectory, $device['id'], $ramDirectory))->read();
    return ['ok'=>true, 'device'=>TabletStore::publicDevice($device), 'server_time'=>time(),
        'last_seen'=>$state['last_seen'] ?? null, 'last_observed'=>$state['last_observed'] ?? null, 'reported'=>$state['status'] ?? [],
        'mqtt'=>$state['mqtt'] ?? null, 'management'=>FleetState::metadata($state)];
}

$store = null;
$tablets = [];
$message = '';
$error = '';
$results = [];
$status = null;
$installedApps = null;
$diagnostics = null;
$requestedForm=query('form','dashboard');
$form=$requestedForm==='tablets' ? 'config' : $requestedForm;
if (!in_array($form, ['dashboard', 'config', 'actions', 'backups', 'diagnostics', 'help'], true)) { $form = 'dashboard'; }
$showTabletDialog=$requestedForm==='tablets' && $explicitDeviceRequested;
$deviceId = query('device');
$kind = query('kind', 'sound');
$kinds = ['sound' => 'Sounds', 'notification' => 'Mitteilungen', 'speech' => 'Sprachtexte', 'alert' => 'Alerts', 'intent' => 'Intents'];
if (!isset($kinds[$kind])) { $kind = 'sound'; }
$editAction = null;
$deviceDraft = null;
$settingsDraft = null;
$settingGroups=ConfigForm::groups();
$requestedActiveGroup=$_POST['active_group'] ?? query('group','network');
$activeSettingsGroup=is_string($requestedActiveGroup) && isset($settingGroups[$requestedActiveGroup]) ? $requestedActiveGroup : 'network';
$batteryCareStatus = null;
$backups = [];
$backupDetail = null;
$backupId = query('backup');
$live = null;

try {
    $store = new TabletStore($lbpconfigdir);
    $tablets = $store->all();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && query('operation') === 'overview') {
        header('Content-Type: application/json; charset=utf-8');
        $value=['ok'=>true]+Loxi2\FleetOverview::snapshot($tablets,$lbpdatadir,$ramDirectory);
        session_write_close();
        echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && query('operation') === 'telemetry') {
        // LoxBerry's htmlauth protects this endpoint. GET is read-only and never returns tokens.
        header('Content-Type: application/json; charset=utf-8');
        try {
            $value=liveStatus($store->get($deviceId), $lbpdatadir, $ramDirectory);
        } catch (Throwable $exception) {
            http_response_code(404); $value=['ok'=>false, 'error'=>redacted($exception,$tablets)];
        }
        session_write_close();
        echo json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!hash_equals($_SESSION['loxi2do_csrf'], post('csrf'))) { throw new RuntimeException('Sitzung abgelaufen. Seite neu laden und erneut versuchen.'); }
        @set_time_limit(600);
        $action = post('action');
        $deviceId = post('device');
        if ($action === 'save_tablet') {
            $deviceDraft = ['id' => $deviceId, 'name' => post('name'), 'host' => post('host')];
            $deviceId = $store->save($deviceId, post('name'), post('host'), post('token'));
            $tablets = $store->all(); $deviceDraft = null; $message = 'Tablet-Zugang gespeichert. Token wird nicht im Browser angezeigt.'; $form = 'config'; $showTabletDialog=false;
        } elseif ($action === 'remove_tablet') {
            $store->remove($deviceId); unset($_SESSION['loxi2do_snapshots'][$deviceId]);
            $tablets = $store->all(); $deviceId = ''; $message = 'Tablet aus LOXi2DO entfernt.'; $form = 'config';
        } elseif ($action === 'bulk' || $action === 'upload') {
            $selected = targets();
            foreach ($selected as $id) { $store->get($id); }
            if ($action === 'bulk') {
                $source = cached($deviceId, post('revision'));
                $groups = listPost('groups');
                if (in_array('battery', $groups, true) && !batteryCareEditable($source)) {
                    throw new InvalidArgumentException('Das Quell-Tablet meldet noch nicht die neue Akku-Steuerung.');
                }
                $settings = ConfigForm::selectedSettings($source['settings'], $groups);
                $actions = [];
                foreach (listPost('action_groups') as $category) {
                    if (!in_array($category, ['notification', 'speech', 'alert', 'intent'], true)) { throw new InvalidArgumentException('Sounds können nur durch einen Datei-Upload auf andere Tablets übertragen werden.'); }
                    $actions[$category] = $source['actions'][$category] ?? [];
                }
                if (!$settings && !$actions) { throw new InvalidArgumentException('Mindestens eine Einstellungs- oder Aktionsgruppe auswählen.'); }
                foreach ($selected as $id) {
                    $target = $store->get($id);
                    try {
                        if ($id === $deviceId) { $results[] = [TabletStore::label($target), true, 'Quell-Tablet unverändert.']; continue; }
                        $client = new RemoteClient($target, $lbpdatadir, $ramDirectory); $current = $client->snapshot();
                        if (in_array('battery', $groups, true) && !batteryCareEditable($current)) {
                            throw new RuntimeException('Dieses Ziel-Tablet meldet noch nicht die neue Akku-Steuerung. Die Akku-Gruppe wurde nicht übertragen.');
                        }
                        remember($id, $client->patch($current['revision'], $settings, $actions), false);
                        $results[] = [TabletStore::label($target), true, 'Ausgewählte Gruppen für die nächste Tablet-Meldung vorgemerkt.'];
                    } catch (Throwable $exception) { $results[] = [TabletStore::label($target), false, redacted($exception, $tablets)]; }
                }
                $form = 'config';
            } else {
                $upload = $_FILES['audio'] ?? null;
                if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                    || !isset($upload['tmp_name'], $upload['name']) || !is_string($upload['tmp_name']) || !is_string($upload['name'])
                    || !is_uploaded_file($upload['tmp_name'])) { throw new InvalidArgumentException('Audiodatei fehlt oder überschreitet das PHP-Upload-Limit.'); }
                $extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
                if (!in_array($extension, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'opus', 'mp4', '3gp', 'amr'], true)) { throw new InvalidArgumentException('Unterstützte Audio-Dateiendung auswählen, etwa mp3, wav oder ogg.'); }
                $soundId = ConfigForm::id(post('entry_id')); $soundName = post('entry_name');
                foreach ($selected as $id) {
                    $target = $store->get($id);
                    try {
                        $client = new RemoteClient($target, $lbpdatadir, $ramDirectory); $current = $client->snapshot();
                        remember($id, $client->upload($soundId, $soundName, $extension, $current['revision'], $upload['tmp_name']), $id === $deviceId);
                        $results[] = [TabletStore::label($target), true, 'Sound für die nächste Tablet-Meldung bereitgestellt; gleiche ID wird ersetzt.'];
                    } catch (Throwable $exception) { $results[] = [TabletStore::label($target), false, redacted($exception, $tablets)]; }
                }
                $form = 'actions'; $kind = 'sound';
            }
        } else {
            $device = $store->get($deviceId); $client = new RemoteClient($device, $lbpdatadir, $ramDirectory);
            if ($action === 'read') {
                remember($deviceId, $client->snapshot()); $message = 'Zuletzt gemeldeten Tablet-Stand geladen.'; $form = 'config';
            } elseif ($action === 'save_config') {
                $source = cached($deviceId, post('revision'));
                $availableSettings = editableSettings($source);
                $settingsDraft = [];
                foreach (ConfigForm::groups() as $group) {
                    foreach ($group[1] as $key => $field) {
                        if (array_key_exists($key, $availableSettings)) { $settingsDraft[$key] = $field[1] === 'bool' ? post('setting_' . $key) === '1' : post('setting_' . $key); }
                    }
                }
                remember($deviceId, $client->patch(post('revision'), ConfigForm::posted($_POST, $availableSettings)));
                $settingsDraft = null; $message = 'Änderungen dauerhaft in LOXi2DO gespeichert und zur Übernahme bereitgestellt. Das Tablet prüft und übernimmt sie bei seiner nächsten Meldung.'; $form = 'config';
            } elseif ($action === 'save_backup') {
                cached($deviceId, post('revision'));
                $client->saveBackup(post('revision'), post('backup_label'));
                remember($deviceId, $client->snapshot());
                $message = 'Zuletzt gemeldeten Tablet-Stand gesichert. Vorgemerkte Änderungen sind darin nicht enthalten.';
                $form = 'backups';
            } elseif ($action === 'restore_backup') {
                cached($deviceId, post('revision'));
                remember($deviceId, $client->restoreBackup(post('revision'), post('backup_id')));
                $message = 'Wiederherstellung vorgemerkt. Das Tablet prüft und übernimmt sie bei seiner nächsten Meldung; erst die Bestätigung meldet den Erfolg.';
                $form = 'backups';
            } elseif ($action === 'export_backup') {
                $exportId = post('backup_id');
                if (!preg_match('/^[a-f0-9]{32}$/D', $exportId)) { throw new InvalidArgumentException('Ungültige Sicherungs-ID.'); }
                $export = $client->exportBackup($exportId);
                $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="LOXi2DO-' . $deviceId . '-' . $exportId . '.json"');
                header('Content-Length: ' . strlen($json));
                echo $json;
                exit;
            } elseif ($action === 'import_backup') {
                cached($deviceId, post('revision'));
                $upload = $_FILES['backup_file'] ?? null;
                if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                    || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
                    throw new InvalidArgumentException('JSON-Sicherung fehlt oder überschreitet das PHP-Upload-Limit.');
                }
                $bytes = filesize($upload['tmp_name']);
                if ($bytes === false || $bytes < 1 || $bytes > 16777216) { throw new InvalidArgumentException('JSON-Sicherung muss zwischen 1 Byte und 16 MiB groß sein.'); }
                $contents = file_get_contents($upload['tmp_name']);
                if ($contents === false) { throw new RuntimeException('JSON-Sicherung konnte nicht gelesen werden.'); }
                $document = json_decode($contents, true, 48, JSON_THROW_ON_ERROR);
                if (!is_array($document)) { throw new InvalidArgumentException('JSON-Sicherung muss ein Konfigurationsobjekt enthalten.'); }
                remember($deviceId, $client->importBackup(post('revision'), $document));
                $message = 'Importierte Konfiguration zur Wiederherstellung vorgemerkt. Das Tablet übernimmt sie erst nach Prüfung bei seiner nächsten Meldung.';
                $form = 'backups';
            } elseif ($action === 'status') {
                $status = $client->status(); $form = 'config';
            } elseif ($action === 'apps') {
                $installedApps = $client->apps()['apps'] ?? []; $form = 'config';
            } elseif ($action === 'diagnostics') {
                $diagnostics = $client->diagnostics(); $form = 'diagnostics';
            } elseif ($action === 'discard_pending' || $action === 'rebase_pending') {
                cached($deviceId, post('revision'));
                remember($deviceId, $client->control(post('revision'), $action === 'discard_pending'));
                $message = $action === 'discard_pending' ? 'Vorgemerkte Änderungen verworfen.' : 'Änderungen ausdrücklich erneut auf Basis des zuletzt gemeldeten Tablet-Stands vorgemerkt.';
                $form = 'config';
            } elseif ($action === 'save_action' || $action === 'delete_action') {
                $postedKind = post('kind'); if (!isset($kinds[$postedKind])) { throw new InvalidArgumentException('Unbekannte Aktionskategorie.'); }
                $kind = $postedKind;
                $source = cached($deviceId, post('revision')); $entries = $source['actions'][$kind] ?? [];
                $id = ConfigForm::id(post('entry_id')); $updated = []; $old = null;
                foreach ($entries as $entry) { if ($entry['id'] === $id) { $old = $entry; } else { $updated[] = $entry; } }
                if ($action === 'save_action') {
                    $editAction = ConfigForm::action($_POST, $kind);
                    if ($kind === 'sound') {
                        if ($old === null) { throw new InvalidArgumentException('Neue Sounds über Audiodatei importieren anlegen.'); }
                        $editAction['file'] = $old['file'];
                    }
                    $updated[] = $editAction;
                } elseif ($old === null) { throw new InvalidArgumentException('Aktion wurde bereits entfernt.'); }
                remember($deviceId, $client->patch(post('revision'), [], [$kind => $updated]));
                $editAction = null; $message = $action === 'save_action' ? 'Aktion zur Übernahme vorgemerkt.' : 'Entfernen der Aktion vorgemerkt.'; $form = 'actions';
            } else { throw new InvalidArgumentException('Unbekannte Aktion.'); }
        }
    }
} catch (Throwable $exception) {
    $error = redacted($exception, $tablets);
    if (isset($action) && $action === 'save_tablet') { $form='config'; $showTabletDialog=true; }
    if (isset($action) && $action === 'save_config') {
        $form = 'config';
        foreach ($settingGroups as $groupId=>$group) {
            foreach ($group[1] as $field) {
                if (strpos($error,$field[0])!==false) { $activeSettingsGroup=$groupId; break 2; }
            }
        }
        if (strpos($error,'Akkugrenzen')!==false) { $activeSettingsGroup='battery'; }
    }
    if (isset($action) && in_array($action, ['save_action', 'delete_action', 'upload'], true)) { $form = 'actions'; }
    if (isset($action) && in_array($action, ['save_backup', 'restore_backup', 'export_backup', 'import_backup'], true)) { $form = 'backups'; }
}

if ($deviceId==='' && $tablets && in_array($form,['config','actions','backups','diagnostics'],true)) { $deviceId=(string)array_key_first($tablets); }
$device = $tablets[$deviceId] ?? null;
$snapshot = $_SESSION['loxi2do_snapshots'][$deviceId] ?? null;
if (!is_array($snapshot)) { $snapshot = null; }
if ($device!==null && in_array($form,['config','actions'],true) && (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='GET' || $snapshot===null)) {
    try { remember($deviceId,(new RemoteClient($device,$lbpdatadir,$ramDirectory))->snapshot()); $snapshot=$_SESSION['loxi2do_snapshots'][$deviceId]; }
    catch (Throwable $ignored) { /* A newly registered tablet may not have reported yet. */ }
}
if ($device !== null && $form === 'backups') {
    $backupClient = new RemoteClient($device, $lbpdatadir, $ramDirectory);
    try { remember($deviceId, $backupClient->snapshot()); $snapshot = $_SESSION['loxi2do_snapshots'][$deviceId]; }
    catch (Throwable $exception) { if ($error === '') { $error = redacted($exception, $tablets); } }
    try {
        $backups = $backupClient->history();
        if ($backupId !== '') { $backupDetail = $backupClient->exportBackup($backupId); }
    } catch (Throwable $exception) { if ($error === '') { $error = redacted($exception, $tablets); } }
}
$revision = $snapshot['revision'] ?? '';
$settings = $settingsDraft ?? ($snapshot['settings'] ?? []);
if ($device !== null && $form === 'config' && $installedApps === null) {
    try { $installedApps = (new RemoteClient($device,$lbpdatadir,$ramDirectory))->apps()['apps']; }
    catch (Throwable $ignored) { $installedApps=[]; }
}
if ($device !== null && $form === 'config') {
    try {
        $live = liveStatus($device, $lbpdatadir, $ramDirectory);
        $reportedStatus = $live['reported'];
        if (is_array($reportedStatus['battery_care'] ?? null)) { $batteryCareStatus = $reportedStatus['battery_care']; }
    } catch (Throwable $ignored) { }
}
if ($form === 'actions' && $editAction === null && $snapshot !== null && query('edit') !== '') {
    foreach ($snapshot['actions'][$kind] ?? [] as $entry) { if ((string) $entry['id'] === query('edit')) { $editAction = $entry; break; } }
}
if ($showTabletDialog && $deviceDraft === null && $device !== null) { $deviceDraft = TabletStore::publicDevice($device); }
$deviceDraft = $deviceDraft ?? ['id' => '', 'name' => '', 'host' => ''];
$fleet=Loxi2\FleetOverview::snapshot($tablets,$lbpdatadir,$ramDirectory);
$fleetById=[]; foreach ($fleet['devices'] as $fleetDevice) { $fleetById[$fleetDevice['id']]=$fleetDevice; }
$workspace=in_array($form,['config','actions','backups','diagnostics'],true);
$navbar = [
    10 => ['Name' => 'Dashboard', 'URL' => 'index.php?form=dashboard', 'active' => $form === 'dashboard'],
    20 => ['Name' => 'Konfiguration', 'URL' => 'index.php?form=config&device=' . rawurlencode($deviceId), 'active' => $workspace],
    30 => ['Name' => 'Hilfe', 'URL' => 'index.php?form=help', 'active' => $form === 'help'],
];
function uiIcon(string $name): string {
    $paths=[
        'plus'=>'<path d="M12 5v14M5 12h14"/>',
        'tablet'=>'<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M10 18h4"/>',
        'arrow'=>'<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'settings'=>'<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/>',
        'pulse'=>'<path d="M2 12h5l3-8 4 16 3-8h5"/>',
        'battery'=>'<rect x="2" y="6" width="17" height="12" rx="2"/><path d="M22 10v4M6 10v4m4-4v4m4-4v4"/>',
        'wifi'=>'<path d="M2 8a16 16 0 0 1 20 0M5 12a11 11 0 0 1 14 0m-11 4a6 6 0 0 1 8 0"/><circle cx="12" cy="20" r=".5"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
        'search'=>'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        'download'=>'<path d="M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'sound'=>'<path d="m11 4-6 5H2v6h3l6 5V4Zm4 4a6 6 0 0 1 0 8m3-11a10 10 0 0 1 0 14"/>',
        'notification'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'speech'=>'<path d="M21 11a8 8 0 0 1-8 8H7l-5 3 2-6a8 8 0 0 1-1-5 9 9 0 0 1 18 0ZM7 9h10M7 13h6"/>',
        'alert'=>'<path d="m12 3 10 18H2L12 3Zm0 6v5m0 3v.1"/>',
        'intent'=>'<path d="M9 6H3v15h15v-6M13 3h8v8M9 15 21 3"/>',
    ];
    return '<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name] ?? $paths['tablet']).'</svg>';
}
function fleetCard(array $item): void {
    $id=$item['id']; $contact=$item['connection_state'] ?? 'unknown';
    $contactLabel=$contact==='online' ? 'Aktuell' : ($contact==='offline' ? 'Kein aktueller Kontakt' : 'Noch keine Meldung');
    $percent=$item['battery_percent'];
    ?>
    <article class="fleet-device lb-card" data-device="<?= h($id) ?>" data-search="<?= h($item['name'].' '.$item['host']) ?>">
        <div class="fleet-device-top"><span class="device-symbol"><?= uiIcon('tablet') ?></span><span class="contact-badge" data-field="connection" data-state="<?= h($contact) ?>"><span></span><b><?= h($contactLabel) ?></b></span></div>
        <h3 data-field="name"><?= h($item['name']) ?></h3><p class="device-identity"><span data-field="host"><?= h($item['host']) ?></span><span> · </span><span data-field="model"><?= h($item['model'] ?? 'Android-Tablet') ?></span></p>
        <div class="battery-heading"><span><?= uiIcon('battery') ?>Akku</span><strong data-field="battery"><?= $percent===null ? '—' : h($percent).' %' ?></strong></div>
        <div class="battery-track" role="img" aria-label="<?= $percent===null ? 'Akkustand unbekannt' : 'Akkustand '.h($percent).' Prozent' ?>"><span data-field="battery-bar" style="width:<?= $percent===null ? 0 : h(max(0,min(100,(float)$percent))) ?>%"></span></div>
        <p class="battery-note" data-field="charging"><?= ($item['charging'] ?? null)===true ? 'Wird geladen' : (($item['plugged'] ?? null)===true ? 'Stromversorgung angeschlossen' : (($item['plugged'] ?? null)===false ? 'Im Akkubetrieb' : 'Stromversorgung unbekannt')) ?></p>
        <dl class="device-metrics"><div><dt><?= uiIcon('wifi') ?>WLAN</dt><dd data-field="wifi"><?= h($item['wifi_ssid'] ?? 'Nicht gemeldet') ?></dd></div><div><dt><?= uiIcon('pulse') ?>UDP-Empfang</dt><dd data-field="udp"><?= $item['udp_running']===true ? 'Aktiv' : ($item['udp_running']===false ? 'Gestoppt' : 'Nicht gemeldet') ?></dd></div><div><dt><?= uiIcon('tablet') ?>Display</dt><dd data-field="display"><?= ($item['display_on'] ?? null)===true ? 'An' : (($item['display_on'] ?? null)===false ? 'Aus' : 'Nicht gemeldet') ?></dd></div><div><dt><?= uiIcon('check') ?>MQTT</dt><dd data-field="mqtt"><?= !empty($item['mqtt']['delivery_confirmed']) ? 'Bestätigt' : (($item['mqtt']['state'] ?? '')==='unavailable' ? 'Nicht verfügbar' : (($item['mqtt']['state'] ?? '')==='pending' ? 'Ausstehend' : (($item['mqtt']['state'] ?? '')==='published' ? 'Unbestätigt' : 'Nicht gemeldet'))) ?></dd></div></dl>
        <div class="device-sync" data-field="management"><?= h($item['management']['message'] ?? 'Noch kein Konfigurationsstand') ?></div>
        <footer class="device-card-footer"><span data-field="contact" title="<?= empty($item['last_seen']) ? '' : h(date('d.m.Y H:i:s',(int)$item['last_seen'])) ?>"><?= empty($item['last_seen']) ? 'Kontakt ausstehend' : 'Letzter Kontakt '.h(date('H:i:s',(int)$item['last_seen'])) ?></span><a data-field="link" href="?form=config&amp;device=<?= h($id) ?>">Konfigurieren <?= uiIcon('arrow') ?></a></footer>
    </article>
    <?php
}
LBWeb::lbheader('LOXi2DO', 'https://github.com/BastyJuice', '', true);
?>
<style><?php readfile(__DIR__.'/assets/interface.css'); ?></style>
<div class="lb-content loxi2do" id="loxi2do-app" data-view="<?= h($form) ?>">
    <?php if ($message !== ''): ?><p class="lb-callout" role="status"><?= h($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="lb-callout lb-callout-warning" role="alert"><?= h($error) ?></p><?php endif; ?>
    <?php if ($results): ?><section class="lb-card"><h2 class="lb-section-title">Ergebnis je Tablet</h2><table class="lb-table"><thead><tr><th>Tablet</th><th>Ergebnis</th></tr></thead><tbody>
        <?php foreach ($results as $result): ?><tr><th scope="row"><?= h($result[0]) ?></th><td><?= $result[1] ? '✓ ' : '⚠ ' ?><?= h($result[2]) ?></td></tr><?php endforeach; ?>
    </tbody></table></section><?php endif; ?>

    <?php if ($form === 'dashboard'): ?>
    <div id="fleet-dashboard">
        <header class="dashboard-hero"><div class="hero-copy"><p class="eyebrow">LOXi2DO · TABLET CONTROL</p><h1>Alles im Blick.</h1><p>Deine Tablets, ihr Status und die nächste Konfiguration. An einem Ort.</p><div class="hero-actions"><button class="lb-btn lb-btn-primary" type="button" data-tablet-add><?= uiIcon('plus') ?> Tablet hinzufügen</button><a class="lb-btn hero-secondary" href="?form=config">Konfiguration <?= uiIcon('arrow') ?></a></div></div><div class="hero-art" aria-hidden="true"><div class="art-tablet art-one"><span>L²</span><i></i></div><div class="art-tablet art-two"><span>L²</span><i></i></div><div class="art-hub"><span>LOXi2<span>DO</span></span><small>CONNECTED</small></div><div class="art-line art-line-one"></div><div class="art-line art-line-two"></div><div class="art-dot"></div></div></header>
        <div class="fleet-stats"><article><span class="stat-icon"><?= uiIcon('tablet') ?></span><div><span>Tablets gesamt</span><strong data-fleet-count="total"><?= h($fleet['total']) ?></strong></div></article><article><span class="stat-icon"><?= uiIcon('pulse') ?></span><div><span>Aktueller Kontakt</span><strong data-fleet-count="online"><?= h($fleet['online']) ?></strong></div></article><article><span class="stat-icon"><?= uiIcon('clock') ?></span><div><span>Änderungen vorgemerkt</span><strong data-fleet-count="pending"><?= h($fleet['pending']) ?></strong></div></article><article><span class="stat-icon"><?= uiIcon('check') ?></span><div><span>MQTT bestätigt</span><strong data-fleet-count="mqtt_ok"><?= h($fleet['mqtt_ok']) ?></strong></div></article></div>
        <div class="section-heading"><div><p class="eyebrow">DEINE GERÄTE</p><h2>Tablet-Übersicht <span class="count-label" data-fleet-count="total"><?= h($fleet['total']) ?></span></h2></div><label class="search-field"><?= uiIcon('search') ?><input id="fleet-search" type="search" placeholder="Tablet suchen …" aria-label="Tablets nach Name oder IP suchen"></label></div>
        <div class="live-caption"><span class="live-indicator"></span><span>Live-Übersicht</span><span id="fleet-updated">Stand <?= h(date('H:i:s',$fleet['server_time'])) ?></span></div>
        <p id="fleet-error" class="fleet-error" role="status"></p>
        <div id="fleet-devices" class="fleet-grid"><?php foreach ($fleet['devices'] as $item) { fleetCard($item); } ?></div>
        <div id="fleet-empty" class="empty-state lb-card" <?= $fleet['total']>0 ? 'hidden' : '' ?>><span class="empty-icon"><?= uiIcon('tablet') ?></span><h2>Dein erstes Tablet wartet.</h2><p>Trage seine IP und den LOXi2DO-Token ein. Sobald es sich meldet, erscheint hier sein Status.</p><button class="lb-btn lb-btn-primary" type="button" data-tablet-add><?= uiIcon('plus') ?> Tablet hinzufügen</button></div>
        <p id="fleet-no-results" class="empty-search" hidden>Kein Tablet passt zu deiner Suche.</p>
        <p class="fleet-footnote">„Aktuell“ bedeutet: Kontakt innerhalb der letzten 45 Sekunden. Messwerte zeigen den zuletzt empfangenen Stand.</p>
        <aside class="download-strip"><div><strong>LOXi2DO auf ein neues Tablet bringen?</strong><span>Die passende App ist bereits im Plugin enthalten.</span></div><a class="lb-btn" href="/plugins/loxi2do/LOXi2.apk" download="LOXi2.apk"><?= uiIcon('download') ?> APK herunterladen</a><a href="?form=help#apk-installation">Installationshilfe <?= uiIcon('arrow') ?></a></aside>
        <script type="application/json" id="fleet-initial"><?= json_encode(['ok'=>true]+$fleet,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR) ?></script>
        <template id="fleet-card-template"><?php fleetCard(['id'=>'0000000000000000','name'=>'','host'=>'','connection_state'=>'unknown','battery_percent'=>null,'udp_running'=>null,'mqtt'=>[],'management'=>[]]); ?></template>
    </div>

    <?php elseif ($form === 'help'): ?>
    <header class="page-heading"><div><p class="eyebrow">WISSEN & UNTERSTÜTZUNG</p><h1>Hilfe</h1><p>Einrichten, verbinden und das Beste aus deinen Tablets machen.</p></div><a class="lb-btn" href="?form=dashboard">Zum Dashboard <?= uiIcon('arrow') ?></a></header>
    <div class="help-content">
    <section class="lb-card" id="apk-installation"><h2 class="lb-section-title">LOXi2DO herunterladen und installieren</h2>
        <p><a class="lb-btn lb-btn-primary" href="/plugins/loxi2do/LOXi2.apk" download="LOXi2.apk">LOXi2.apk herunterladen</a></p>
        <p>Öffentliche Download-Adresse im lokalen Netz: <code>http://&lt;LoxBerry-IP&gt;/plugins/loxi2do/LOXi2.apk</code>. Der Download benötigt keinen Tablet-Token und keine Anmeldung an der Plugin-Verwaltung. Die Datei enthält ausschließlich die App, keine Tablet-Konfiguration oder Zugangsdaten.</p>
        <h3>Direkt am Tablet</h3>
        <ol><li>Die Download-Adresse im externen Browser des Tablets öffnen, auf Fire OS zum Beispiel Silk, und <strong>LOXi2.apk</strong> herunterladen.</li>
            <li>Die Datei über Downloads öffnen. Falls Android oder Fire OS dazu auffordert, diesem Browser beziehungsweise Dateimanager die Installation unbekannter Apps erlauben.</li>
            <li><strong>Installieren</strong> beziehungsweise <strong>Aktualisieren</strong> bestätigen und LOXi2DO öffnen. Android 11 / API 30 oder neuer ist erforderlich. Eine vorhandene gleich signierte LOXi2DO-Installation wird aktualisiert und behält ihre Daten.</li></ol>
        <h3>Mit ADB unter Windows (PowerShell)</h3>
        <p>ADB ist in den offiziellen <a href="https://developer.android.com/tools/releases/platform-tools?hl=de" target="_blank" rel="noopener noreferrer">Android SDK Platform Tools</a> für Windows, macOS und Linux enthalten. Das passende ZIP herunterladen und entpacken; eine Installation von Android Studio ist dafür nicht nötig.</p>
        <p>USB-Debugging am Tablet aktivieren, verbinden und den PC auf dem Tablet autorisieren. <code>LOXi2.apk</code> nach <code>C:\platform-tools</code> herunterladen oder kopieren. PowerShell dort öffnen:</p>
        <pre>cd C:\platform-tools
.\adb.exe devices
.\adb.exe install -r .\LOXi2.apk</pre>
        <p>Bei mehreren verbundenen Tablets die Seriennummer aus <code>adb devices</code> angeben:</p>
        <pre>.\adb.exe -s SERIENNUMMER install -r .\LOXi2.apk</pre>
        <p><code>-r</code> aktualisiert die vorhandene App mit ihren Daten. Die Installation vergibt keinen Device-Owner-Status. Ein Plugin-Update ersetzt die angebotene APK; die Installation auf dem Tablet wird weiterhin bewusst gestartet.</p>
    </section>
    <section class="lb-card" id="apk-updates"><h2 class="lb-section-title">App-Updates direkt vom Dashboard</h2>
        <p>Ab <strong>App 0.12.0 und Plugin 0.7.0</strong> kann das Tablet neue hier bereitgestellte APKs selbst erkennen. Die erste passende APK einmal über den Download oder per ADB installieren. Danach genügen bei künftigen Versionen ein Plugin-Update und ein Tipp auf das Update-Symbol am Tablet.</p>
        <p><strong>Plugin-Upgrade ab 0.7.1:</strong> Vor dem Austausch sichert das Plugin die vollständigen privaten Tablet-Einträge, Tokens, Einstellungen, Backups und Sounds außerhalb der vom Installer gelöschten Verzeichnisse. Anschließend werden die Daten wiederhergestellt und geprüft. Währenddessen kann die Oberfläche kurz HTTP 503 anzeigen. Beim ersten Upgrade von einer älteren Version während der Installation keine Einstellungen bearbeiten. Eine abgebrochene Installation behält ihre private Sicherung; der Wiederherstellungsaufruf steht in der mitgelieferten README. Bereits früher gelöschte Daten benötigen eine vorhandene frühere Sicherung.</p>
        <ol><li>Auf dem Tablet unter Verbindung → Zugriff Remote Management mit der festen LoxBerry-IP und demselben Tablet-Token aktivieren. <strong>App-Updates über LoxBerry prüfen</strong> angehakt lassen und speichern. Diese lokale Freigabe ist nur zusammen mit aktivem Management wirksam und wird nicht zentral verändert.</li>
            <li>Die App prüft beim Öffnen und beim regelmäßigen Konfigurationsabruf auf eine neuere passende Version. Nur dann erscheint neben dem Fragezeichen im Dashboard ein grünes Update-Symbol. Der Hinweis startet keine Installation von selbst.</li>
            <li>Das Symbol antippen. LOXi2DO lädt die APK, prüft Paketkennung, höhere Version, Dateiintegrität und dieselbe vertrauenswürdige Signatur. Fortschritt und Fehler erscheinen auf dem Dashboard.</li>
            <li>Ohne Device Owner gegebenenfalls LOXi2DO in Android als Installationsquelle freigeben, zur App zurückkehren und den Installationsdialog bestätigen. Mit bereits vorhandenem Device Owner kann Android den ausdrücklich gestarteten Update-Vorgang ohne zusätzlichen Installationsdialog ausführen.</li></ol>
        <p>Die feste APK-Adresse bleibt <code>/plugins/loxi2do/LOXi2.apk</code>. Daneben liegt die öffentliche Versionsdatei <code>/plugins/loxi2do/LOXi2-update.json</code>. Die App vertraut nicht allein dieser öffentlichen Datei, sondern fragt die Update-Informationen authentifiziert über <code>api.php</code> ab und prüft die heruntergeladene APK vor der Installation. Es werden keine Downgrades oder fremden Signaturen akzeptiert. Einstellungen und Tablet-Token bleiben beim normalen Update erhalten.</p>
        <p>Eine ältere APK oder ein älteres Plugin erhält durch den festen Downloadpfad noch keine Update-Funktion. Ohne bestätigte neuere Version bleibt das Symbol ausgeblendet. Ein Update erteilt weder Root noch Device-Owner-Rechte.</p>
    </section>
    <section class="lb-card"><h2 class="lb-section-title">Einrichten</h2><ol>
        <li>LOXi2DO ab Version 0.10.3 auf jedem Tablet installieren. Diese Version verwendet den neuen Managementpfad <code>/plugins/loxi2do/api.php</code>.</li>
        <li>In LOXi2DO unter Verbindung → Zugriff Remote Management aktivieren und die feste IPv4-Adresse dieses LoxBerry setzen. Ein nicht leerer Token ist auch bei aktivem UDP-Legacy-Modus erforderlich.</li>
        <li>Unter Konfiguration über das Plus jedes Tablet mit seiner festen IP und seinem vorhandenen LOXi2DO-Token hinzufügen.</li>
        <li>Nach der ersten Tablet-Meldung das Tablet in der Konfiguration auswählen, Einstellungen ändern und bereitstellen. Ab App 0.11.0 prüft das Tablet zu jeder vollen Minute auf Änderungen. Gruppen können auch für derzeit nicht erreichbare Tablets vorgemerkt werden.</li>
    </ol><p>LOXi2DO verwendet vorhandenes PHP und die LoxBerry-Oberfläche. Es installiert keine weiteren Pakete und benötigt keinen eigenen Hintergrunddienst.</p><p>Beim Wechsel von Plugin 0.3.1 oder älter kann LoxBerry LOXi2DO als neues Plugin erkennen. Das neue Plugin zuerst installieren: Vorhandene Zugänge, Backups und verwaltete Sounds werden einmalig kopiert, wenn die neue Ablage noch leer ist. Bestehende neue Daten werden nicht überschrieben. Das vorherige Plugin erst entfernen, nachdem Einträge und Dateien in LOXi2DO geprüft wurden.</p></section>
    <section class="lb-card" id="mqtt-status"><h2 class="lb-section-title">Live-Status und MQTT</h2>
        <p>Ab App 0.11.0 und Plugin 0.6.0 sendet das Tablet Statusänderungen fortlaufend an den LoxBerry. Messwerte werden etwa alle fünf Sekunden geprüft, wichtige Android-Ereignisse stoßen eine zusätzliche Meldung an. Ohne Änderungen folgt spätestens nach 30 Sekunden eine Kontaktmeldung. Die App gleicht die Konfiguration sofort beim Managementstart und danach zu jeder vollen Minute ab, zum Beispiel um 12:00:00 und 12:01:00. Android-Ruhemodus, Netzprobleme und laufende Übertragungen können die tatsächliche Zustellung verzögern.</p>
        <p>Unter Konfiguration → Live-Gerätestatus erscheinen Info, Akku, WLAN, Display, System, Audio, Berechtigungen und Dienst. Bei geöffneter Ansicht werden die zuletzt empfangenen Werte alle fünf Sekunden nachgeladen. Ein Zeitstempel bezeichnet den letzten empfangenen Kontakt und ist kein Beweis für aktuelle Erreichbarkeit. Ältere APKs behalten ihren bisherigen Meldetakt.</p>
        <p>Ab Plugin 0.7.1 veröffentlicht das Plugin direkt beim konfigurierten LoxBerry-MQTT-Broker, wie das ENPHASE-Plugin. Host, Port, Zugang und gegebenenfalls TLS stammen aus der vorhandenen LoxBerry-Konfiguration. Vorhandene PHP-Streams genügen; UDP-Gateway, alte phpMQTT-Bibliothek und zusätzliche Pakete sind dafür nicht erforderlich. Broker-Zugangsdaten bleiben auf dem LoxBerry.</p>
        <p>Die Werte werden mit <strong>QoS 1 und Retain</strong> gesendet. Erst nach allen Broker-Bestätigungen erscheint <strong>„Vom MQTT-Broker bestätigt (QoS 1)“</strong>. Dies bestätigt die Annahme durch den Broker, nicht die Verarbeitung durch einen bestimmten Empfänger. Bei Fehlern zeigt die Statusansicht einen Hinweis; der neueste Status bleibt im RAM und wird später erneut versucht. Konfigurationsabgleich und Statusannahme bleiben verfügbar.</p>
        <p>Der konfigurierte Tablet-Name bildet die Geräte-Ebene unter <code>loxi2do</code>. Bei einer Umbenennung ändert sich auch die MQTT-Adresse. Die genaue Basis steht in der Statusansicht:</p>
        <pre>loxi2do/&lt;Tablet-Name&gt;/Info/name
loxi2do/&lt;Tablet-Name&gt;/Info/last_seen
loxi2do/&lt;Tablet-Name&gt;/Akku/percent
loxi2do/&lt;Tablet-Name&gt;/Akku/temperature_c
loxi2do/&lt;Tablet-Name&gt;/Akku/care/mode
loxi2do/&lt;Tablet-Name&gt;/WLAN/ssid
loxi2do/&lt;Tablet-Name&gt;/WLAN/signal_dbm
loxi2do/&lt;Tablet-Name&gt;/Display/…
loxi2do/&lt;Tablet-Name&gt;/System/…
loxi2do/&lt;Tablet-Name&gt;/Audio/…
loxi2do/&lt;Tablet-Name&gt;/Berechtigungen/…
loxi2do/&lt;Tablet-Name&gt;/Dienst/…</pre>
        <p>Ab Plugin 0.8.1 steht <code>loxi2do</code> direkt als Haupttopic, ohne führenden Schrägstrich oder zusätzliche Zwischenebene. Danach folgt der unter Konfiguration hinterlegte Tablet-Name, zum Beispiel <code>loxi2do/Tablet Flur/Akku/percent</code>. Ohne eigenen Namen wird die Tablet-IP verwendet; doppelte Namen erhalten einen IP-Zusatz. Leerzeichen und Umlaute bleiben erhalten. Die MQTT-Sonderzeichen <code>/</code>, <code>+</code>, <code>#</code> sowie <code>%</code> im Namen werden eindeutig kodiert. Die konkrete MQTT-Basis steht beim Tablet im Live-Gerätestatus.</p>
        <p>Zum Anzeigen aller Geräte <code>loxi2do/#</code> abonnieren. Vorhandene Abonnements auf die frühere Struktur müssen angepasst werden. Beim nächsten Tablet-Bericht werden eigene alte retained Topics und bei einer Umbenennung die bisherigen Namens-Topics bereinigt. Andere Tablets bleiben erhalten. Einzelwerte werden als Zahl, Text oder <code>0</code>/<code>1</code> übertragen und retained gespeichert. Nicht verfügbare Werte sind unbekannt; ein weggefallener Wert entfernt einen zuvor vorgehaltenen MQTT-Wert.</p>
        <p>Messwerte hängen von Gerät, Firmware und Freigaben ab. WLAN-Namen benötigen passende Standortfreigaben; Akkustrom oder einzelne Sensorwerte stehen nicht auf jedem Gerät zur Verfügung. Der gemeldete Amazon-Akkuschonmodus bestätigt keine exakte Ladegrenze. Tokens, persönliche Schlüssel, rohe Diagnoseprotokolle und Konfigurationsinhalte werden nicht als MQTT-Status veröffentlicht.</p>
    </section>
    <section class="lb-card"><h2 class="lb-section-title">Tablet-Name im Dashboard</h2><p>Unter Konfiguration → Zugang kann ein optionaler Name vergeben werden. LOXi2DO ab 0.10.1 übernimmt ihn beim Start des Managementdienstes oder beim nächsten Abruf, ab App 0.11.0 zur nächsten vollen Minute. Ein leerer Name stellt die bisherige Systemnamen-Anzeige wieder her. Ohne erreichbaren LoxBerry bleibt der zuletzt bestätigte Name verfügbar. Wird Remote Management lokal ausgeschaltet oder seine Vertrauensadresse beziehungsweise sein Token geändert, verwendet die App wieder den bisherigen Gerätenamen.</p><p>Der Name gehört zum Tablet-Eintrag in LOXi2DO. Er verändert weder den Android-/Amazon-Gerätenamen noch die gesicherten App-Einstellungen. Für die Verbindung mit diesem Plugin ist wegen des neuen Managementpfads App-Version 0.10.3 oder neuer erforderlich.</p></section>
    <section class="lb-card"><h2 class="lb-section-title">Was zentral verwaltet wird</h2><p>UDP-Port, erlaubte Quellen, Präfix, Legacy, Befehlszuordnungen, Apps, Webseiten, interner/externer Browser, Vollbild, JavaScript, Display-Fallback, Instant off, CPU-Wachhaltung, Overlay, Heim-WLAN, Autostart, Funktionsfreigaben, RAM-Diagnose sowie Sounds, Mitteilungen, Sprachtexte, Alerts und Intents.</p><p>Android-Berechtigungen, Device Owner, Root und die lokale Management-Vertrauensfreigabe werden auf dem Tablet eingerichtet. Der Token wird nicht ausgelesen oder zentral überschrieben. Ausführung von Reboot-/Display-/App-Befehlen bleibt bei Loxone und der bestehenden UDP-Schnittstelle.</p></section>
    <section class="lb-card"><h2 class="lb-section-title">Dauerbetrieb und Akku</h2><p><strong>Akkuschonmodus aktivieren</strong> ist bei kompatiblen Fire-Tablets verfügbar. Die niedrigere Ladespannung schont den Akku bei dauerhaftem Netzbetrieb. Die gespeicherte Auswahl wird beim App-Start, nach einem Neustart und während des Betriebs geprüft und bei Abweichung erneut angefordert. Die frühere Prozentautomatik für diesen Modus entfällt.</p><p><strong>Akkuladebegrenzung steuern</strong> schaltet ein externes Ladegerät über zwei frei hinterlegte HTTP(S)-GET-Adressen. Beispiel AN: <code>http://IP-SHELLY/relay/0?turn=on</code>, AUS: <code>http://IP-SHELLY/relay/0?turn=off</code>. Unterhalb des Minimums wird AN gesendet, ab dem Maximum AUS. Dazwischen bleibt die letzte Entscheidung erhalten; Vorgabe 30 % / 70 %. Die externe Ladebegrenzung steht auch auf Android-Tablets ohne Fire-Modus zur Verfügung.</p><p>Mit <strong>Akkuschonmodus beim Laden automatisch deaktivieren</strong> wird ein gewählter Fire-Schonmodus bei Laden AN ausgesetzt und bei Laden AUS wiederhergestellt. Die Checkbox für den gewünschten Schonmodus bleibt gespeichert. Ohne gewählten Fire-Schonmodus schaltet diese Option ihn nicht ein.</p><p>Der freigegebene UDP-Befehl <code>loxi2do:charge:1</code> sendet AN und <code>loxi2do:charge:0</code> sendet AUS. Ein angenommener manueller Auftrag beendet die Schwellenautomatik und benötigt konfigurierte HTTP-Adressen. Eigene Präfixe und Zuordnungen gelten weiterhin. Speichern stellt die Konfiguration für den nächsten signierten Tablet-Abruf bereit. Ältere APKs erhalten keine neuen Akku-Einstellungen; vorhandene Konfigurationen und die letzten sieben Sicherungen bleiben erhalten.</p><p>HTTP-Adressen können vertrauliche Query-Parameter enthalten. Sie bleiben Teil der privaten Konfiguration und ihrer Sicherungen und werden nicht an MQTT veröffentlicht.</p></section>
    <section class="lb-card"><h2 class="lb-section-title">Übertragen und Konflikte</h2><p>Vormerken prüft die geladene Revision. Bei der nächsten Meldung vergleicht das Tablet zusätzlich seinen tatsächlichen Stand mit der ursprünglichen Basis. Bei lokalen Änderungen entsteht ein Konflikt: Die Vormerkung bleibt zur Prüfung erhalten. Danach ausdrücklich mit dem zuletzt gemeldeten Stand erneut vormerken oder verwerfen. Gruppenübertragung ändert nur ausgewählte Gruppen und ersetzt kein vollständiges fremdes Tabletprofil.</p><p>Audiodateien liegen privat auf dem jeweiligen Tablet. Für denselben Sound auf mehreren Tablets die Datei mit derselben ID auf diese Tablets hochladen. Mitteilungen und Alerts mit Soundreferenz benötigen dort dieselbe Sound-ID.</p></section>
    <section class="lb-card"><h2 class="lb-section-title">Sieben Backups pro Tablet</h2><p>Unter Backups bleiben die letzten sieben unterschiedlichen bestätigten Konfigurationen erhalten. Bei einer geänderten Tablet-Meldung wird der bisher bestätigte Stand automatisch gesichert; zusätzlich kann der gemeldete Ist-Stand manuell benannt werden. Wiederherstellen und JSON-Import erzeugen eine neue, revisionsgeprüfte Vormerkung. Erst die Bestätigung des Tablets meldet eine erfolgreiche Übernahme.</p><p>JSON-Downloads enthalten Einstellungen und Aktionsdefinitionen, keine Tokens oder Audiodaten. Frühere JSON-Sicherungen können weiterhin importiert werden. Für ältere lokale Sounds ohne verwaltete Dateikopie kann ein erneutes Bereitstellen der Datei nötig sein. Fehlende Sounddateien werden angezeigt und verhindern eine unvollständige Wiederherstellung.</p></section>
    <section class="lb-card"><h2 class="lb-section-title">Verbindung prüfen</h2><p>Feste IP-Adressen verwenden; Tablet und LoxBerry müssen sich im lokalen Netz erreichen. Das Tablet stellt selbst eine Verbindung zum Standardpfad /plugins/loxi2do/api.php auf dem LoxBerry her. Auf dem Tablet muss die LoxBerry-IP hinterlegt sein; hier muss exakt die tatsächliche Tablet-Absender-IP stehen. Token auf beiden Seiten vergleichen und beide Uhren synchron halten. Es wird kein eingehender Tablet-Port benötigt.</p><p>Anfragen und Antworten sind mit HMAC-SHA256 signiert. HTTP verschlüsselt die Konfigurationsdaten nicht; die Schnittstelle gehört ausschließlich ins vertrauenswürdige lokale Netz. Die vorhandene LoxBerry-Webauthentifizierung schützt die Maske.</p></section>

    <section class="lb-card" id="support"><h2 class="lb-section-title">LOXi2DO unterstützen</h2><p>Dir gefällt LOXi2DO und das Plugin erleichtert dir den Alltag? Wenn du meine Arbeit unterstützen möchtest, freue ich mich über einen Kaffee. Danke für deine Unterstützung!</p><p><a class="lb-btn lb-btn-primary" href="https://www.paypal.com/donate/?hosted_button_id=FDJ5UHKLA8C7A" target="_blank" rel="noopener noreferrer">Spendier mir einen Kaffee · PayPal</a></p></section>

    </div>
    <?php else: ?>
    <header class="page-heading"><div><p class="eyebrow">DEINE GERÄTE VERWALTEN</p><h1>Konfiguration</h1><p>Ein Tablet auswählen. Einstellungen anpassen. Bereitstellen.</p></div><button class="lb-btn lb-btn-primary" type="button" data-tablet-add><?= uiIcon('plus') ?> Tablet hinzufügen</button></header>
    <div class="device-workspace">
        <aside class="tablet-sidebar" id="tablet-sidebar"><div class="sidebar-heading"><h2>Tablets <span><?= h(count($tablets)) ?></span></h2><button class="icon-button" type="button" data-tablet-add aria-label="Tablet hinzufügen"><?= uiIcon('plus') ?></button></div><label class="search-field"><?= uiIcon('search') ?><input type="search" id="tablet-search" placeholder="Tablet suchen …" aria-label="Tablet-Liste durchsuchen"></label><nav class="tablet-list" aria-label="Tablet auswählen"><?php foreach ($tablets as $tablet): $brief=$fleetById[$tablet['id']] ?? []; ?><a href="?form=<?= h($form) ?>&amp;device=<?= h($tablet['id']) ?>" class="tablet-list-item <?= $deviceId===$tablet['id'] ? 'is-selected' : '' ?>" <?= $deviceId===$tablet['id'] ? 'aria-current="true"' : '' ?> data-search="<?= h(TabletStore::label($tablet).' '.$tablet['host']) ?>"><span class="tablet-list-symbol"><?= uiIcon('tablet') ?></span><span><strong><?= h(TabletStore::label($tablet)) ?></strong><small><?= h($tablet['host']) ?></small></span><i class="status-dot" data-state="<?= h($brief['connection_state'] ?? 'unknown') ?>" title="<?= ($brief['connection_state'] ?? '')==='online' ? 'Aktueller Kontakt' : 'Kein aktueller Kontakt' ?>"></i></a><?php endforeach; ?></nav><?php if (!$tablets): ?><p class="muted">Noch keine Tablets. Über das Plus legst du das erste an.</p><?php endif; ?><div class="sidebar-download"><a href="/plugins/loxi2do/LOXi2.apk" download="LOXi2.apk"><?= uiIcon('download') ?> LOXi2.apk herunterladen</a><a href="?form=help#apk-installation">Hilfe beim Einrichten <?= uiIcon('arrow') ?></a></div></aside>
        <main class="tablet-detail">
            <?php if ($device!==null): $selectedBrief=$fleetById[$deviceId] ?? []; ?>
            <header class="selected-device-heading"><div class="selected-device-title"><span class="device-symbol"><?= uiIcon('tablet') ?></span><div><h2><?= h(TabletStore::label($device)) ?></h2><p><?= h($device['host']) ?><?= !empty($selectedBrief['model']) ? ' · '.h($selectedBrief['model']) : '' ?><?= !empty($selectedBrief['app_version']) ? ' · App '.h($selectedBrief['app_version']) : '' ?></p></div></div><div class="selected-device-actions"><?php if ($form==='config' && $snapshot!==null): ?><button class="lb-btn lb-btn-primary" type="submit" form="settings-form" aria-label="Einstellungen speichern und bereitstellen"><?= uiIcon('check') ?> Speichern</button><?php endif; ?><button class="lb-btn" type="button" data-tablet-edit data-id="<?= h($deviceId) ?>" data-name="<?= h($device['name']) ?>" data-host="<?= h($device['host']) ?>"><?= uiIcon('settings') ?> Zugang</button></div></header>
            <nav class="tablet-subnav" id="tablet-subnav" aria-label="Tablet-Bereiche"><?php foreach (['config'=>'Einstellungen','actions'=>'Sounds & Aktionen','backups'=>'Backups','diagnostics'=>'Diagnose'] as $route=>$title): ?><a href="?form=<?= h($route) ?>&amp;device=<?= h($deviceId) ?>" <?= $form===$route ? 'aria-current="page" class="is-active"' : '' ?>><?= h($title) ?></a><?php endforeach; ?></nav>
            <div class="device-tools"><span>Zuletzt gemeldete Tablet-Konfiguration</span><form method="post" action="index.php?form=<?= h($form) ?>&amp;device=<?= h($deviceId) ?>"><?php csrf(); hidden('device',$deviceId); ?><button class="text-button" name="action" value="read">Gemeldeten Stand neu laden</button></form></div>
            <?php else: ?><section class="empty-state lb-card"><span class="empty-icon"><?= uiIcon('tablet') ?></span><h2>Hier beginnt deine Tablet-Konfiguration.</h2><p>Füge dein erstes Tablet mit seiner festen IP und dem Token aus der App hinzu.</p><button class="lb-btn lb-btn-primary" type="button" data-tablet-add><?= uiIcon('plus') ?> Tablet hinzufügen</button></section><?php endif; ?>
    <?php if ($device !== null && $form === 'config'): ?>
    <details id="telemetry-panel" class="lb-card compact-status status-drawer" data-device="<?= h($deviceId) ?>" <?= $status!==null || query('live')==='1' ? 'open' : '' ?>><summary class="telemetry-summary">Live-Gerätestatus und Berechtigungen</summary>
        <p id="telemetry-contact" class="telemetry-meta">Letzte Meldung wird geladen.</p>
        <p id="telemetry-observed" class="telemetry-meta" hidden></p>
        <p id="telemetry-mqtt" class="telemetry-meta">MQTT-Veröffentlichung wird geprüft.</p>
        <p class="telemetry-meta">MQTT-Basis: <code><?= h(Loxi2\MqttTopics::prefix($device,$tablets)) ?>/</code> · <a href="?form=help#mqtt-status">Topics und Statusübertragung</a></p>
        <div class="telemetry-content">
            <p>Aktualisiert alle fünf Sekunden, solange geöffnet. Nicht verfügbare Werte bleiben unbekannt.</p>
            <p id="telemetry-error" role="status"></p>
            <div id="telemetry-groups" class="telemetry-grid"></div>
            <details><summary>Vollständige Statusdaten anzeigen</summary><pre id="telemetry-raw"></pre></details>
        </div>
        <noscript><p>Für die automatische Statusanzeige JavaScript aktivieren. Den gemeldeten Stand kannst du oben neu laden.</p><?php if ($status !== null): ?><pre><?= h(json_encode($status, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?></pre><?php endif; ?></noscript>
    </details>
    <script type="application/json" id="telemetry-initial"><?= json_encode($live, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR) ?></script>
    <?php elseif ($status !== null): ?><section class="lb-card"><h2 class="lb-section-title">Gerätestatus und Berechtigungen</h2><pre><?= h(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></section><?php endif; ?>

    <?php if ($device !== null && $form === 'backups'): ?>
    <?php $backupManagement = $snapshot['management'] ?? []; $hasPendingBackup = !empty($backupManagement['pending_id']); ?>
    <section class="lb-card"><h2 class="lb-section-title">Backups und Wiederherstellung</h2>
        <p>Die letzten sieben unterschiedlichen, gesicherten Konfigurationsstände dieses Tablets bleiben erhalten. Bei einer geänderten Tablet-Meldung wird der bisher bestätigte Stand automatisch gesichert. Unveränderte Konfigurationsmeldungen und fehlgeschlagene Vormerkungen erzeugen keine zusätzlichen Versionen.</p>
        <p>Eine Sicherung enthält Einstellungen und Aktionsdefinitionen des <strong>zuletzt gemeldeten Ist-Stands</strong>. Nicht bestätigte Vormerkungen, Zugangstoken, lokale Management-Freigaben, Android-Berechtigungen und Diagnoseeinträge gehören nicht dazu.</p>
        <p>Letzte Tablet-Meldung: <?= empty($backupManagement['last_seen']) ? 'noch keine' : h(date('d.m.Y H:i:s', (int) $backupManagement['last_seen'])) ?>. Aktueller Übertragungsstatus: <strong><?= h(stateLabel($backupManagement)) ?></strong>.</p>
        <?php if (is_string($backupManagement['result']['warning'] ?? null) && $backupManagement['result']['warning']!==''): ?><p class="lb-callout lb-callout-warning"><?= h($backupManagement['result']['warning']) ?></p><?php endif; ?>
        <?php if ($hasPendingBackup): ?><p class="lb-callout lb-callout-warning">Es gibt bereits vorgemerkte Änderungen. Vor einer Wiederherstellung diese im Bereich <a href="?form=config&amp;device=<?= h($deviceId) ?>">Konfiguration</a> prüfen und gegebenenfalls bewusst verwerfen. Die Wiederherstellung wird nicht mit einer vorhandenen Vormerkung vermischt.</p><?php endif; ?>
    </section>
    <form class="lb-card" method="post" action="index.php?form=backups&amp;device=<?= h($deviceId) ?>">
        <?php csrf(); hidden('action', 'save_backup'); hidden('device', $deviceId); hidden('revision', $revision); ?>
        <h2 class="lb-section-title">Aktuellen gemeldeten Stand sichern</h2>
        <div class="lb-form-row"><label class="lb-form-label" for="backup-label">Bezeichnung (optional)</label><div class="lb-form-field"><input class="lb-input" type="text" name="backup_label" id="backup-label" maxlength="100" placeholder="Zum Beispiel: Vor Display-Änderung"></div></div>
        <p>Speichert den zuletzt bestätigten Tablet-Stand dauerhaft auf dem LoxBerry. Ist derselbe Stand schon gesichert, wird dieser Eintrag aktualisiert, ohne einen weiteren der sieben Plätze zu belegen.</p>
        <button class="lb-btn lb-btn-primary" type="submit" <?= $revision === '' ? 'disabled' : '' ?>>Aktuellen gemeldeten Stand sichern</button>
    </form>
    <section class="lb-card"><h2 class="lb-section-title">Gesicherte Stände · <?= h(count($backups)) ?> von 7</h2>
        <p>Die Übersicht prüft die Dateiverfügbarkeit. Archivierte Audiodateien werden beim Wiederherstellen auf Prüfsummen geprüft; vorhandene Tablet-Dateien werden wiederverwendet.</p>
        <?php if (!$backups): ?><p>Noch keine Sicherung vorhanden. Nach der ersten Tablet-Meldung wird der gemeldete Stand gesichert.</p><?php else: ?>
        <div class="tablet-table-scroll"><table class="lb-table backup-table"><thead><tr><th>Zeitpunkt / Quelle</th><th>Stand</th><th>Inhalt</th><th>Wiederherstellung</th><th>Aktionen</th></tr></thead><tbody>
        <?php foreach ($backups as $backup): ?><tr>
            <td class="backup-date"><?= h(date('d.m.Y H:i:s', (int) $backup['created_at'])) ?><br><?= ($backup['source'] ?? '') === 'manual' ? 'Manuell gesichert' : 'Automatisch gesichert' ?></td>
            <td class="backup-state"><?= h(($backup['label'] ?? '') !== '' ? $backup['label'] : 'Konfiguration') ?><br><code title="<?= h($backup['revision'] ?? '') ?>"><?= h(substr($backup['revision'] ?? '', 0, 12)) ?></code><?php if (!empty($backup['current'])): ?><br><strong>Aktuell gemeldeter Stand</strong><?php endif; ?></td>
            <td><?= h($backup['settings_count'] ?? 0) ?> Einstellungen<br><?= h(array_sum($backup['action_counts'] ?? [])) ?> Aktionen · <?= h($backup['sound_count'] ?? 0) ?> Sounds</td>
            <td><?= !empty($backup['unavailable_reason']) ? h($backup['unavailable_reason']) : (!empty($backup['restorable']) ? 'Dateien verfügbar' : 'Audiodateien fehlen') ?><?php if (!empty($backup['missing_sounds'])): ?><br><?= h(count($backup['missing_sounds'])) ?> Sounddatei(en) zuerst erneut bereitstellen<?php endif; ?></td>
            <td><a class="lb-btn" href="?form=backups&amp;device=<?= h($deviceId) ?>&amp;backup=<?= h($backup['id']) ?>">Ansehen / Wiederherstellen</a>
                <form class="inline-form" method="post" action="index.php?form=backups&amp;device=<?= h($deviceId) ?>"><?php csrf(); hidden('action', 'export_backup'); hidden('device', $deviceId); hidden('backup_id', $backup['id']); ?><button class="lb-btn" type="submit">JSON herunterladen</button></form></td>
        </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
    <?php if (is_array($backupDetail)): ?>
    <?php $details = $backupDetail['snapshot'] ?? []; $backupRestorable = !empty($backupDetail['restorable']); ?>
    <section class="lb-card"><h2 class="lb-section-title">Sicherung prüfen</h2>
        <p><strong><?= h(($backupDetail['label'] ?? '') !== '' ? $backupDetail['label'] : 'Konfiguration') ?></strong> · <?= h(date('d.m.Y H:i:s', (int) ($backupDetail['created_at'] ?? 0))) ?></p>
        <p>Die Übersicht prüft die Dateiverfügbarkeit. Archivierte Audiodateien werden beim Wiederherstellen auf Prüfsummen geprüft; vorhandene Tablet-Dateien werden wiederverwendet.</p>
        <details><summary>Einstellungen und Aktionen anzeigen</summary><pre><?= h(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details>
        <?php if (!$backupRestorable): ?><p class="lb-callout lb-callout-warning">Diese Sicherung kann noch nicht vollständig wiederhergestellt werden. Mindestens eine Sounddatei liegt weder im verwalteten Archiv noch unverändert auf dem Tablet vor. Die betroffenen Dateien erneut bereitstellen; es wird keine unvollständige Konfiguration vorgemerkt.</p>
        <ul><?php foreach ($backupDetail['missing_sounds'] ?? [] as $missing): ?><li>Sound <?= h($missing['id'] ?? '') ?> · <?= h($missing['name'] ?? '') ?><?= isset($missing['reason']) ? ' – ' . h($missing['reason']) : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if (ConfigForm::supportsBatteryManagement($snapshot) && array_intersect(array_keys(ConfigForm::legacyBatteryFields()),array_keys($details['settings'] ?? []))): ?><p class="lb-callout lb-callout-warning"><?= h(ConfigForm::LEGACY_BATTERY_RESTORE_NOTICE) ?> Die übrigen Einstellungen und Aktionen werden wiederhergestellt.</p><?php endif; ?>
        <p>Die Wiederherstellung ersetzt die gesicherten Einstellungen und Aktionslisten dieses Tablets. Sie wird als neue Änderung vorgemerkt und erst beim nächsten erfolgreichen Abruf vom Tablet geprüft und angewendet.</p>
        <form method="post" action="index.php?form=backups&amp;device=<?= h($deviceId) ?>"><?php csrf(); hidden('action', 'restore_backup'); hidden('device', $deviceId); hidden('revision', $revision); hidden('backup_id', $backupId); ?><button class="lb-btn lb-btn-primary" type="submit" <?= !$backupRestorable || $hasPendingBackup || $revision === '' ? 'disabled' : '' ?>>Wiederherstellung vormerken</button></form>
    </section>
    <?php endif; ?>
    <form class="lb-card" method="post" action="index.php?form=backups&amp;device=<?= h($deviceId) ?>" enctype="multipart/form-data">
        <?php csrf(); hidden('action', 'import_backup'); hidden('device', $deviceId); hidden('revision', $revision); ?>
        <h2 class="lb-section-title">Gespeicherte JSON-Sicherung laden</h2>
        <div class="lb-form-row"><label class="lb-form-label" for="backup-file">LOXi2DO-Sicherung</label><div class="lb-form-field"><input id="backup-file" name="backup_file" type="file" accept="application/json,.json" required></div></div>
        <p>JSON-Dateien enthalten Konfiguration, HTTP-Schaltadressen und Sound-Zuordnungen, keine Audiodateien oder Tablet-Tokens. HTTP-Adressen können vertrauliche Query-Parameter enthalten; Sicherungen privat aufbewahren. Verwaltete Audiodateien werden im privaten LoxBerry-Archiv aufbewahrt, solange sie für aktuelle Stände, Vormerkungen oder die sieben Sicherungen benötigt werden. Bei fehlenden Audiodateien wird die vollständige Wiederherstellung abgelehnt.</p>
        <p>Bis 16 MiB; zusätzlich gelten die vorhandenen PHP-Uploadgrenzen. Ein Import wird als neue Änderung für das ausgewählte Tablet vorgemerkt und vergibt keine Android-Berechtigungen.</p>
        <button class="lb-btn lb-btn-primary" type="submit" <?= $hasPendingBackup || $revision === '' ? 'disabled' : '' ?>>JSON prüfen und Wiederherstellung vormerken</button>
    </form>

    <?php elseif ($device !== null && $snapshot !== null && $form === 'config'): ?>
    <?php $management=$snapshot['management'] ?? []; $managementNeedsAttention=in_array($management['result']['state'] ?? '',['conflict','error'],true); ?>
    <section class="lb-card management-card">
        <div class="management-summary"><h2 class="lb-section-title"><?= uiIcon(!empty($management['pending_id']) ? 'clock' : 'check') ?> <?= h(stateLabel($management)) ?></h2><span class="management-date">Gemeldet: <?= empty($management['last_seen']) ? 'noch nicht' : h(date('d.m.Y H:i:s',(int)$management['last_seen'])) ?></span></div>
        <?php if (is_string($management['result']['warning'] ?? null) && $management['result']['warning']!==''): ?><p class="lb-callout lb-callout-warning"><?= h($management['result']['warning']) ?></p><?php endif; ?>
        <?php if (!empty($management['pending_id'])): ?><details class="pending-management" <?= $managementNeedsAttention ? 'open' : '' ?>><summary>Vormerkung verwalten</summary><p>Änderungen werden bei der nächsten Meldung übernommen, ab App 0.11.0 zu jeder vollen Minute. Die Formularwerte berücksichtigen bereits vorgemerkte Änderungen. <?= h($management['pending_sounds'] ?? 0) ?> Sounddateien vorgemerkt. Bei einem Konflikt bleiben die gewünschten Werte erhalten.</p>
        <?php if (!empty($management['result']['error'])): ?><p class="lb-callout lb-callout-warning"><?= h($management['result']['error']) ?></p><?php endif; ?>
        <form method="post" action="index.php?form=config&amp;device=<?= h($deviceId) ?>"><?php csrf(); hidden('device',$deviceId); hidden('revision',$revision); ?><button class="lb-btn" name="action" value="discard_pending">Vormerkung verwerfen</button> <button class="lb-btn" name="action" value="rebase_pending">Bewusst mit zuletzt gemeldetem Stand erneut vormerken</button></form></details><?php endif; ?>
    </section>
    <form method="post" id="settings-form" class="settings-form" action="index.php?form=config&amp;device=<?= h($deviceId) ?>">
        <?php csrf(); hidden('action', 'save_config'); hidden('device', $deviceId); hidden('revision', $revision); ?>
        <input type="hidden" name="active_group" id="settings-active-group" value="<?= h($activeSettingsGroup) ?>">
        <nav class="settings-menu" id="settings-menu" aria-label="Einstellungsbereiche">
            <?php foreach ($settingGroups as $groupId=>$group): ?><button type="button" class="settings-menu-button <?= $activeSettingsGroup===$groupId ? 'is-active' : '' ?>" data-settings-target="<?= h($groupId) ?>" aria-controls="group-<?= h($groupId) ?>" aria-pressed="<?= $activeSettingsGroup===$groupId ? 'true' : 'false' ?>"><?= uiIcon(in_array($groupId,['network','commands','autostart'],true) ? 'wifi' : (in_array($groupId,['display','battery'],true) ? 'battery' : 'settings')) ?><span><?= h($group[0]) ?></span></button><?php endforeach; ?>
        </nav>
        <nav class="settings-breadcrumb" id="settings-breadcrumb" aria-label="Navigationspfad">
            <span>Konfiguration</span><span aria-hidden="true">›</span><span><?= h(TabletStore::label($device)) ?></span><span aria-hidden="true">›</span><span>Einstellungen</span><span aria-hidden="true">›</span><strong id="settings-breadcrumb-current" aria-live="polite"><?= h($settingGroups[$activeSettingsGroup][0]) ?></strong>
        </nav>
        <div class="settings-grid">
        <?php foreach ($settingGroups as $groupId => $group): ?><section class="lb-card settings-group settings-<?= h($groupId) ?> <?= $activeSettingsGroup===$groupId ? 'is-active' : '' ?>" id="group-<?= h($groupId) ?>" data-settings-group="<?= h($groupId) ?>" data-group-title="<?= h($group[0]) ?>" aria-labelledby="group-title-<?= h($groupId) ?>" <?= $activeSettingsGroup!==$groupId ? 'hidden' : '' ?>>
        <header class="group-heading"><span class="group-icon"><?= uiIcon(in_array($groupId,['network','commands','autostart'],true) ? 'wifi' : (in_array($groupId,['display','battery'],true) ? 'battery' : 'settings')) ?></span><h2 class="lb-section-title" id="group-title-<?= h($groupId) ?>"><?= h($group[0]) ?></h2></header>
        <div class="settings-group-content">
        <?php if ($groupId === 'battery' && !batteryCareEditable($snapshot)): ?>
        <p class="lb-callout lb-callout-warning">Für die neue Akku-Steuerung die aktuelle LOXi2DO-APK installieren und den nächsten Managementabgleich abwarten. Die frühere Prozentautomatik wird nicht als externe Ladebegrenzung übernommen. Vorhandene Konfigurationen und Sicherungen bleiben erhalten.</p>
        <?php else: ?>
        <?php foreach ($group[1] as $key => $field):
            if ($groupId==='battery' && in_array($key,['battery_care_mode_enabled','charge_control_disable_care_while_charging'],true) && !fireCareEditable($snapshot)) { continue; }
            $value = $settings[$key] ?? ($field[1] === 'bool' ? false : '');
            $chargeDetail=$groupId==='battery' && in_array($key,['charge_control_minimum','charge_control_maximum'],true);
        ?>
        <?php if ($groupId==='battery' && $key==='charge_control_enabled'): ?><h3>Akkuladebegrenzung steuern</h3><?php endif; ?>
        <div class="lb-form-row setting-field <?= $field[1]==='bool' ? 'is-checkbox' : '' ?> <?= $field[1]==='textarea' ? 'is-wide' : '' ?>" <?= $chargeDetail ? 'data-charge-detail' : '' ?> <?= $key==='charge_control_disable_care_while_charging' ? 'data-fire-care-option' : '' ?>><label class="lb-form-label" for="setting-<?= h($key) ?>" <?= $field[1]==='bool' && isset($field[2]) ? 'title="'.h($field[2]).'"' : '' ?>><?= h($field[0]) ?></label><div class="lb-form-field">
            <?php if ($field[1] === 'bool'): ?><input id="setting-<?= h($key) ?>" type="checkbox" name="setting_<?= h($key) ?>" value="1" <?= $value ? 'checked' : '' ?> <?= isset($field[2]) ? 'title="'.h($field[2]).'"' : '' ?>>
            <?php elseif ($field[1] === 'textarea'): ?><textarea class="lb-input" id="setting-<?= h($key) ?>" name="setting_<?= h($key) ?>" rows="5"><?= h($value) ?></textarea><p><?= h($field[2]) ?></p>
            <?php elseif ($field[1] === 'select'): ?><select class="lb-input" id="setting-<?= h($key) ?>" name="setting_<?= h($key) ?>"><?php foreach ($field[2] as $option => $label): ?><option value="<?= h($option) ?>" <?= $value === $option ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
            <?php else: ?><input class="lb-input" id="setting-<?= h($key) ?>" type="<?= $field[1] === 'number' ? 'number' : 'text' ?>" name="setting_<?= h($key) ?>" value="<?= h($value) ?>" <?= $field[1] === 'number' ? 'min="' . h($field[2]) . '" max="' . h($field[3]) . '" required' : '' ?>><?php if ($field[1] === 'text' && isset($field[2])): ?><p><?= h($field[2]) ?></p><?php endif; ?>
            <?php endif; ?>
        </div></div><?php endforeach; ?>
        <?php endif; ?>
        <?php if ($groupId === 'battery' && batteryCareEditable($snapshot)): ?>
        <p>Unterhalb des Minimums sendet das Tablet den AN-Befehl, ab dem Maximum den AUS-Befehl. Dazwischen bleibt die letzte Schaltentscheidung erhalten. Es werden HTTP-GET-Anfragen an die hinterlegten Adressen gesendet; die tatsächliche Stromversorgung schaltet das externe Gerät. Vorgabe: 30 % / 70 %.</p>
        <?php if (fireCareEditable($snapshot)): ?><p>Der gewählte Fire-Akkuschonmodus wird beim App-Start, nach einem Neustart und während des Betriebs mit dem System abgeglichen. Die Zusatzoption setzt ihn während Laden AN aus und stellt ihn bei Laden AUS wieder her. Der gespeicherte Haken bleibt dabei erhalten.</p><?php endif; ?>
        <p><code>charge:1</code> sendet AN, <code>charge:0</code> sendet AUS. Ein angenommener manueller UDP-Auftrag beendet die Schwellenautomatik. Beide HTTP-Adressen müssen konfiguriert sein. Speichern stellt die Einstellungen für den nächsten erfolgreichen Tablet-Abruf bereit.</p>
        <h3>Zuletzt gemeldeter Akkustatus</h3>
        <?php if ($batteryCareStatus === null): ?><p>Noch kein Akkustatus vom Tablet gemeldet.</p>
        <?php else: $reportedMode = $batteryCareStatus['mode'] ?? null; ?>
        <?php if (fireCareEditable($snapshot)): ?><p><strong><?= $reportedMode === 1 ? 'Fire-Akkuschonmodus aktiv' : ($reportedMode === 0 ? 'Fire-Akkuschonmodus aus' : 'Fire-Akkuschonmodus unbekannt') ?></strong><?= ($batteryCareStatus['request_pending'] ?? false) === true ? ' · Änderung noch ausstehend' : '' ?></p><?php endif; ?>
        <?php if (is_string($batteryCareStatus['status'] ?? null) && $batteryCareStatus['status'] !== ''): ?><p><?= h($batteryCareStatus['status']) ?></p><?php endif; ?>
        <?php endif; ?>
        <p>Dies ist der zuletzt empfangene Ist-Zustand. Vorgemerkte Einstellungen bestätigen weder einen HTTP-Schaltvorgang noch eine Änderung am Laderegler. Die HTTP-Adressen werden nicht als MQTT-Status veröffentlicht.</p>
        <?php endif; ?>
        <?php if (array_key_exists('apps',$group[1])): ?>
        <div class="lb-form-row app-picker"><label class="lb-form-label" for="app-mapping-id">Installierte App zuordnen</label><div class="lb-form-field">
            <input class="lb-input" id="app-mapping-id" type="number" min="0" max="999999999" value="0" aria-label="App-ID">
            <input class="lb-input" id="app-package-search" type="search" placeholder="App-Name oder Paket suchen" aria-label="App-Liste durchsuchen">
            <select class="lb-input" id="app-package-select" aria-label="Installierte App"><?php foreach ($installedApps ?? [] as $installed): ?><option value="<?= h($installed['package'] ?? '') ?>" data-launchable="<?= ($installed['launchable'] ?? true) ? '1' : '0' ?>"><?= h($installed['label'] ?? $installed['package'] ?? '') ?> · <?= h($installed['package'] ?? '') ?><?= !empty($installed['system']) ? ' (System)' : '' ?></option><?php endforeach; ?></select>
            <label><input type="checkbox" id="app-show-all">Alle Pakete einschließlich nicht startbarer System-Pakete anzeigen</label>
            <p><button class="lb-btn" type="button" id="app-mapping-add">Zuordnung übernehmen</button></p><p id="app-mapping-message" role="status">Verwendet die zuletzt vom Tablet gemeldete Paketliste. System-Pakete ohne startbare Activity können nicht als App gestartet werden.</p>
        </div></div><?php endif; ?>
        </div></section><?php endforeach; ?>
        </div>
        <div class="settings-savebar"><div><strong id="settings-draft-state">Einstellungen für dieses Tablet</strong><span>Übernahme beim nächsten erfolgreichen Tablet-Abruf.</span></div><button class="lb-btn lb-btn-primary" type="submit"><?= uiIcon('check') ?> Speichern &amp; bereitstellen</button></div>
    </form>
    <details class="secondary-section" data-settings-extra="apps" <?= $activeSettingsGroup!=='apps' ? 'hidden' : '' ?>><summary>Installierte Apps nachschlagen</summary><form method="post" action="index.php?form=config&amp;device=<?= h($deviceId) ?>"><?php csrf(); hidden('device', $deviceId);  hidden('active_group','apps'); ?><button class="lb-btn" name="action" value="apps">Zuletzt gemeldete App-Liste anzeigen</button></form>
        <?php if (is_array($installedApps)): ?><table class="lb-table"><thead><tr><th>Name</th><th>Paketname für die App-Zuordnung</th></tr></thead><tbody><?php foreach ($installedApps as $app): ?><tr><td><?= h($app['label'] ?? '') ?></td><td><code><?= h($app['package'] ?? '') ?></code></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    </details>
    <details class="secondary-section"><summary>Einstellungen auf weitere Tablets übertragen</summary>
    <form method="post" action="index.php?form=config&amp;device=<?= h($deviceId) ?>">
        <?php csrf(); hidden('action', 'bulk'); hidden('device', $deviceId); hidden('revision', $revision); ?>
        <h2 class="lb-section-title">Gespeicherte Gruppen auf weitere Tablets übertragen</h2><p>Quelle: <?= h(TabletStore::label($device)) ?>. Verwendet den geladenen Stand einschließlich vorgemerkter Werte; Änderungen im Formular oben zuerst speichern und bereitstellen.</p>
        <fieldset class="device-options"><legend>Einstellungsgruppen</legend><?php foreach (ConfigForm::groups() as $id => $group): if ($id === 'battery' && !batteryCareEditable($snapshot)) { continue; } ?><label><input type="checkbox" name="groups[]" value="<?= h($id) ?>"><?= h($group[0]) ?></label><?php endforeach; ?></fieldset>
        <fieldset class="device-options"><legend>Aktionsgruppen vollständig ersetzen</legend><?php foreach (['notification', 'speech', 'alert', 'intent'] as $category): ?><label><input type="checkbox" name="action_groups[]" value="<?= h($category) ?>"><?= h($kinds[$category]) ?></label><?php endforeach; ?></fieldset>
        <fieldset class="device-options"><legend>Ziel-Tablets (höchstens acht)</legend><?php foreach ($tablets as $tablet): if ($tablet['id'] === $deviceId) { continue; } ?><label><input type="checkbox" name="targets[]" value="<?= h($tablet['id']) ?>"><?= h(TabletStore::label($tablet)) ?></label><?php endforeach; ?></fieldset>
        <p>Nur ausgewählte Gruppen werden überschrieben. Sounds vorher mit derselben ID auf alle benötigten Tablets importieren. Die Token und Management-Vertrauensfreigaben bleiben gerätespezifisch.</p><button class="lb-btn lb-btn-primary" type="submit">Ausgewählte Gruppen übertragen</button>
    </form></details>

    <?php elseif ($device !== null && $snapshot !== null && $form === 'actions'): ?>
    <form method="get" action="index.php" id="actions-menu-form">
        <?php hidden('form', 'actions'); hidden('device', $deviceId); ?>
        <nav class="settings-menu" id="actions-menu" aria-label="Aktionsbereiche">
            <?php foreach ($kinds as $category => $label): ?><button type="submit" name="kind" value="<?= h($category) ?>" class="settings-menu-button <?= $kind===$category ? 'is-active' : '' ?>" aria-pressed="<?= $kind===$category ? 'true' : 'false' ?>"><?= uiIcon($category) ?><span><?= h($label) ?></span></button><?php endforeach; ?>
        </nav>
    </form>
    <nav class="settings-breadcrumb" aria-label="Navigationspfad">
        <span>Konfiguration</span><span aria-hidden="true">›</span><span><?= h(TabletStore::label($device)) ?></span><span aria-hidden="true">›</span><span>Sounds &amp; Aktionen</span><span aria-hidden="true">›</span><strong id="actions-breadcrumb-current"><?= h($kinds[$kind]) ?></strong>
    </nav>
    <section id="actions-panel" class="lb-card settings-group actions-panel is-active" data-action-kind="<?= h($kind) ?>" aria-labelledby="actions-panel-title">
        <header class="group-heading"><span class="group-icon"><?= uiIcon($kind) ?></span><h2 class="lb-section-title" id="actions-panel-title"><?= h($kinds[$kind]) ?></h2></header>
        <div class="actions-panel-content">
        <div class="actions-list"><h3>Gespeicherte Einträge</h3><table class="lb-table"><thead><tr><th>ID</th><th>Name</th><th>Bearbeiten</th></tr></thead><tbody>
        <?php foreach ($snapshot['actions'][$kind] ?? [] as $entry): ?><tr><td><?= h($entry['id']) ?></td><td><?= h($entry['name']) ?></td><td><a class="lb-btn" href="?form=actions&amp;device=<?= h($deviceId) ?>&amp;kind=<?= h($kind) ?>&amp;edit=<?= h($entry['id']) ?>">Bearbeiten</a>
        <form class="inline-form" method="post" action="index.php?form=actions&amp;device=<?= h($deviceId) ?>&amp;kind=<?= h($kind) ?>"><?php csrf(); hidden('action', 'delete_action'); hidden('device', $deviceId); hidden('revision', $revision); hidden('kind', $kind); hidden('entry_id', (string) $entry['id']); ?><button class="lb-btn" type="submit">Entfernen</button></form></td></tr><?php endforeach; ?></tbody></table>
        <?php if (empty($snapshot['actions'][$kind])): ?><p>Noch keine Einträge in dieser Kategorie.</p><?php endif; ?>
        </div>
    <?php if ($kind !== 'sound' || $editAction !== null): $entry = $editAction ?? ['id' => '', 'name' => '', 'sound' => -1, 'mode' => 'activity']; ?>
    <form method="post" action="index.php?form=actions&amp;device=<?= h($deviceId) ?>&amp;kind=<?= h($kind) ?>" class="actions-editor">
        <?php csrf(); hidden('action', 'save_action'); hidden('device', $deviceId); hidden('revision', $revision); hidden('kind', $kind); ?>
        <h3><?= $editAction === null ? 'Eintrag hinzufügen' : 'Eintrag bearbeiten' ?></h3>
        <div class="actions-fields">
        <?php $fields = ['id' => 'ID', 'name' => 'Name']; if (in_array($kind, ['notification', 'alert'], true)) { $fields += ['title' => 'Titel', 'text' => 'Text', 'sound' => 'Sound-ID (-1 = ohne Sound)']; } elseif ($kind === 'speech') { $fields['text'] = 'Sprachtext'; } elseif ($kind === 'intent') { $fields += ['package' => 'Ziel-Paketname', 'action' => 'Intent-Aktion', 'mode' => 'Typ', 'data' => 'Daten-URI', 'mime' => 'MIME-Typ', 'extras' => 'Extras (schluessel=wert je Zeile)']; } ?>
        <?php foreach ($fields as $key => $label): ?><div class="lb-form-row <?= in_array($key, ['text', 'extras'], true) ? 'is-wide' : '' ?>"><label class="lb-form-label" for="entry-<?= h($key) ?>"><?= h($label) ?></label><div class="lb-form-field">
        <?php if (in_array($key, ['text', 'extras'], true)): ?><textarea class="lb-input" id="entry-<?= h($key) ?>" name="entry_<?= h($key) ?>" rows="5"><?= h($entry[$key] ?? '') ?></textarea>
        <?php elseif ($key === 'mode'): ?><select class="lb-input" id="entry-mode" name="entry_mode"><option value="activity" <?= ($entry['mode'] ?? '') === 'activity' ? 'selected' : '' ?>>Activity</option><option value="broadcast" <?= ($entry['mode'] ?? '') === 'broadcast' ? 'selected' : '' ?>>Broadcast</option></select>
        <?php else: ?><input class="lb-input" id="entry-<?= h($key) ?>" name="entry_<?= h($key) ?>" type="<?= in_array($key, ['id', 'sound'], true) ? 'number' : 'text' ?>" value="<?= h($entry[$key] ?? '') ?>" <?= $key === 'id' ? 'min="0" max="999999999" required' : ($key === 'sound' ? 'min="-1" max="999999999"' : '') ?> <?= $key === 'id' && $editAction !== null ? 'readonly' : '' ?>><?php endif; ?>
        </div></div><?php endforeach; ?>
        </div><div class="actions-form-buttons"><button class="lb-btn lb-btn-primary" type="submit">Änderung vormerken</button>
        <a class="lb-btn" href="?form=actions&amp;device=<?= h($deviceId) ?>&amp;kind=<?= h($kind) ?>">Neuer Eintrag</a>
        </div>
    </form><?php endif; ?>
    <?php if ($kind === 'sound'): ?><form method="post" action="index.php?form=actions&amp;device=<?= h($deviceId) ?>&amp;kind=sound" enctype="multipart/form-data" class="actions-upload">
        <?php csrf(); hidden('action', 'upload'); hidden('device', $deviceId); ?>
        <h3>Audiodatei auf Tablets importieren</h3>
        <div class="actions-fields">
        <div class="lb-form-row"><label class="lb-form-label" for="sound-id">Sound-ID</label><div class="lb-form-field"><input class="lb-input" id="sound-id" name="entry_id" type="number" min="0" max="999999999" required></div></div>
        <div class="lb-form-row"><label class="lb-form-label" for="sound-name">Name</label><div class="lb-form-field"><input class="lb-input" id="sound-name" name="entry_name" type="text" maxlength="100" required></div></div>
        <div class="lb-form-row is-wide"><label class="lb-form-label" for="sound-file">Audiodatei</label><div class="lb-form-field"><input id="sound-file" name="audio" type="file" accept="audio/*,.mp3,.wav,.ogg,.m4a,.flac,.aac,.opus" required><p>Bis 50 MiB pro Datei, zusätzlich gelten die vorhandenen PHP-Limits: upload_max_filesize <?= h(ini_get('upload_max_filesize')) ?>, post_max_size <?= h(ini_get('post_max_size')) ?>. LOXi2DO ändert diese Systemwerte nicht.</p></div></div>
        </div>
        <fieldset class="device-options"><legend>Ziel-Tablets (höchstens acht)</legend><?php foreach ($tablets as $tablet): ?><label><input type="checkbox" name="targets[]" value="<?= h($tablet['id']) ?>" <?= $tablet['id'] === $deviceId ? 'checked' : '' ?>><?= h(TabletStore::label($tablet)) ?></label><?php endforeach; ?></fieldset>
        <p>Die Datei wird privat auf dem LoxBerry bereitgestellt und bei der nächsten Tablet-Meldung heruntergeladen. Eine vorhandene gleiche Sound-ID wird ersetzt.</p><button class="lb-btn lb-btn-primary" type="submit">Audiodatei bereitstellen</button>
    </form><?php endif; ?>
        </div>
    </section>

    <?php elseif ($device !== null && $form === 'diagnostics'): ?>
    <section class="lb-card"><h2 class="lb-section-title">RAM-Diagnose vom Tablet</h2><form method="post" action="index.php?form=diagnostics&amp;device=<?= h($deviceId) ?>"><?php csrf(); hidden('device', $deviceId); ?><button class="lb-btn" name="action" value="diagnostics">Zuletzt gemeldetes Protokoll anzeigen</button></form>
        <p>Protokollierung ist auf dem Tablet standardmäßig aus und kann in der Konfiguration eingeschaltet werden.</p>
        <?php if ($ramDirectory===null): ?><p class="lb-callout lb-callout-warning">Keine verifizierte RAM-Ablage verfügbar. Diagnose-Einträge werden auf diesem LoxBerry nicht zwischengespeichert. Die Tablet-Konfiguration bleibt nutzbar.</p><?php else: ?><p>Die zuletzt gemeldeten Einträge werden ausschließlich in einer verifizierten RAM-Ablage zwischengespeichert.</p><?php endif; ?>
        <?php if ($diagnostics !== null): ?><p><?= !empty($diagnostics['enabled']) ? 'Protokollierung aktiv.' : 'Protokollierung deaktiviert.' ?></p><pre><?= h(is_string($diagnostics['entries'] ?? null) ? $diagnostics['entries'] : json_encode($diagnostics['entries'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre><?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($device!==null && $snapshot===null && in_array($form,['config','actions'],true)): ?><section class="lb-card empty-state"><span class="empty-icon"><?= uiIcon('clock') ?></span><h2>Warte auf die erste Tablet-Meldung.</h2><p>Aktiviere in der App unter Verbindung → Zugriff das Remote Management und hinterlege die LoxBerry-IP. Sobald das Tablet seinen Stand gemeldet hat, kannst du hier alle Einstellungen bearbeiten.</p><form method="post" action="index.php?form=config&amp;device=<?= h($deviceId) ?>"><?php csrf(); hidden('device',$deviceId); ?><button class="lb-btn" name="action" value="read">Gemeldeten Stand laden</button></form></section><?php endif; ?>
    <?php if ($device!==null): ?><form class="remove-device-form" method="post" action="index.php?form=config" data-confirm="Dieses Tablet aus LOXi2DO entfernen? Die App auf dem Tablet bleibt installiert."><?php csrf(); hidden('device',$deviceId); ?><button type="submit" class="text-button danger-text" name="action" value="remove_tablet">Tablet aus der Verwaltung entfernen</button></form><?php endif; ?>
        </main>
    </div>
    <?php endif; ?>
    <dialog id="tablet-dialog" aria-labelledby="tablet-dialog-title" class="tablet-dialog" data-auto-open="<?= $showTabletDialog ? '1' : '0' ?>">
        <div class="dialog-heading"><span class="device-symbol"><?= uiIcon('tablet') ?></span><button class="icon-button" type="button" data-dialog-close aria-label="Dialog schließen"><?= uiIcon('close') ?></button></div>
        <h2 id="tablet-dialog-title"><?= $deviceDraft['id']==='' ? 'Tablet hinzufügen' : 'Tablet-Zugang bearbeiten' ?></h2><p class="dialog-intro">Verbinde dein Tablet mit der Zentrale.</p>
        <?php if ($showTabletDialog && $error!==''): ?><p class="lb-callout lb-callout-warning" role="alert"><?= h($error) ?></p><?php endif; ?>
        <form method="post" id="tablet-access-form" action="index.php?form=config" autocomplete="off">
            <?php csrf(); hidden('action','save_tablet'); ?><input type="hidden" id="device-id" name="device" value="<?= h($deviceDraft['id']) ?>">
            <div class="dialog-field"><label for="device-name">Tablet-Name / Dashboard-Name (optional)</label><input class="lb-input" id="device-name" name="name" type="text" value="<?= h($deviceDraft['name']) ?>" maxlength="160" placeholder="Zum Beispiel Tablet Flur"><p>Leer lassen, um den bisherigen Gerätenamen zu verwenden.</p></div>
            <div class="dialog-field"><label for="device-host">Tablet-IP (IPv4)</label><input class="lb-input" id="device-host" name="host" type="text" value="<?= h($deviceDraft['host']) ?>" maxlength="15" placeholder="192.168.0.27" required inputmode="decimal"></div>
            <div class="dialog-field"><label for="device-token">LOXi2DO-Token</label><input class="lb-input" id="device-token" name="token" type="password" value="" autocomplete="new-password" maxlength="512" <?= $deviceDraft['id']==='' ? 'required' : '' ?>><p id="device-token-help"><?= $deviceDraft['id']==='' ? 'Den vorhandenen Token aus der LOXi2DO-App eintragen.' : 'Leer lassen, um den gespeicherten Token beizubehalten.' ?></p></div>
            <p class="dialog-note">In der Tablet-App Remote Management aktivieren und die IP dieses LoxBerry eintragen.</p>
            <div class="dialog-actions"><button class="lb-btn" type="button" data-dialog-close>Abbrechen</button><button class="lb-btn lb-btn-primary" type="submit">Zugang speichern <?= uiIcon('arrow') ?></button></div>
        </form>
    </dialog>
    <noscript><p class="lb-callout">Zum Öffnen der Tablet-Dialoge JavaScript aktivieren.</p></noscript>
</div>
<script><?php readfile(__DIR__.'/assets/interface.js'); ?></script>
<script>
(function () {
    var panel=document.getElementById('telemetry-panel');
    if (!panel) return;
    var groups=document.getElementById('telemetry-groups'), timer=null, request=null;
    var labels={info:'Info',akku:'Akku',wlan:'WLAN',display:'Display',system:'System',audio:'Audio',berechtigungen:'Berechtigungen',dienst:'Dienst'};
    var fields={name:'Gerätename',model:'Modell',manufacturer:'Hersteller',android_api:'Android-API',android_release:'Android-Version',
        version:'App-Version',security_patch:'Sicherheitspatch',battery_percent:'Akkustand (%)',percent:'Akkustand (%)',
        charging:'Lädt tatsächlich',plugged:'Stromversorgung angeschlossen',plugged_in:'Stromversorgung angeschlossen',
        temperature_c:'Temperatur (°C)',voltage_mv:'Spannung (mV)',current_ua:'Strom (µA)',health:'Akkuzustand',
        ssid:'WLAN-Name',rssi_dbm:'Signalstärke (dBm)',frequency_mhz:'Frequenz (MHz)',link_speed_mbps:'Verbindung (Mbit/s)',
        interactive:'Gerät wach',state:'Zustand',brightness:'Helligkeit',brightness_mode:'Helligkeitsmodus',
        screen_timeout_ms:'Display-Timeout (ms)',locked:'Gesperrt',available_bytes:'Verfügbar',total_bytes:'Gesamt',
        available_ram_bytes:'RAM verfügbar',total_ram_bytes:'RAM gesamt',uptime_seconds:'Laufzeit (s)',
        device_owner:'Device Owner',device_admin:'Geräteadministrator',overlay:'Overlay erlaubt',notifications:'Benachrichtigungen erlaubt',
        location:'Standortfreigabe',background_location:'Standort im Hintergrund',automatic_enabled:'Automatik aktiv',mode_enabled:'Fire-Schonmodus gewählt',charge_control_enabled:'Ladebegrenzung aktiv',charge_control:'Externe Ladebegrenzung',requested_on:'Laden angefordert',confirmed_on:'HTTP-Auftrag bestätigt',effective_mode:'Wirksamer Fire-Sollmodus',override_while_charging:'Schonmodus beim Laden aussetzen (Option)',disable_care_while_charging:'Schonmodus beim Laden aussetzen',
        minimum:'Untergrenze (%)',maximum:'Obergrenze (%)',mode:'Akkuschonmodus (0/1)',request_pending:'Änderung ausstehend',
        active:'Aktiv',udp_running:'UDP-Empfang aktiv',status:'Status',battery_care:'Akkuschonung',permissions:'Berechtigungen',
        system_name:'Systemname',android_version:'Android-Version',build_id:'Build',build_incremental:'Build-Version',
        app_version:'App-Version',app_version_code:'App-Versionscode',locale:'Sprache',timezone:'Zeitzone',
        present:'Akku vorhanden',power_source:'Stromquelle',full:'Voll geladen',technology:'Akkutyp',
        current_average_ua:'Strom im Mittel (µA)',charge_counter_uah:'Ladungszähler (µAh)',energy_nwh:'Restenergie (nWh)',
        charge_time_remaining_seconds:'Geschätzte Restladezeit (s)',care:'Akkuschonung',supported:'Unterstützt',
        enabled:'Eingeschaltet',connected:'Verbunden',signal_dbm:'Signalstärke (dBm)',link_mbps:'Verbindung (Mbit/s)',
        rx_link_mbps:'Empfang (Mbit/s)',tx_link_mbps:'Senden (Mbit/s)',standard:'WLAN-Standard',
        active_connected:'Aktive Netzwerkverbindung',transport:'Verbindungstyp',metered:'Getaktete Verbindung',
        validated:'Internetverbindung bestätigt',internet_capable:'Internetfähig',interface:'Netzwerkschnittstelle',
        ip_address:'IP-Adresse',addresses:'Netzwerkadressen',dns:'DNS-Server',gateways:'Gateways',
        keyguard_locked:'Sperrbildschirm aktiv',brightness_setting:'Eingestellte Helligkeit (0–255)',
        screen_timeout_seconds:'Display-Timeout (s)',rotation:'Drehung (0–3 Vierteldrehungen)',
        width_pixels:'Breite (Pixel)',height_pixels:'Höhe (Pixel)',density_dpi:'Pixeldichte (dpi)',
        power_save_mode:'Android-Energiesparmodus',thermal_status:'Thermische Warnstufe (0–6)',
        awake_seconds:'Wachzeit seit Neustart (s)',ram_total_bytes:'RAM gesamt',ram_available_bytes:'RAM verfügbar',
        low_memory:'Speicherknappheit gemeldet',low_memory_threshold_bytes:'RAM-Warnschwelle',
        storage_total_bytes:'Interner Speicher gesamt',storage_available_bytes:'Interner Speicher verfügbar',
        cpu_count:'Logische Prozessoren',supported_abis:'Unterstützte Prozessorvarianten',
        ringer_mode:'Klingelmodus',music_active:'Medienwiedergabe aktiv',music:'Medien',ring:'Klingeln',
        notification:'Benachrichtigungen',alarm:'Wecker',system:'System',voice_call:'Anruf',
        index:'Stufe',muted:'Stumm',location_enabled:'Standortdienst eingeschaltet',
        ignores_battery_optimizations:'Von Akkuoptimierung ausgenommen',receiver_active:'Empfängerdienst aktiv',
        receiver_status:'Empfängerdienst-Status',autostart_enabled:'Autostart eingeschaltet',
        autostart_paused:'Autostart pausiert',management_enabled:'Remote Management freigegeben',
        management_status:'Remote-Management-Status',diagnostics_enabled:'Protokollierung eingeschaltet'};
    function label(key,prefix) {
        if (key==='minimum' || key==='maximum') {
            if (prefix && prefix.indexOf('Ladebegrenzung')!==-1) return key==='minimum' ? 'Minimum (%)' : 'Maximum (%)';
            if (prefix && prefix.indexOf('Akkuschonung')!==-1) return key==='minimum' ? 'Untergrenze (%)' : 'Obergrenze (%)';
            return key==='minimum' ? 'Minimale Stufe' : 'Maximale Stufe';
        }
        return fields[key] || key.replace(/_/g,' ');
    }
    function text(value,key) {
        if (value===null || typeof value==='undefined') return 'Nicht verfügbar';
        if (typeof value==='boolean') return value ? 'Ja' : 'Nein';
        if (Array.isArray(value)) return value.length ? value.join(', ') : 'Keine';
        if (typeof value==='number' && /bytes$/.test(key)) {
            var units=['B','KiB','MiB','GiB','TiB'], index=0;
            while (Math.abs(value)>=1024 && index<units.length-1) { value/=1024; index++; }
            return value.toLocaleString(undefined,{maximumFractionDigits:index ? 1 : 0})+' '+units[index];
        }
        return typeof value==='object' ? JSON.stringify(value) : String(value);
    }
    function rows(table,object,prefix,depth,counter) {
        if (!object || typeof object!=='object') return;
        Object.keys(object).forEach(function (key) {
            if (counter.count>=160) return;
            var value=object[key], title=prefix ? prefix+' / '+label(key,prefix) : label(key,prefix);
            if (value!==null && typeof value==='object' && !Array.isArray(value) && depth<4) { rows(table,value,title,depth+1,counter); return; }
            var row=document.createElement('tr'), heading=document.createElement('th'), cell=document.createElement('td');
            heading.scope='row'; heading.textContent=title; cell.textContent=text(value,key);
            row.appendChild(heading); row.appendChild(cell); table.appendChild(row); counter.count++;
        });
    }
    function render(value) {
        if (!value || value.ok!==true) return;
        var reported=value.reported || {}, telemetry=reported.telemetry;
        if (!telemetry || typeof telemetry!=='object') {
            telemetry={info:{name:reported.name,model:reported.model,android_api:reported.android_api,version:reported.version},
                akku:{battery_percent:reported.battery_percent,plugged_in:reported.charging,battery_care:reported.battery_care},
                berechtigungen:reported.permissions || {},dienst:reported.receiver || {}};
        }
        groups.textContent='';
        Object.keys(labels).forEach(function (key) {
            if (!telemetry[key] || typeof telemetry[key]!=='object') return;
            var card=document.createElement('section'), title=document.createElement('h3'), table=document.createElement('table'), body=document.createElement('tbody');
            card.className='telemetry-group'; title.textContent=labels[key]; table.className='lb-table';
            rows(body,telemetry[key],'',0,{count:0}); table.appendChild(body); card.appendChild(title); card.appendChild(table); groups.appendChild(card);
        });
        var seen=Number(value.last_seen), age=seen ? Math.max(0,Number(value.server_time)-seen) : null;
        document.getElementById('telemetry-contact').textContent=age===null ? 'Noch kein Tablet-Kontakt empfangen.' :
            'Letzter Kontakt: '+new Date(seen*1000).toLocaleString()+' · vor '+Math.floor(age)+' Sekunden'+(age>45 ? ' · Älter als erwartet.' : '');
        var observed=document.getElementById('telemetry-observed'), sampled=Number(reported.sampled_at_ms);
        observed.hidden=!sampled;
        if (sampled) observed.textContent='Messzeitpunkt (Tabletuhr): '+new Date(sampled).toLocaleString()+
            (value.last_observed ? ' · zuletzt übernommen: '+new Date(value.last_observed*1000).toLocaleString() : '');
        var mqtt=value.mqtt, mqttText='Noch keine MQTT-Veröffentlichung bestätigt.';
        if (mqtt && typeof mqtt==='object') {
            mqttText='MQTT: '+(typeof mqtt.message==='string' && mqtt.message ? mqtt.message :
                mqtt.state==='published' ? 'An LoxBerry-MQTT übergeben; Zustellung unbestätigt' : mqtt.state==='pending' ? 'Veröffentlichung ausstehend' : 'Broker nicht verfügbar');
            if (mqtt.last_published) mqttText+=' · '+new Date(mqtt.last_published*1000).toLocaleString();
            if (mqtt.pending_topics) mqttText+=' '+mqtt.pending_topics+' Topics ausstehend.';
        }
        document.getElementById('telemetry-mqtt').textContent=mqttText;
        document.getElementById('telemetry-raw').textContent=JSON.stringify(value,null,2);
    }
    function enabled() { return panel.open && !document.hidden; }
    function schedule() { clearTimeout(timer); if (enabled()) timer=setTimeout(poll,5000); }
    function poll() {
        clearTimeout(timer); if (!enabled() || request) return;
        var controller=new AbortController(), timeout=setTimeout(function () { controller.abort(); },5000); request=controller;
        fetch('index.php?operation=telemetry&device='+encodeURIComponent(panel.getAttribute('data-device')),
            {method:'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal})
            .then(function (response) { if (!response.ok) throw new Error('status'); return response.json(); })
            .then(function (value) { if (value.ok!==true) throw new Error('status'); render(value); document.getElementById('telemetry-error').textContent=''; })
            .catch(function () { if (enabled()) document.getElementById('telemetry-error').textContent='Status konnte nicht nachgeladen werden. Die zuletzt angezeigten Werte bleiben erhalten.'; })
            .finally(function () { clearTimeout(timeout); request=null; schedule(); });
    }
    function update() { clearTimeout(timer); if (enabled()) poll(); else if (request) request.abort(); }
    panel.addEventListener('toggle',update); document.addEventListener('visibilitychange',update);
    window.addEventListener('pagehide',function () { clearTimeout(timer); if (request) request.abort(); });
    try { render(JSON.parse(document.getElementById('telemetry-initial').textContent)); } catch (ignored) { }
    update();
}());
(function () {
    var list=document.getElementById('app-package-select'), all=document.getElementById('app-show-all'), search=document.getElementById('app-package-search');
    if (!list || !all || !search) return;
    function filter() {
        var term=search.value.toLocaleLowerCase(), first=null;
        Array.prototype.forEach.call(list.options,function (option) {
            var visible=(all.checked || option.getAttribute('data-launchable')==='1') && option.textContent.toLocaleLowerCase().indexOf(term)!==-1;
            option.hidden=!visible; option.disabled=!visible; if (visible && first===null) first=option;
        });
        if (list.selectedIndex<0 || list.options[list.selectedIndex].disabled) { list.selectedIndex=first===null ? -1 : first.index; }
    }
    all.addEventListener('change',filter); search.addEventListener('input',filter); filter();
    document.getElementById('app-mapping-add').addEventListener('click',function () {
        var id=document.getElementById('app-mapping-id').value, target=document.getElementById('setting-apps'), message=document.getElementById('app-mapping-message');
        if (!/^(0|[1-9][0-9]{0,8})$/.test(id) || list.selectedIndex<0 || list.options[list.selectedIndex].disabled) { message.textContent='Gültige ID und eine App auswählen.'; return; }
        if (list.options[list.selectedIndex].getAttribute('data-launchable')!=='1') { message.textContent='Dieses Paket hat keine startbare Activity. Ein startbares Paket auswählen.'; return; }
        var lines=target.value.split(/\r?\n/), found=false;
        lines=lines.filter(function (line) { return line.trim()!==''; }).map(function (line) { if (line.split('=')[0].trim()===id) { found=true; return id+'='+list.value; } return line; });
        if (!found) lines.push(id+'='+list.value); target.value=lines.join('\n'); target.dispatchEvent(new Event('input',{bubbles:true})); message.textContent='Zuordnung ins Formular übernommen. Anschließend speichern und bereitstellen.';
    });
}());
</script>
<?php LBWeb::lbfooter(); ?>
