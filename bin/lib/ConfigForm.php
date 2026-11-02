<?php
declare(strict_types=1);

namespace Loxi2;

final class ConfigForm
{
    public const LEGACY_BATTERY_RESTORE_NOTICE = 'Alte Akkuautomatik nicht übernommen; die aktuelle Akku-Konfiguration bleibt erhalten.';
    /** Android remains the authoritative validator; this describes the native configuration form. */
    public static function groups(): array
    {
        return [
            'network' => ['Verbindung und UDP', [
                'port' => ['UDP-Port', 'number', 1024, 65535],
                'sources' => ['Erlaubte Quell-IP/CIDR', 'textarea', 'Eine Miniserver-IP oder CIDR je Zeile; mehrere Werte auch mit Komma trennen.'],
                'legacy' => ['Legacy ohne Token zulassen', 'bool'],
                'trigger' => ['Befehlspräfix', 'text', 'Zum Beispiel loxi2do: einschließlich Doppelpunkt. Bestehende Sender müssen denselben Präfix verwenden.'],
            ]],
            'apps' => ['App-Launcher', [
                'apps' => ['App-Zuordnungen', 'textarea', 'Je Zeile ID=Paketname, zum Beispiel 0=com.loxone.kerberos. HOME öffnet den Standardlauncher.'],
            ]],
            'browser' => ['Webansichten', [
                'pages' => ['Webseiten-Zuordnungen', 'textarea', 'Je Zeile ID=HTTP(S)-Adresse. HTTP und HTTPS werden unterstützt.'],
                'browser_mode' => ['Browser', 'select', ['internal' => 'Intern in LOXi2DO', 'external' => 'Externer Browser']],
                'browser_fullscreen' => ['Interne Webansicht im Vollbild anzeigen', 'bool'],
                'javascript' => ['JavaScript in internen Webansichten erlauben', 'bool'],
            ]],
            'commands' => ['UDP-Befehle', [
                'mappings' => ['Befehlszuordnungen', 'textarea', 'Je Zeile Befehl=Funktion, ohne globalen Präfix. Bestehende Zuordnungen zuerst vom Tablet lesen.'],
            ]],
            'display' => ['Display und Energie', [
                'display_fallback_minutes' => ['Display-Fallback in Minuten', 'number', 0, 1440],
                'display_instant_off' => ['Bei display:0 sofort ausschalten (Instant off)', 'bool'],
                'cpu' => ['CPU während laufender Steuerung wach halten', 'bool'],
                'overlay' => ['Sichtbare LOXi2DO-/Loxone-Schaltfläche anzeigen', 'bool'],
            ]],
            'battery' => ['Dauerbetrieb und Akku', [
                'battery_care_mode_enabled' => ['Akkuschonmodus aktivieren', 'bool', 'Senkt die Ladespannung und schont den Akku im Dauerbetrieb.'],
                'charge_control_enabled' => ['Akkuladebegrenzung aktivieren', 'bool'],
                'charge_control_on_url' => ['Befehl für AN (HTTP-GET)', 'text', 'Zum Beispiel http://IP-SHELLY/relay/0?turn=on'],
                'charge_control_off_url' => ['Befehl für AUS (HTTP-GET)', 'text', 'Zum Beispiel http://IP-SHELLY/relay/0?turn=off'],
                'charge_control_minimum' => ['Minimum (%) – darunter Laden AN', 'number', 1, 100],
                'charge_control_maximum' => ['Maximum (%) – ab hier Laden AUS', 'number', 1, 100],
                'charge_control_disable_care_while_charging' => ['Akkuschonmodus beim Laden automatisch deaktivieren', 'bool', 'Bei Laden AN wird der gewählte Akkuschonmodus ausgesetzt, bei Laden AUS wiederhergestellt.'],
            ]],
            'autostart' => ['Heim-WLAN und Autostart', [
                'boot' => ['Autostart im gespeicherten Heim-WLAN', 'bool'],
                'home_ssid' => ['Heim-WLAN (SSID)', 'text', 'Exakter WLAN-Name. Die erforderlichen Standort-/WLAN-Freigaben müssen lokal auf dem Tablet vorliegen.'],
            ]],
            'features' => ['Funktionsfreigaben', [
                'feature_launcher' => ['Apps starten', 'bool'],
                'feature_display' => ['Display und Energie', 'bool'],
                'feature_browser' => ['Webansichten', 'bool'],
                'feature_notify' => ['Mitteilungen und Alerts', 'bool'],
                'feature_speak' => ['Sprachausgabe', 'bool'],
                'feature_toast' => ['Toast-Meldungen', 'bool'],
                'feature_volume' => ['Lautstärke', 'bool'],
            ]],
            'diagnostics' => ['Diagnose', [
                'diagnostics_enabled' => ['RAM-Protokoll aktivieren', 'bool'],
                'diagnostics_autoscroll' => ['Automatisches Scrollen im Tablet-Protokoll', 'bool'],
            ]],
        ];
    }

    public static function batteryKeys(): array
    {
        return array_keys(self::groups()['battery'][1]);
    }

    /** Retained only for reading old tablet reports/backups, never shown in the new form. */
    public static function legacyBatteryFields(): array
    {
        return ['battery_care_enabled'=>['Automatik (vorherige APK)', 'bool'],
            'battery_care_minimum'=>['Untergrenze', 'number', 1, 100],
            'battery_care_maximum'=>['Obergrenze', 'number', 1, 100]];
    }

    public static function hasBatterySettings(array $settings): bool
    {
        return count(array_intersect(self::batteryKeys(), array_keys($settings))) === count(self::batteryKeys());
    }

    public static function supportsBatteryManagement(array $snapshot): bool
    {
        return ($snapshot['capabilities']['battery_management_v2'] ?? false) === true
            && self::hasBatterySettings($snapshot['settings'] ?? []);
    }

    /** Snapshots/backups contain a whole schema; submitted edits may contain a supported subset. */
    public static function validateBatterySettings(array $settings, bool $complete = true): void
    {
        $present = array_intersect(self::batteryKeys(), array_keys($settings));
        $legacy = array_intersect(array_keys(self::legacyBatteryFields()), array_keys($settings));
        if ($present && $legacy) { throw new \InvalidArgumentException('Alte und neue Akku-Einstellungen dürfen nicht vermischt werden.'); }
        if ($legacy) {
            if (count($legacy)!==3 || !is_bool($settings['battery_care_enabled'])
                || !is_int($settings['battery_care_minimum']) || !is_int($settings['battery_care_maximum'])
                || $settings['battery_care_minimum']<1 || $settings['battery_care_maximum']>100
                || $settings['battery_care_minimum'] >= $settings['battery_care_maximum']) {
                throw new \InvalidArgumentException('Die bisherige Akku-Konfiguration ist unvollständig oder ungültig.');
            }
            return;
        }
        if (!$present) { return; }
        if ($complete && count($present)!==count(self::batteryKeys())) {
            throw new \InvalidArgumentException('Die Akku-Konfiguration ist unvollständig.');
        }
        foreach (['battery_care_mode_enabled','charge_control_enabled','charge_control_disable_care_while_charging'] as $key) {
            if (array_key_exists($key,$settings) && !is_bool($settings[$key])) { throw new \InvalidArgumentException('Akku-Schalter benötigt einen booleschen Wert.'); }
        }
        foreach (['charge_control_minimum','charge_control_maximum'] as $key) {
            if (array_key_exists($key,$settings) && (!is_int($settings[$key]) || $settings[$key]<1 || $settings[$key]>100)) {
                throw new \InvalidArgumentException('Akkugrenzen müssen ganze Prozentwerte von 1 bis 100 sein.');
            }
        }
        if (isset($settings['charge_control_minimum'],$settings['charge_control_maximum'])
            && $settings['charge_control_minimum'] >= $settings['charge_control_maximum']) {
            throw new \InvalidArgumentException('Das Minimum muss kleiner als das Maximum sein.');
        }
        foreach (['charge_control_on_url','charge_control_off_url'] as $key) {
            if (!array_key_exists($key,$settings)) { continue; }
            if (!is_string($settings[$key])) { throw new \InvalidArgumentException('Der HTTP-Schaltbefehl muss eine Adresse sein.'); }
            self::validateChargeUrl($settings[$key]);
        }
        if (($settings['charge_control_enabled'] ?? false) === true
            && (empty($settings['charge_control_on_url']) || empty($settings['charge_control_off_url']))) {
            throw new \InvalidArgumentException('Für die Ladebegrenzung werden beide HTTP-Schaltbefehle benötigt.');
        }
    }

    private static function validateChargeUrl(string $value): void
    {
        if ($value==='') { return; }
        $parts=parse_url($value);
        if (strlen($value)>2048 || preg_match('/[\\x00-\\x20\\x7f]/',$value) || $parts===false
            || !in_array(strtolower((string)($parts['scheme'] ?? '')),['http','https'],true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || isset($parts['port']) && ($parts['port']<1 || $parts['port']>65535)) {
            throw new \InvalidArgumentException('HTTP-Schaltbefehle benötigen eine absolute HTTP(S)-Adresse ohne Zugangsdaten, Leerzeichen oder Fragment (maximal 2048 Zeichen).');
        }
    }

    public static function posted(array $post, ?array $availableSettings = null): array
    {
        $result = [];
        foreach (self::groups() as $group) {
            foreach ($group[1] as $key => $field) {
                if ($availableSettings !== null && !array_key_exists($key, $availableSettings)) { continue; }
                $value = $post['setting_' . $key] ?? '';
                if (!is_string($value)) { throw new \InvalidArgumentException('Ungültiger Formularwert.'); }
                if ($field[1] === 'bool') { $result[$key] = $value === '1'; }
                elseif ($field[1] === 'number') {
                    if (!preg_match('/^(0|[1-9][0-9]{0,8})$/D', $value) || (int) $value < $field[2] || (int) $value > $field[3]) {
                        throw new \InvalidArgumentException($field[0] . ' muss zwischen ' . $field[2] . ' und ' . $field[3] . ' liegen.');
                    }
                    $result[$key] = (int) $value;
                } else {
                    if (strlen($value) > 131072) { throw new \InvalidArgumentException($field[0] . ' ist zu lang.'); }
                    if ($field[1] === 'select' && !array_key_exists($value, $field[2])) { throw new \InvalidArgumentException('Browserauswahl ist ungültig.'); }
                    $result[$key] = $value;
                }
            }
        }
        self::validateBatterySettings($result, false);
        return $result;
    }

    public static function selectedSettings(array $settings, array $groups): array
    {
        $result = [];
        $known = self::groups();
        foreach ($groups as $id) {
            if (!is_string($id) || !isset($known[$id])) { throw new \InvalidArgumentException('Unbekannte Einstellungsgruppe.'); }
            foreach (array_keys($known[$id][1]) as $key) {
                if (!array_key_exists($key, $settings)) { throw new \RuntimeException('Ausgelesene Konfiguration ist unvollständig.'); }
                $result[$key] = $settings[$key];
            }
        }
        self::validateBatterySettings($result);
        return $result;
    }

    public static function action(array $post, string $kind): array
    {
        if (!in_array($kind, ['sound', 'notification', 'speech', 'alert', 'intent'], true)) { throw new \InvalidArgumentException('Unbekannte Aktionskategorie.'); }
        $result = ['id' => self::id($post['entry_id'] ?? ''), 'name' => self::text($post, 'entry_name', 400)];
        if (in_array($kind, ['notification', 'alert'], true)) {
            $result['title'] = self::text($post, 'entry_title', 640);
            $result['text'] = self::text($post, 'entry_text', 32000);
            $sound = $post['entry_sound'] ?? '-1';
            $result['sound'] = $sound === '-1' ? -1 : self::id($sound);
        } elseif ($kind === 'speech') { $result['text'] = self::text($post, 'entry_text', 16000); }
        elseif ($kind === 'intent') {
            foreach (['package', 'action', 'mode', 'data', 'mime', 'extras'] as $key) { $result[$key] = self::text($post, 'entry_' . $key, 32768); }
        }
        return $result;
    }

    public static function id($value): int
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,8})$/D', $value)) { throw new \InvalidArgumentException('ID muss zwischen 0 und 999999999 liegen.'); }
        return (int) $value;
    }

    private static function text(array $post, string $key, int $limit): string
    {
        $value = $post[$key] ?? '';
        if (!is_string($value) || strlen($value) > $limit || strpos($value, "\0") !== false) { throw new \InvalidArgumentException('Aktionsfeld ist ungültig.'); }
        return $value;
    }
}
