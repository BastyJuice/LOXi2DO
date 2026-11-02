<div align="center">

<img src="icons/icon.svg" width="96" alt="LOXi2DO Logo">

# LOXi2DO

**Control your Android**  
Tablets sind auch nur Aktoren mit Display.

Deine Android- und Fire-Tablets zentral im LoxBerry verwalten.

**[Plugin herunterladen](https://github.com/BastyJuice/LOXi2DO/releases/download/0.9.1/LOXi2DO-0.9.1.zip)** · [Einrichtung](#schnellstart) · [Forum](https://www.loxforum.com/forum/projektforen/loxberry/plugins/494519-loxi2do-android-tablet-control-tablets-sind-auch-nur-aktoren)

LoxBerry 4 · Android 11+ · Lokal im eigenen Netzwerk

</div>

---

LOXi2DO macht aus deinen Tablets steuerbare Geräte für dein Smart Home. Die Android-App übernimmt die Funktionen am Tablet, das LoxBerry-Plugin bündelt Konfiguration, Gerätestatus und Updates an einem Ort. Loxone steuert die Tablets über lokale UDP-Befehle.

## Alles an einem Ort

| Funktion | Das kannst du damit machen |
| --- | --- |
| **Dashboard** | Alle Tablets mit letztem Kontakt, Akku, WLAN, Display und Dienststatus im Blick behalten. |
| **Zentrale Konfiguration** | Verbindung, UDP-Befehle, Apps, Webansichten, Display, Energie und Audio je Tablet einstellen. |
| **Apps & Aktionen** | Gemeldete Apps mit Paketnamen auswählen sowie Sounds, Sprachtexte und weitere Aktionen verwalten. |
| **Akkumanagement** | Den Fire-Akkuschonmodus auf unterstützter Firmware konfigurieren und externe Ladegeräte über HTTP-Schaltbefehle mit Min-/Max-Grenzen steuern. |
| **Backups** | Bis zu sieben bestätigte Konfigurationsstände aufbewahren, exportieren und zur Wiederherstellung bereitstellen. |
| **MQTT** | Gerätestatus über den konfigurierten LoxBerry-Broker an andere Systeme weitergeben. |
| **App-Updates** | Die enthaltene APK vom LoxBerry laden und verfügbare Updates am Tablet über das Dashboard starten. |

## Schnellstart

Du brauchst einen **LoxBerry 4** und ein Tablet mit **Android 11 oder neuer** im selben lokalen Netzwerk. Bei Fire-Tablets zählt die zugrunde liegende Android-Version. Feste IP-Adressen erleichtern die Einrichtung.

### 1. Plugin installieren

Das **[Installationspaket herunterladen](https://github.com/BastyJuice/LOXi2DO/releases/download/0.9.1/LOXi2DO-0.9.1.zip)** und über die Pluginverwaltung des LoxBerry installieren.

**Enthalten:** Plugin **0.9.1** und Android-App **0.13.1**.

### 2. App auf dem Tablet installieren

Im Browser des Tablets diese Adresse öffnen und die APK installieren:

```text
http://<LoxBerry-IP>/plugins/loxi2do/LOXi2.apk
```

`<LoxBerry-IP>` durch deine eigene Adresse ersetzen. Falls Android nachfragt, die Installation für den verwendeten Browser zulassen.

Alternativ mit den offiziellen [Android Platform Tools](https://developer.android.com/tools/releases/platform-tools?hl=de) vom PC installieren:

```sh
adb install -r LOXi2.apk
```

### 3. Tablet verbinden

1. In der App unter **Verbindung → Zugriff** das Remote Management aktivieren und die **LoxBerry-IP** eintragen. Einen Token auf dem Tablet festlegen.
2. Im Plugin unter **Konfiguration → + Tablet** einen Namen, die **Tablet-IP** und denselben **Token** hinterlegen.
3. Die erste Meldung des Tablets abwarten. Anschließend die gewünschten Einstellungen wählen und **Speichern & bereitstellen** anklicken.

Das Tablet holt seine Konfiguration direkt beim Managementstart und danach **zu jeder vollen Minute** ab. Der Übernahmestatus zeigt, ob eine Änderung bestätigt wurde. Statusänderungen werden zusätzlich fortlaufend übertragen.

Der im Plugin vergebene Tablet-Name erscheint auch neben **Dashboard** in der App.

## Mit Loxone steuern

In Loxone Config einen virtuellen UDP-Ausgang auf die Tablet-IP anlegen:

```text
/dev/udp/<Tablet-IP>/5000
```

Der Standardpräfix lautet **`loxi2do:`**. In der App den Miniserver als erlaubten Absender eintragen, die benötigten Funktionen freigeben und **Steuerung starten**. Sender und App müssen denselben Token verwenden; alternativ lässt sich der Legacy-Modus ausdrücklich freigeben. Die App-Hilfe führt durch die Einrichtung und zeigt die zu deiner Konfiguration passenden Befehle.

## Status über MQTT nutzen

Die Topics verwenden den im Plugin hinterlegten Tablet-Namen:

```text
loxi2do/Tablet Flur/Info/name
loxi2do/Tablet Flur/Akku/percent
loxi2do/Tablet Flur/WLAN/signal_dbm
```

Mit **`loxi2do/#`** empfängst du alle Tablet-Statuswerte. Das Plugin nutzt die Broker-Konfiguration des LoxBerry und veröffentlicht mit **QoS 1 und Retain**. Weitere Gruppen liefern unter anderem Display-, System-, Audio- und Dienstinformationen, soweit das Tablet sie bereitstellt.

## Hilfe & Austausch

Die integrierte **Hilfe** erklärt Installation, Verbindung, Updates und die einzelnen Einstellungen. Für Fragen, Ideen und Rückmeldungen gibt es den [LOXi2DO-Thread im Loxforum](https://www.loxforum.com/forum/projektforen/loxberry/plugins/494519-loxi2do-android-tablet-control-tablets-sind-auch-nur-aktoren).

## Unterstützen

LOXi2DO hilft dir im Alltag? Dann freue ich mich über einen virtuellen Kaffee und deine Unterstützung bei der Weiterentwicklung.

**[☕ Spendier mir einen Kaffee](https://www.paypal.com/donate/?hosted_button_id=FDJ5UHKLA8C7A)**

---

Development by **[BastyJuice](https://github.com/BastyJuice)** · [MIT-Lizenz](LICENSE)
