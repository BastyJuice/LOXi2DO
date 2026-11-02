# LOXi2DO

**LoxBerry-Plugin 0.9.1 · enthaltene Android-App 0.13.1**

[Installationspaket herunterladen](https://github.com/BastyJuice/LOXi2DO/raw/refs/heads/main/dist/LOXi2DO-0.9.1.zip)

Dieses Repository enthält das LoxBerry-Plugin und die installierbare APK. Die Android-Quellen sind hier nicht enthalten.

Zentrale Konfiguration mehrerer **LOXi2DO**-Tablets in der vorhandenen LoxBerry-Oberfläche. Das Plugin heißt vollständig **LOXi2DO**; Plugin-Bezeichner und Ordner lauten ab Version 0.4.0 `loxi2do`. Das Tablet meldet sich selbst beim LoxBerry, unmittelbar nach dem Managementstart und danach zu jeder vollen Minute; Statusänderungen werden zusätzlich fortlaufend übertragen. Am Tablet wird kein eingehender Management-Port geöffnet.

## Oberfläche ab Plugin 0.8.0

Beim Öffnen startet das **Dashboard** mit einer Übersicht aller registrierten Tablets. Karten zeigen letzten Kontakt, Akku, WLAN, Display, UDP und MQTT sowie ausstehende Konfigurationsänderungen. Die Übersicht aktualisiert sich alle fünf Sekunden, solange die Seite sichtbar ist. „Online“ bedeutet einen empfangenen Kontakt innerhalb der letzten 45 Sekunden; ältere Messwerte bleiben als zuletzt gemeldeter Stand erkennbar. Fehlende Messwerte werden nicht als Null oder ausgeschaltet dargestellt.

Ab Plugin **0.8.3** wählst du unter **Konfiguration** den gewünschten Einstellungsbereich über Menübuttons aus, zum Beispiel **Akkuschonung**. Ausschließlich der ausgewählte Bereich wird angezeigt; die übrigen Einstellungsboxen sind vollständig ausgeblendet. Der aktive Button und der zugehörige Bereich sind grün markiert. Der Brotkrumenpfad zeigt Tablet und Auswahl; beim Speichern bleibt die Auswahl erhalten. Die Akku-Untergrenze und -Obergrenze stehen untereinander. Die Hilfeboxen nutzen weiterhin die verfügbare Breite. Ab Plugin **0.8.5** hat die Hilfe denselben zentrierten Inhaltsrahmen wie Dashboard und Konfiguration.

Ab Plugin **0.8.4** verwendet auch **Sounds & Aktionen** diese Menübuttons: **Sounds**, **Mitteilungen**, **Sprachtexte**, **Alerts** und **Intents**. Nur die gewählte Kategorie mit ihren gespeicherten Einträgen und passenden Formularen ist sichtbar. Aktive Auswahl und Navigationspfad sind grün markiert; Liste, Bearbeitung und Sound-Import bilden einen gemeinsamen, gleich breiten Inhaltsbereich.

Unter **Konfiguration** bleibt die Tablet-Liste links sichtbar. Ein Tablet auswählen und rechts zwischen **Einstellungen**, **Sounds & Aktionen**, **Backups** und **Diagnose** wechseln. Die Einstellungen sind nach Funktionen gegliedert und werden weiterhin mit **Speichern & bereitstellen** für den nächsten Tablet-Abgleich vorgemerkt. Ausstehende Änderungen und Konflikte bleiben erkennbar; eine Speicherung meldet keine sofortige Übernahme am Tablet.

Mit **+ Tablet** öffnet sich der Dialog für Name, IP und den auf dem Tablet vorhandenen Token. Über **Zugang bearbeiten** lassen sich diese Angaben ändern; ein leeres Tokenfeld erhält den gespeicherten Token. Der Token wird niemals vorbefüllt. Downloads, Installation, MQTT-Topics und weitere Anleitungen stehen unter **Hilfe**. Die Oberfläche benötigt keine zusätzlichen JavaScript-Bibliotheken oder externen Dienste und passt sich schmalen Bildschirmen sowie dem gewählten LoxBerry-Theme an.

## Installation und vorhandene Laufzeit

`dist/LOXi2DO-0.9.1.zip` über die Pluginverwaltung eines LoxBerry 4 installieren. Enthält die Release-APK LOXi2DO 0.13.1 als `LOXi2.apk`. Die APK fordert nach dem Tablet-Boot einmalig die installierte Loxone-App im Vordergrund an und verwendet einen vorhandenen Launcher-Task weiter. Ohne wirksame Hintergrundstartfreigabe wartet sie auf das sichtbare Dashboard oder einen Tipp auf den Starthinweis; spätere L²-Rückkehr startet nicht erneut. Für den neuen Managementpfad wird App-Version 0.10.3 oder neuer benötigt. Die Installation auf dem Tablet bleibt bewusst gestartet; ab App 0.12.0 kann dafür das Update-Symbol im Dashboard verwendet werden. LOXi2DO verwendet vorhandenes PHP ab 7.4, JSON, hash, Sessions und Dateien/Dateisperren. Es installiert keine Pakete, zusätzliche PHP-Version, Composer-/Node-Abhängigkeiten oder Hintergrunddienste. cURL ist für das Pull-Verfahren nicht erforderlich.

Der offizielle LoxBerry-4-Installer wählt weiterhin PHP7.4 für Produktion und PHP8.4 für Tests; konkrete Installationen können abweichen. Belege: Plattformprüfung im Quellprojekt unter `docs/LOXBERRY_PLATFORM.md`, [offizieller Installer, fester Commit](https://github.com/mschlenstedt/Loxberry_Installer/blob/80a78101268cbf5169d9a1e002ede721ba1eaae6/install.sh). Die Installationsprüfung verwendet `/usr/bin/php` und meldet fehlende Funktionen, ohne Pakete nachzuinstallieren.

### Konfiguration beim Plugin-Upgrade erhalten

**Plugin 0.7.1 behebt den Verlust vorhandener Konfigurationen beim Upgrade.** Der LoxBerry-Installer entfernt beim Aktualisieren die bisherigen Plugin-Verzeichnisse für Konfiguration und Daten. Vor dieser Löschung sichert LOXi2DO jetzt die vollständigen Inhalte in einer privaten dauerhaften Ablage neben dem Datenverzeichnis. Nach der Installation werden Tablet-Einträge, Tokens, gewünschte Einstellungen, Aktionen, Konfigurationshistorie und verwaltete Sounds wiederhergestellt und anhand von Dateigröße und SHA-256 geprüft. Die Sicherung gehört genau zu diesem Installationsvorgang; abweichende neu angelegte Daten werden nicht überschrieben. Eine fehlgeschlagene Sicherung bricht vor der Löschung ab.

Während eines Updates sperrt die neue Version Plugin-Anfragen vorübergehend mit HTTP 503. Beim ersten Wechsel von einer älteren Version während der Installation keine Einstellungen bearbeiten: Deren bereits laufende Anfragen kennen die neue Updatesperre noch nicht. Nach erfolgreicher Prüfung bleibt die letzte vollständige Update-Sicherung unter `<LoxBerry>/data/plugins/.loxi2do-upgrade/` privat erhalten; ältere abgeschlossene Update-Sicherungen werden entfernt. Diese Ablage ist nicht Teil eines öffentlichen Downloads oder Konfigurationsexports. Die sieben Konfigurationsstände pro Tablet bleiben davon unabhängig.

Bei einem abgebrochenen Update bleibt die Sicherung mit ihrer Sperre bestehen. Nach Beseitigung des Installationsfehlers kann sie ausdrücklich wiederhergestellt werden:

```sh
/usr/bin/php /opt/loxberry/bin/plugins/loxi2do/migrate-legacy.php --recover /opt/loxberry/config/plugins/loxi2do /opt/loxberry/data/plugins/loxi2do
```

Falls der Installer die Programmdatei noch nicht kopiert hat, dieselbe `bin/migrate-legacy.php` aus dem entpackten **vollständigen** neuen Plugin-Paket verwenden. Bei abweichendem LoxBerry-Basisordner alle drei Pfade anpassen. Der Aufruf verwendet ausschließlich die geprüfte ausstehende Update-Sicherung und verweigert das Überschreiben abweichender Daten. Danach kann die Plugin-Installation erneut gestartet werden. Bereits durch frühere Releases gelöschte Daten werden durch diesen Fix nicht nachträglich rekonstruiert; dafür wird eine vorhandene frühere LoxBerry-Sicherung benötigt.

### Wechsel von Plugin 0.3.1 oder älter

Durch den neuen technischen Bezeichner kann LoxBerry LOXi2DO als neues Plugin statt als Update erkennen. Das neue ZIP installieren, bevor das vorherige Plugin entfernt wird. Bei der Installation werden vorhandene Tablet-Zugänge, Konfigurationen, Backups und verwaltete Sounds einmalig aus der bisherigen Ablage kopiert, sofern die neue Ablage noch leer ist. Die Quelldaten bleiben erhalten; eine bereits verwendete neue Ablage wird nicht zusammengeführt oder überschrieben.

Danach auf jedem Tablet LOXi2DO 0.10.3 oder neuer installieren, damit es `/plugins/loxi2do/api.php` abruft. Tablet-Einträge, Backups und Sounds in der neuen Plugin-Verwaltung prüfen. Das vorherige Plugin erst nach erfolgreicher Übernahme entfernen. Frühere JSON-Konfigurationssicherungen können weiterhin importiert werden; neue Exporte verwenden das Format `loxi2do-config-backup`.

## LOXi2.apk herunterladen und installieren

Nach der Plugin-Installation liegt die enthaltene APK unter dem festen öffentlichen LoxBerry-Pfad:

```text
http://<LoxBerry-IP>/plugins/loxi2do/LOXi2.apk
```

`<LoxBerry-IP>` durch die tatsächliche IP ersetzen. Der Dateiname bleibt bei Updates **LOXi2.apk**, ohne Versionsnummer. Der Download benötigt keine Tablet-Registrierung und keine LoxBerry-Adminanmeldung. Die APK enthält keine Tablet-Tokens oder persönliche Konfiguration. Voraussetzungen sind Android 11/API30 oder neuer, auch bei Fire OS zählt dessen Android-Basis. Ein Plugin-Update aktualisiert die bereitgestellte Datei. Ab App 0.12.0 kann eine neue passende Version über das Dashboard-Symbol installiert werden; die Erstinstallation und ältere APKs verwenden weiterhin den Download oder ADB.

### Direkt auf dem Tablet

1. Tablet mit dem lokalen Netz verbinden und die Download-Adresse im externen Browser öffnen, zum Beispiel Silk auf einem Fire-Tablet.
2. `LOXi2.apk` herunterladen und anschließend aus den Downloads öffnen.
3. Falls das System dazu auffordert, für den verwendeten Browser beziehungsweise die öffnende Dateien-App **„Unbekannte Apps installieren“ / „Aus dieser Quelle zulassen“** freigeben. Die Bezeichnung und der Einstellungsweg können unter Fire OS abweichen. Anschließend zur APK zurückkehren.
4. **Installieren** beziehungsweise **Aktualisieren** bestätigen und danach LOXi2DO öffnen. Bei einem Update mit derselben Signatur bleiben die vorhandenen App-Daten erhalten. Die Installationsfreigabe für den Browser kann anschließend wieder ausgeschaltet werden.

Android beschreibt die [Installation aus anderen Quellen](https://developer.android.com/distribute/marketing-tools/alternative-distribution). Die normale Tablet-Installation vergibt keinen Device-Owner-Status.

### Per ADB vom PC

Die offiziellen [Android SDK Platform Tools für Windows, macOS und Linux](https://developer.android.com/tools/releases/platform-tools?hl=de) herunterladen und entpacken. Sie enthalten ADB; Android Studio ist dafür nicht nötig. Anschließend USB-Debugging am Tablet aktivieren und den PC am Tablet bestätigen. In Windows PowerShell im Platform-Tools-Ordner ausführen:

```powershell
$loxBerryIp = "192.168.3.20" # Durch die eigene LoxBerry-IP ersetzen
Invoke-WebRequest -Uri "http://$loxBerryIp/plugins/loxi2do/LOXi2.apk" -OutFile ".\LOXi2.apk"
.\adb.exe devices
.\adb.exe install -r ".\LOXi2.apk"
```

Bei mehreren verbundenen Geräten die Seriennummer aus `adb devices` ausdrücklich auswählen:

```powershell
.\adb.exe -s "SERIENNUMMER" install -r ".\LOXi2.apk"
```

Unter Linux/macOS lautet der Installationsaufruf entsprechend `adb install -r ./LOXi2.apk`. Die Option `-r` aktualisiert die bestehende App und behält ihre Daten; die Signatur muss zum installierten LOXi2DO passen. Details: [offizielle ADB-Anleitung](https://developer.android.com/tools/adb).

## App-Updates vom Dashboard

Ab **App 0.12.0 / Plugin 0.7.0** ist kein erneuter Browser-Download am Tablet nötig:

1. Das neue Plugin auf dem LoxBerry installieren und die erste APK ab 0.12.0 einmal per Download oder ADB auf dem Tablet aktualisieren.
2. Auf dem Tablet unter **Verbindung → Zugriff** Remote Management mit der festen LoxBerry-IP und dem passenden Tablet-Token speichern. **App-Updates über LoxBerry prüfen** angehakt lassen. Die Auswahl gilt nur lokal; ohne aktives Management ist sie wirkungslos.
3. Bei künftigen Plugin-Releases liegt die neue APK wieder unter derselben Adresse. Das Tablet prüft beim Öffnen und beim Konfigurationsabruf auf eine neuere passende Version. Nur bei bestätigtem Update erscheint neben dem Fragezeichen im Dashboard ein grünes Update-Symbol.
4. Das Symbol antippen. Die App lädt und prüft die APK; die Statuszeile zeigt Fortschritt und Fehler. Doppelte Starts sind während des Vorgangs gesperrt.
5. Ohne Device Owner bei Aufforderung **LOXi2DO** als Installationsquelle freigeben, zur App zurückkehren und den Android-Installationsdialog bestätigen. Fire OS kann andere Bezeichnungen verwenden. Mit bereits eingerichtetem Device Owner kann Android den ausdrücklich angetippten Vorgang ohne zusätzlichen Installationsdialog ausführen.

Ein Versionshinweis allein installiert nichts. Die App prüft höhere Version, Paketkennung `de.loxi2`, Dateigröße, SHA-256 und dieselbe vertrauenswürdige Signatur der bestehenden Installation. Fremde Signaturen und Downgrades werden abgelehnt. Ein normales Update erhält Einstellungen und Token; es vergibt keinen Device-Owner-Status und braucht keinen Root-Zugriff.

Die öffentliche Versionsdatei liegt unter `/plugins/loxi2do/LOXi2-update.json`. Sie enthält keine Zugangsdaten. Die App verwendet für ihre Entscheidung die HMAC-authentifizierte Update-Antwort von `api.php`; eine Prüfsumme von einer öffentlichen HTTP-Adresse allein wird nicht als Echtheitsnachweis behandelt. Die APK bleibt unter `/plugins/loxi2do/LOXi2.apk`. Bei unbekannter oder nicht neuer Version bleibt das Symbol ausgeblendet. Die lokale Update-Prüfung lässt sich unter Verbindung → Zugriff ausschalten und speichern.

## Tablets verbinden

1. LOXi2DO ab Version 0.10.3 mit dem neuen LoxBerry-Managementpfad installieren.
2. Auf dem Tablet unter Verbindung → Zugriff „Remote Management durch LOXi2DO“ aktivieren, die feste LoxBerry-IP hinterlegen und speichern. Der bestehende LOXi2DO-Token muss nicht leer sein, auch wenn UDP-Legacy aktiv ist.
3. In LOXi2DO unter **Konfiguration → + Tablet** jedes Tablet mit Name, tatsächlicher privater IPv4-Absenderadresse und demselben Token hinzufügen. Tokenfelder werden nie vorbefüllt; leere Felder erhalten bei Änderungen den gespeicherten Token.
4. Das Tablet sendet seinen tatsächlichen Stand an den Standardpfad **`/plugins/loxi2do/api.php`**. Im Plugin erscheint seine letzte Meldung.
5. Das Tablet auswählen, Einstellungen ändern und **Speichern & bereitstellen** wählen. Dadurch wird der Auftrag dauerhaft auf dem LoxBerry gespeichert. Das Tablet prüft beim nächsten Abruf seine Konfigurationsrevision, übernimmt die Änderung und bestätigt anschließend das Ergebnis.

IP-Adressen im Router reservieren. Tablet und LoxBerry müssen sich im lokalen Netz erreichen. Dieses Plugin verwendet keine NAT-/Proxy-Header zur Tabletidentifikation: Die tatsächliche HTTP-Absender-IP muss mit dem eingetragenen Tablet übereinstimmen.

## Formulare und Sammelverwaltung

### Live-Status und MQTT ab Plugin 0.6.0 / App 0.11.0

Der Konfigurationsabgleich erfolgt sofort beim Managementstart und anschließend zu jeder vollen Minute der Tablet-Systemuhr, beispielsweise um 12:00:00 und 12:01:00. Statusdaten werden davon getrennt fortlaufend übermittelt: Die App prüft Messwerte etwa alle fünf Sekunden und meldet Änderungen; wichtige Android-Ereignisse stoßen zusätzlich eine Meldung an. Spätestens nach 30 Sekunden wird der Kontakt erneuert. Android-Ruhemodus, Netzfehler oder eine noch laufende Übertragung können die tatsächliche Zustellung verzögern. Ältere APKs behalten ihren bisherigen Meldetakt.

Unter **Konfiguration → Live-Gerätestatus** erscheinen die zuletzt empfangenen Gruppen **Info**, **Akku**, **WLAN**, **Display**, **System**, **Audio**, **Berechtigungen** und **Dienst**. Bei geöffneter und sichtbarer Statusansicht werden die Werte alle fünf Sekunden nachgeladen; das Konfigurationsformular und seine ungespeicherten Eingaben bleiben unverändert. Der angezeigte letzte Kontakt ist ein Empfangszeitpunkt, keine dauerhafte Online-Garantie.

Ab Plugin **0.7.1** veröffentlicht das Plugin Statuswerte direkt beim konfigurierten LoxBerry-MQTT-Broker, wie das ENPHASE-Plugin. Die vorhandene Funktion `mqtt_connectiondetails()` liefert Host, Port, Anmeldung und gegebenenfalls TLS-Einstellungen. Der Transport verwendet vorhandene PHP-Streams und benötigt weder den UDP-Gateway noch die alte phpMQTT-Bibliothek oder zusätzliche Pakete. Broker-Zugangsdaten bleiben auf dem LoxBerry.

Die Werte werden mit **QoS 1 und Retain** gesendet. Erst wenn der Broker jede Veröffentlichung bestätigt hat, zeigt die Statusansicht **„Vom MQTT-Broker bestätigt (QoS 1)“**. Das bestätigt die Annahme durch den Broker, nicht die Verarbeitung durch einen bestimmten Empfänger. Verbindungs-, Anmelde-, TLS- und Zeitfehler erscheinen getrennt vom Tablet-Kontakt. Ein Versuch ist auf 2,5 Sekunden Netzwerkzeit begrenzt; bei Fehlern bleibt der neueste Stand im RAM und wird mit einer späteren Tablet-Meldung erneut versucht. Konfigurationsabgleich und Statusannahme bleiben auch ohne erreichbaren Broker verfügbar.

Ab Plugin **0.8.1** ist die Basis **`loxi2do/<Tablet-Name>/`**, ohne leere Ebene am Anfang oder weitere ID-Ebene. Verwendet wird der unter Konfiguration eingetragene Tablet-Name, zum Beispiel **`loxi2do/Tablet Flur/`**. Ohne Namen wird die feste Tablet-IP verwendet. Bei gleichen Namen erhalten die Zweige einen eindeutigen IP-Zusatz. Die konkrete Basis steht beim Tablet im Live-Gerätestatus. Leerzeichen und Umlaute bleiben erhalten; `%`, `/`, `+` und `#` im Namen werden eindeutig als `%25`, `%2F`, `%2B` und `%23` kodiert. Beispiele für Topic-Gruppen:

```text
loxi2do/<Tablet-Name>/Info/name
loxi2do/<Tablet-Name>/Info/last_seen
loxi2do/<Tablet-Name>/Akku/percent
loxi2do/<Tablet-Name>/Akku/temperature_c
loxi2do/<Tablet-Name>/Akku/care/mode
loxi2do/<Tablet-Name>/WLAN/ssid
loxi2do/<Tablet-Name>/WLAN/signal_dbm
loxi2do/<Tablet-Name>/Display/…
loxi2do/<Tablet-Name>/System/…
loxi2do/<Tablet-Name>/Audio/…
loxi2do/<Tablet-Name>/Berechtigungen/…
loxi2do/<Tablet-Name>/Dienst/…
```

Mit **`loxi2do/#`** alle Tablet-Statuswerte abonnieren. Es gibt keinen führenden Schrägstrich; Groß-/Kleinschreibung und Leerzeichen gehören zum Topic. Vorhandene Abonnements auf die alte Struktur anpassen. Skalare Werte werden als Zahl, Text oder `0`/`1` veröffentlicht und retained gespeichert. Nicht verfügbare Werte bleiben unbekannt; fällt ein Wert weg, wird sein bisheriger retained MQTT-Wert entfernt. Die technische Tablet-ID bleibt als Info-Wert verfügbar und ist keine zusätzliche Hierarchieebene; Tokens werden nicht veröffentlicht. Konfiguration, private Schlüssel, Tokens und rohe Diagnoseprotokolle gehören nicht zur MQTT-Telemetrie.

Beim nächsten Bericht wird die alte retained Struktur des betreffenden Tablets geprüft und bereinigt. Das gilt auch für frühere Zwischenebenen `0` oder `o`, sofern sie unter dem alten Plugin-Zweig zur eigenen Tablet-ID gehören. Ein neuer Name verschiebt die Werte in den neuen Zweig und entfernt bisherige eigene Namens-Topics. Bereits an ein anderes Tablet vergebene Zweige werden nicht gelöscht. Für diese Bereinigung benötigt der konfigurierte Broker-Zugang auch Leserechte auf die alten eigenen Topics; eine fehlgeschlagene Prüfung wird später erneut versucht und blockiert die Veröffentlichung der aktuellen Werte nicht.

Das Plugin merkt sich ausschließlich die zuletzt verwendeten Topic-Namen dauerhaft unter `data/plugins/loxi2do/mqtt_topics_<Tablet-ID>.json`. Diese private Liste ermöglicht die Bereinigung nach einem LoxBerry-Neustart oder Verlust des RAM-Caches. Sie enthält weder Messwerte noch Token und wird nur bei geänderter Topic-Struktur oder Migration aktualisiert; fortlaufende Messwerte bleiben im RAM. Eine Umbenennung verändert MQTT-Adressen, vorhandene Empfänger müssen den neuen Namen verwenden.

Zu den zusätzlichen Messwerten gehören, soweit vom Gerät bereitgestellt, tatsächlicher Ladezustand statt bloßem Netzteilanschluss, Akkutemperatur/-spannung/-strom, WLAN-Signal und Verbindung, Display-/Ruhezustand, freier RAM und Speicher, Laufzeit und Audiostufen. Fehlende Hardware-Unterstützung oder Berechtigungen erscheinen als nicht verfügbar statt als erfundener Nullwert. WLAN-Namen benötigen passende Standortfreigaben; Herstellerwerte wie der Fire-Akkuschonmodus bestätigen weiterhin keinen exakten Prozent-Ladestopp.

Häufige Statusdaten und die Replay-Prüfung werden in einer verifizierten RAM-Ablage verarbeitet, um die SD-Karte zu schonen. Konfiguration, Vormerkungen, Backups und Sounds behalten ihre bisherige dauerhafte Ablage. Nach einem LoxBerry-Neustart erneuert die nächste Tablet-Meldung den flüchtigen Status.

### Tablet-Name im Dashboard

Der optionale **Tablet-Name** in der Tabletverwaltung erscheint ab LOXi2DO 0.10.1 nach dem nächsten erfolgreichen Abruf als **`Dashboard | Loxone Tablet Flur`**. Bereits eingetragene Namen werden verwendet. Das Feld leer lassen und speichern, um wieder den normalen Android-Gerätenamen zu zeigen. Namen dürfen bis zu 160 UTF-8-Bytes umfassen und keine Steuerzeichen oder Zeilenumbrüche enthalten. Bei einem leeren Namen zeigt die Plugin-Liste zur Unterscheidung die Tablet-IP.

Die Übertragung erfolgt als signierte Gerätemetadaten, auch wenn keine Konfigurationsänderung vorgemerkt ist. Ein bestätigter Name bleibt auf dem Tablet offline erhalten, solange derselbe lokal freigegebene LoxBerry und Token gelten. Ausschalten des Managements oder ein Wechsel der Vertrauensdaten verwendet wieder den Systemnamen. Ältere Plugin-Antworten ohne Namen führen ebenfalls zum Systemnamen. Ältere APKs ignorieren das neue Antwortfeld und behalten ihre bisherige Anzeige.

Der Name gehört zum Eintrag in der Tabletverwaltung. Er ist kein zusätzliches App-Konfigurationsfeld und wird durch einen Konfigurations-Restore nicht zurückgesetzt. Der gemeldete Gerätestatus enthält weiterhin den nativen Android-Namen. Amazons separat gespeicherter Fire-Gerätename wird nicht ausgelesen.

### Zentrale Einstellungen

* Die bisherigen 25 freigegebenen Einstellungen sowie sieben neue Akku-Einstellungen ergeben auf APKs mit V2-Unterstützung 32 Werte: UDP-Port, Quellen, Legacy, Präfix, Apps, Webseiten und UDP-Zuordnungen; interner/externer Browser, Vollbild, JavaScript; Display-Fallback, Instant off, CPU-Wachhaltung, L/L²; Heim-WLAN, Autostart; sieben Funktionsschalter; Diagnose und Autoscroll; Fire-Akkuschonmodus; externe Ladeautomatik mit AN-/AUS-Adressen und Minimum/Maximum; Fire-Schonmodus während des Ladens aussetzen.
* App-Auswahl aus dem zuletzt vollständig gemeldeten Paketbestand mit Name, Paketname und App-ID. Standardmäßig werden startbare Apps angeboten; alle Pakete einschließlich nicht startbarer Systempakete lassen sich zum Nachschlagen einblenden. „Zuordnung übernehmen“ füllt zunächst nur das Formular.
* Sounds, Mitteilungen, Sprachtexte, Alerts und Intents mit nativen Formularen anlegen, ändern und entfernen.
* Eine Audiodatei für bis zu acht Tablets bereitstellen. Jedes Tablet lädt seine freigegebene Datei signiert herunter und legt sie in seinem privaten Sound-Speicher ab. Übertragung bis 50 MiB, zusätzlich gelten die bestehenden PHP-Upload-/POST-Limits. Die Maske zeigt deren Werte an und verändert keine PHP-Systemeinstellungen.
* Ausgewählte Einstellungsgruppen und vollständige Aktionskategorien für bis zu acht Ziele pro Vorgang vormerken. Nicht ausgewählte Gruppen bleiben erhalten. Klare Ergebnisse pro Tablet; ein Fehler beendet nicht den gesamten Sammelvorgang.
* Sounds auf mehreren Tablets mit derselben ID bereitstellen. Private Sound-Dateinamen eines Quell-Tablets werden nicht auf andere Tablets kopiert. Mitteilungen und Alerts benötigen dort die referenzierte Sound-ID.
* Gemeldeten Gerätestatus, Berechtigungen und RAM-Diagnose anzeigen. Die Meldungen sind Momentaufnahmen, keine direkte Bildschirmfernsteuerung; ab App 0.11.0 werden Statusänderungen fortlaufend gemeldet.

### Dauerbetrieb und Akku

Ab **App 0.13.0 / Plugin 0.9.0** sind Fire-Akkuschonung und externe Ladebegrenzung getrennt. Der Bereich benötigt die ausdrücklich vom Tablet gemeldete Fähigkeit `battery_management_v2`. Auf älteren APKs bleiben die vorhandenen Einstellungen und sieben Sicherungen erhalten; die neue Maske fordert zunächst die aktuelle APK an. Eine frühere Prozentautomatik wird weder automatisch auf externe Schaltbefehle umgedeutet noch für ein Ladegerät aktiviert.

**Akkuschonmodus aktivieren** erscheint nur bei gemeldeter Unterstützung der Fire-Firmware. Die Checkbox speichert die gewünschte spannungssenkende Betriebsart. Die App gleicht sie beim Start, nach einem Neustart und während des Betriebs mit Fire OS ab und fordert sie bei Abweichungen erneut an. Der kurze Tooltip lautet: „Senkt die Ladespannung und schont den Akku im Dauerbetrieb.“ Der Modus ist keine genaue Prozent-Ladegrenze.

**Akkuladebegrenzung steuern** ist auch auf anderen Android-Tablets verfügbar. Zwei absolute HTTP(S)-Adressen werden per **GET** aufgerufen, zum Beispiel:

```text
AN:  http://IP-SHELLY/relay/0?turn=on
AUS: http://IP-SHELLY/relay/0?turn=off
```

Unterhalb des **Minimums** sendet das Tablet AN; ab dem **Maximum** AUS. Dazwischen bleibt die letzte Schaltentscheidung bestehen. Vorgabe: **30 % / 70 %**, ganze Werte mit **1 ≤ Minimum < Maximum ≤ 100**. Die beiden Prozentfelder stehen untereinander und werden bei gewählter Automatik eingeblendet. Die HTTP-Adressen bleiben auch für manuelle UDP-Aufträge sichtbar. Die tatsächliche Stromversorgung schaltet das externe Relais. Eine erfolgreiche HTTP-Antwort bestätigt den angenommenen HTTP-Auftrag, keinen unabhängig gemessenen Relaiszustand. Beide Adressen müssen gesetzt sein; maximal 2048 Zeichen, keine Leer-/Steuerzeichen, Fragmente oder eingebetteten Benutzername-/Passwort-Angaben. Adressen können vertrauliche Query-Parameter enthalten und gehören zur privaten Konfiguration und ihren Sicherungen. Sie werden nicht an MQTT veröffentlicht.

Die Fire-Zusatzoption **Akkuschonmodus beim Laden automatisch deaktivieren** setzt einen gewählten Schonmodus bei Laden AN aus und stellt ihn bei Laden AUS wieder her. Der gespeicherte Haken für den Fire-Schonmodus bleibt dabei erhalten. Wenn der Fire-Schonmodus nicht gewählt ist, aktiviert die Zusatzoption ihn nicht.

Im Standardprofil sendet **`loxi2do:charge:1` AN** und **`loxi2do:charge:0` AUS**. Der frühere UDP-Aufruf für den Fire-Modus wird damit auf die externe Ladeversorgung umgestellt. Eigene Präfixe und Zuordnungen gelten weiterhin. Ein angenommener manueller UDP-Auftrag beendet die Schwellenautomatik, damit sie den Auftrag nicht direkt ersetzt; beide HTTP-Adressen müssen auch dafür konfiguriert sein.

Die sieben neuen Konfigurationsfelder sind `battery_care_mode_enabled`, `charge_control_enabled`, `charge_control_on_url`, `charge_control_off_url`, `charge_control_minimum`, `charge_control_maximum` und `charge_control_disable_care_while_charging`. **Speichern & bereitstellen** merkt sie für den nächsten erfolgreichen signierten Tablet-Abruf vor. Laufzeitstatus und gewünschte Konfiguration bleiben getrennt. Neue Sicherungen enthalten alle sieben Werte. Die früheren 25-/28-Feld-Sicherungen bleiben lesbar und wiederherstellbar. Beim Restore auf eine neue APK werden die drei früheren Akku-Automatikwerte ausgelassen; alle übrigen Einstellungen und Aktionen werden wiederhergestellt. Die sieben aktuellen Akku-Einstellungen bleiben erhalten. Die Vorschau und die vorgemerkte Wiederherstellung weisen darauf ausdrücklich hin; es findet keine Umdeutung in HTTP-Schaltbefehle statt.

## Offline-Verhalten und Konflikte

Gewünschte Werte liegen als ausstehender Auftrag auf dem LoxBerry. Ein derzeit offline befindliches Tablet kann Änderungen beim nächsten Abruf übernehmen. Die Maske zeigt vorgemerkte Werte und getrennt davon den Übernahmestatus.

Eine Auftrags-ID bleibt stabil, bis der Benutzer den Auftrag verändert. Die ursprüngliche Tablet-Basisrevision bleibt bei neuen Meldungen erhalten. Ein inzwischen lokal geänderter Stand wird nicht still überschrieben: Das Tablet meldet Konflikt oder Fehler; die Vormerkung bleibt zur Prüfung bestehen. Über „Bewusst mit zuletzt gemeldetem Stand erneut vormerken“ setzt der Benutzer eine neue Basis und Auftrags-ID, oder er verwirft die Vormerkung. Alte Bestätigungen können neuere Aufträge nicht löschen.

Sounds werden privat bereitgestellt; ausschließlich das entsprechende registrierte Tablet kann sie mit gültiger HMAC-Anfrage und einer aktuellen oder unter den letzten sieben tatsächlich ausgelieferten Soundaufträgen gespeicherten Auftrags-ID abrufen. Dateien bleiben erhalten, solange der gemeldete Stand, eines der sieben Backups oder ein ausstehender Auftrag darauf verweist. Für verspätete Bestätigungen werden zusätzlich die letzten sieben tatsächlich ausgelieferten Soundaufträge berücksichtigt. Unreferenzierte Dateien werden erst nach erfolgreichem Zustands-Commit entfernt. Eine verspätete Bestätigung ordnet die archivierte Datei dem tatsächlichen privaten Tablet-Dateinamen zu, ohne eine neuere Vormerkung zu löschen.

## Sieben Backups und Wiederherstellung

**Backups** zeigt pro Tablet bis zu sieben unterschiedliche bestätigte Konfigurationsstände. Die erste Meldung wird als Ausgangsstand gesichert; meldet das Tablet später eine geänderte Konfiguration, wird der bisherige Stand automatisch archiviert. Unveränderte Konfigurationsmeldungen erzeugen keine weiteren Sicherungen. Auch lokal am Tablet geänderte Einstellungen werden auf diese Weise erfasst, sobald sie gemeldet werden. Änderungen zwischen zwei Meldungen sind nicht einzeln rekonstruierbar.

* **Aktuellen gemeldeten Stand sichern** speichert den bestätigten Ist-Stand mit optionaler Bezeichnung. Vorgemerkte Werte werden dadurch nicht als bereits übernommen ausgegeben. Ein identischer Stand wird wiederverwendet und nicht mehrfach gezählt.
* **Ansehen / Wiederherstellen** zeigt Inhalt und verfügbare Sounds vorab. **Wiederherstellung vormerken** stellt die gesicherten Einstellungen und fünf Aktionskategorien als neuen, revisionsgeprüften Auftrag bereit. Auf unterstützten Tablets umfassen neue Sicherungen 32 Einstellungen; frühere Sicherungen ohne Akku-Einstellungen bleiben importierbar und setzen die aktuelle Akkuschonung nicht zurück. Erst das Tablet bestätigt die Übernahme. Eine bestehende Vormerkung muss vorher übernommen oder bewusst verworfen werden.
* **JSON herunterladen** sichert Einstellungen und Aktionsdefinitionen außerhalb des LoxBerry. **JSON importieren** prüft die Datei und stellt sie für das ausgewählte Tablet bereit. Token, Management-Freigabe, Vertrauensadresse, Systemrechte, Protokolle und Audiodaten sind nicht enthalten.

Über LOXi2DO bereitgestellte Audiodateien bleiben für die referenzierten Stände im privaten LoxBerry-Archiv erhalten und werden bei Bedarf erneut zum Tablet übertragen. Die Übersichtsseite prüft Verfügbarkeit und Länge; die vollständige SHA-256-Prüfung erfolgt vor erneuter Bereitstellung. JSON-Downloads enthalten nur die Sounddefinitionen und Prüfsummen, keine Audiodateien. Sie sind deshalb kein vollständiges Audio- oder LoxBerry-Systembackup.

Vorher nur lokal auf dem Tablet importierte Sounds können nicht nachträglich vom Tablet heruntergeladen werden. Solange die unveränderte Datei noch im aktuellen gemeldeten Stand liegt, kann sie wiederverwendet werden. Fehlt sie später auch im LOXi2DO-Archiv, zeigt die Oberfläche die betroffene ID und den Namen an und verhindert einen unvollständigen Restore. Die Audiodatei muss dann erneut bereitgestellt werden. Beim Import auf ein anderes Tablet müssen die Sounds dort oder in dessen eigenem verwalteten Archiv verfügbar sein.

Backups liegen außerhalb des Webroots unter `data/plugins/loxi2do/history_<Tablet-ID>`; Dateien sind 0600, Verzeichnisse 0700. Änderungen werden mit Dateisperre und atomarem Umbenennen gespeichert. Der achte unterschiedliche Stand verdrängt den ältesten. Für eine längere Aufbewahrung vorab JSON herunterladen und Audiodateien separat sichern.

## Umfang und Schutz

Der lokale Managementschalter, die LoxBerry-Vertrauensadresse, Tokenänderungen, Android-Berechtigungen, Device Owner und Root werden auf dem Tablet eingerichtet. LOXi2DO vergibt keine Android-Rechte und führt keine Reboot-/Display-/App-Aktionen aus. Die Live-Steuerung bleibt bei Loxone und UDP.

Die Maske liegt unter LoxBerrys authentifiziertem `htmlauth`-Pfad und verwendet wie das vorhandene enphase2lox-Plugin `LBWeb::lbheader`, native `lb-*`-Klassen und `LBWeb::lbfooter`. Jede schreibende Browseraktion benötigt den Session-CSRF-Token. Gerätezugänge liegen außerhalb des Webroots unter `config/plugins/loxi2do/tablets.json` (0600, Verzeichnis0700). Token werden nicht als Formularwert, JSON oder Protokoll ausgegeben. Updates liefern keine Konfigurationsdatei mit und erhalten bestehende Zugänge.

Der öffentliche Tablet-Endpunkt ist kein anonymer Konfigurationszugang. Er verlangt registrierte Quell-IP, HMAC-SHA256, Zeitstempel innerhalb von fünf Minuten und eine noch nicht verwendete Zufalls-Nonce. Die begrenzte Replay-Prüfung bleibt über PHP-Anfragen hinweg erhalten. Jede Antwort ist zusätzlich an Nonce, HTTP-Status und Bodydigest gebunden. Der Token selbst wird nicht übertragen.

HTTP verschlüsselt Konfiguration und Audiodaten nicht; Management gehört in ein vertrauenswürdiges lokales Netz. Alle Tablet-Adressen müssen numerische RFC1918-IPv4-Adressen sein. Beide Uhren synchron halten.

Gemeldete Konfiguration, Zustand, gewünschte Aufträge und Sounddateien liegen privat unter `data/plugins/loxi2do`. Diagnose-Einträge werden aus dem persistenten Zustands-JSON ausgeschlossen. LOXi2DO prüft über `/proc/mounts`, ob das tatsächliche Plugin-Logverzeichnis auf tmpfs, ramfs oder dem LoxBerry-zram-Loglaufwerk liegt. Bei PC-/VM-Installationen kann dieses Verzeichnis persistent sein; dann wird stattdessen eine verifizierte Ablage unter `/dev/shm` verwendet. Ist keine verifizierte RAM-Ablage verfügbar, werden die Einträge verworfen und die Diagnosemaske erklärt die Einschränkung. Die übrige Verwaltung funktioniert weiter. Die vorhandene PHP-Sitzung hält höchstens zwei Formular-Snapshots.

## Prüfung und ZIP

```sh
php loxberry/bin/check.php
php loxberry/tests/test.php
php loxberry/tests/history_test.php
python3 loxberry/tests/ui_test.py
python3 scripts/package-loxberry.py
```

Tests und SDK-Stubs sind Entwicklungswerkzeuge und werden nicht ausgeliefert. Die lokale Designvorschau verwendet unveränderte offizielle Header-Templates, deutsche Beschriftungen, JavaScript und Designsystem-Styles von LoxBerry4.0.0.15. Diese gespiegelten Dateien liegen ausschließlich unter `tests/official-lb4` und sind vom ZIP ausgeschlossen. Im installierten Plugin liefert der vorhandene LoxBerry die tatsächliche Oberfläche und das ausgewählte Theme. Der ZIP enthält Plugin, native PHP-Maske, signierten Pull-Endpunkt, öffentliche `LOXi2.apk`, Installationsprüfungen, Symbol und Dokumentation. Keine Tokens, Konfigurationsdaten oder Caches.

Der lokale Lauf mit PHP8.5 ersetzt keine Abnahme auf dem tatsächlichen LoxBerry mit PHP7.4. Reale Fire-OS-/LoxBerry-Abnahme bleibt zusätzlich erforderlich.

Copyright (c)2026 BastyJuice. MIT-Lizenz, siehe LICENSE.

## Sync-Information auf dem Tablet ab App 0.10.2

Die achte Dashboard-Kachel **Remote Management** zeigt mit LoxBerry-Symbol den letzten vollständig erfolgreichen Einstellungsabgleich. Sie hat keinen Schalter und aktiviert keinen Dienst. Abrufe ohne Änderung und vollständig bestätigte Übernahmen setzen den lokalen Zeitpunkt; Verbindungs-/Signaturfehler, ungültige Antworten und Konflikte tun das nicht. Die Anzeige bleibt offline verfügbar. Bei ausgeschaltetem Management wird eine vorhandene historische Zeit ausdrücklich als solcher Zustand angezeigt. Ein Serverwechsel entfernt die alte Zuordnung, ein anderer Token blendet sie aus. Die eigentliche Freigabe bleibt unter Verbindung → Zugriff.

Der sichtbare App-Name, Seitenkopf, Versionsfuß, Über-Menü und Splash heißen LOXi2DO. Paket de.loxi2 und der öffentliche Dateiname LOXi2.apk bleiben erhalten. Die Hilfeüberschrift lautet wie gewünscht Remote Management durch LoxBerry LOXi2DO.

## LOXi2DO unterstützen

Dir gefällt LOXi2DO und das Plugin erleichtert dir den Alltag? Wenn du meine Arbeit unterstützen möchtest, freue ich mich über einen Kaffee. Danke für deine Unterstützung!

[Spendier mir einen Kaffee · PayPal](https://www.paypal.com/donate/?hosted_button_id=FDJ5UHKLA8C7A)
