#!/usr/bin/php
<?php
declare(strict_types=1);
require_once __DIR__.'/lib/LegacyMigration.php';
require_once __DIR__.'/lib/UpgradeStorage.php';
try {
    if ($argc===4 && $argv[1]==='--recover') {
        Loxi2\UpgradeStorage::recover($argv[2],$argv[3]);
        echo "<OK> LOXi2DO: ausstehende Update-Sicherung ausdrücklich lokal wiederhergestellt und geprüft. Update erneut starten.\n";
        exit(0);
    }
    if ($argc===5 && in_array($argv[1],['--backup','--install','--complete'],true)) {
        if ($argv[1]==='--backup') {
            Loxi2\UpgradeStorage::backup($argv[2],$argv[3],$argv[4]);
            echo "<OK> LOXi2DO: vollständige private Konfiguration und Daten vor dem Update geprüft gesichert.\n";
            exit(0);
        }
        if ($argv[1]==='--complete') {
            Loxi2\UpgradeStorage::complete($argv[2],$argv[3],$argv[4]);
            echo "<OK> LOXi2DO: Update-Konfiguration, Tokens, Sicherungen und Sounds vollständig geprüft; Update-Sperre aufgehoben.\n";
            exit(0);
        }
        if (Loxi2\UpgradeStorage::restore($argv[2],$argv[3],$argv[4])) {
            echo "<OK> LOXi2DO: private Konfiguration und Daten aus genau diesem Update wiederhergestellt.\n";
            exit(0);
        }
        $result=Loxi2\LegacyMigration::copy($argv[2],$argv[3]);
    } elseif ($argc===3) { $result = Loxi2\LegacyMigration::copy($argv[1],$argv[2]); }
    else { throw new InvalidArgumentException('Private Plugin-Zielordner und gültige Installationsoperation erforderlich.'); }
    if ($result==='copied') { echo "<OK> LOXi2DO: bisherige private Tablet-Einträge, Einstellungen, Backups und Sounds kopiert. Originale bleiben erhalten.\n"; }
    elseif ($result==='existing') { echo "<OK> LOXi2DO: vorhandene neue Plugin-Daten bleiben unverändert; keine Zusammenführung.\n"; }
} catch (Throwable $error) {
    fwrite(STDERR,"<ERROR> LOXi2DO: private Datenübernahme nicht abgeschlossen. Keine neuen Tablet-Einträge anlegen. Eine vorhandene Update-Sicherung bleibt zur Wiederherstellung erhalten.\n");
    exit(2);
}
