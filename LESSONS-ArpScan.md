# Symcon-Modulentwicklung – Erfahrungen, Fehler und Designentscheidungen

Wissensbasis aus der Entwicklung des Moduls **ArpScan** (Bibliothek `ArpScanLib`, Präfix `ARPS`, Version 1.4).
Zweck: Zu Beginn einer neuen Modul-Session hochladen bzw. einfügen, damit bekannte Fallstricke nicht erneut auftreten.

> **Hinweis für Claude beim Laden dieser Datei:** Die Regeln in Abschnitt 3 und 6 sind verbindliche Voreinstellungen für jedes neue Symcon-Modul, solange die Person nichts anderes sagt. Abschnitt 5 nennt, was noch nicht verifiziert ist – das nicht als gesichert darstellen.

---

## 1. Zielumgebungen (Fakten vom Nutzer)

| Umgebung | Fakt | Status |
|---|---|---|
| SymBox (Original-Hardware) | Raspberry Pi Compute Module, kein `arp-scan` im Dateisystem | vom Nutzer bestätigt |
| Symcon allgemein | PHP-`memory_limit` = **32 MB** (Fehlermeldung: `Allowed memory size of 33554432 bytes exhausted`) | vom Nutzer bestätigt |
| Symcon Windows 64 Bit | existiert und soll unterstützt werden; ARP-Befehl dort `arp -a` | vom Nutzer bestätigt |
| Modulpfad | `/var/lib/symcon/modules/<Repo>/<Bibliothek>/` (hier: `.../EMBS4Symcon/ArpScan/`) | aus Fehlermeldung |
| Kernel-Verzeichnis | `/var/lib/symcon/` (per `IPS_GetKernelDir()`) | aus Fehlermeldung |
| Modul-Ausführung auf SymBox | Modul läuft laut Nutzer „ohne Fehler“ – ob als root oder per Capability, wurde nicht geklärt | offen |

**Konsequenz:** Module immer so bauen, dass sie (a) mit 32 MB RAM auskommen, (b) auf Linux-ARM *und* Windows laufen oder sauber melden, dass sie es nicht tun, und (c) keine Systempakete voraussetzen.

---

## 2. Fehlerlog (Problem → Ursache → Lösung)

### F1 – `Undefined variable $Network` / `TypeError … must be of type string, null given`
- **Symptom:** Button-`onClick` `ARPS_ScanNetwork($id, $Network, $Bitcount);` lieferte `NULL`-Argumente → Fatal Error.
- **Ursache:** Formularfelder waren im `onClick` nicht als Variablen verfügbar (vermutlich wegen `RowLayout`/Bereich `elements` vs. `actions`; genaue Ursache nicht geklärt). Zusätzlich war die Funktionssignatur zu streng (`string $IP`).
- **Lösung:**
  1. `onClick` defensiv: `ARPS_ScanNetwork($id, $Network ?? '', $Bitcount ?? 0);`
  2. Funktionssignatur nullable: `public function ScanNetwork(?string $IP = '', ?int $Bitcount = 0)`, intern `(string)`/`(int)` casten.
  3. Leere Parameter fallen auf die **gespeicherte Konfiguration** zurück.
- **Regel:** Nie darauf verlassen, dass Formularvariablen im `onClick` existieren. Immer `?? default` und Fallback auf `ReadProperty*`.

### F2 – `Allowed memory size of 33554432 bytes exhausted (tried to allocate 10661872 bytes)`
- **Ursache:** Große Downloads (IEEE-OUI-CSV ≈ 10 MB, Alpine-`APKINDEX` mehrere MB) wurden als **PHP-String** geladen (`curl_exec` mit `RETURNTRANSFER`, `file_get_contents`, `php://temp`), dann zusätzlich kopiert/geparst.
- **Lösung:**
  - Downloads **direkt in eine Datei** (`CURLOPT_FILE` bzw. `copy($url,$file,$ctx)`).
  - CSV mit `fgetcsv` **zeilenweise** aus der Datei lesen.
  - Gzip-Tar **als Datenstrom** lesen (`gzopen`/`gzread` in Chunks, Header 512 Byte, Body in 64-KB-Stücken), nur nötige Felder behalten.
  - Lookup-Dateien **nicht komplett in ein Array laden**, sondern nur die benötigten Schlüssel beim Durchlesen sammeln (`lookupOui`).
  - Zusätzlich `@ini_set('memory_limit','128M')` bei speicherintensiven Aktionen (Wirkung abhängig von der Installation, nicht verlassen darauf).
- **Regel:** Alles > ca. 1 MB streamen. Nie ganze Dateien in Strings halten, nie große Arrays aufbauen, wenn ein Durchlauf mit Filter reicht.

### F3 – `filesystem error: cannot remove: Directory not empty [.../ArpScan/runtime]`
- **Ursache (wahrscheinlich):** Beim Aktualisieren der Bibliothek räumt Symcon den Modulordner auf; ein zur Laufzeit angelegter Unterordner (`runtime/`) gehört nicht zum Paket und ist nicht leer → Entfernen schlägt fehl.
- **Lösung:** Laufzeitdaten (heruntergeladene Binaries, Datenbanken) **außerhalb des Modulordners** ablegen: `rtrim(IPS_GetKernelDir(),'/\\') . '/<Modulname>'`. Alten Ordner beim `ApplyChanges` per `migrateOldRuntime()` verschieben (`rename`) oder löschen.
- **Regel:** **Der Modulordner ist read-only zu behandeln.** Keine Laufzeitdateien hineinschreiben.
- **Hinweis:** Die Ursache ist plausibel, aber nicht abschließend bestätigt (ob der Fehler nach der Umstellung wirklich verschwindet, steht aus).

### F4 – arp-scan auf der SymBox nicht vorhanden / Plattformabhängigkeit
- **Problem:** Binary und Bibliotheken fehlen; Betriebssystem/glibc der SymBox unbekannt.
- **Verworfen:** Debian-Pakete (`.deb`): benötigen `xz`-Entpacken (PHP kann kein xz), hängen an glibc-Version (aktuelles arp-scan braucht glibc ≥ 2.34), `libpcap0.8` zieht lange Abhängigkeitskette (libdbus, libsystemd …).
- **Gewählt:** **Alpine-Linux-Pakete (`.apk`, musl)**: gzip-Tar, selbst entpackbar, plus mitgelieferter musl-Loader → unabhängig von Host-Bibliotheken. Aufruf:
  `<root>/lib/ld-musl-<arch>.so.1 --library-path <root>/lib:<root>/usr/lib <root>/usr/sbin/arp-scan …`
- **Architektur-Mapping:** `aarch64→aarch64`, `armv7l/armv8l→armv7`, `armv6l→armhf`, `x86_64→x86_64`, `i686→x86` (per `php_uname('m')`, überschreibbar per Property).
- **Abhängigkeitsauflösung:** nur `so:`-Abhängigkeiten aus dem `APKINDEX` folgen (nicht `cmd:`/`pc:`/Skripte, sonst zieht z. B. Perl nach), Limit 25 Pakete, `musl` immer mitnehmen.

### F5 – PHP-Fallstricke beim Entpacken
- `gzdecode()` liest bei aneinandergehängten gzip-Segmenten (APK-Format: Signatur + Control + Daten) nur das **erste**. → `gzopen`/`gzread` verwenden (zlib behandelt Multi-Member korrekt).
- Symlinks aus Archiven **nicht als Symlinks anlegen**, sondern nach dem Entpacken in **Kopien** auflösen (absolute Link-Ziele würden sonst auf den Host zeigen; Dateisystem muss Symlinks nicht unterstützen).
- Tar-Details: ustar-Prefix, GNU-Langnamen (`L`), PAX-Header (`x` → `path`/`linkpath`), Steuerdateien mit führendem `.` überspringen, Pfade mit `..` oder `/` am Anfang verwerfen.

### F6 – Windows hat kein `arp-scan`
- **Lösung:** Aktiver Scan über **UDP-Pakete an alle Hosts** (`stream_socket_client('udp://IP:9')`, 2 Runden) → löst ARP-Auflösung des Betriebssystems aus (ohne Root, ohne Ping, Firewall-unabhängig) → danach `arp -a` auslesen und nach Netz filtern; Broadcast (`ff:ff…`) und Multicast (`01:00:5e`, `33:33`) entfernen.
- **Eigene IP unter Windows:** sprachunabhängig über `route print -4 0.0.0.0` (Default-Route, niedrigste Metrik) + `ipconfig` (auf die IPv4 folgt die Maske; Maske nur akzeptieren, wenn sie mit `255.` beginnt und zusammenhängende Einsen hat; `169.254.*` und `127.*` ausschließen).
- Dieselbe ARP-Tabellen-Methode dient unter Linux als **Fallback** (`/proc/net/arp`, Flag `0x2`), wenn arp-scan nicht nutzbar ist.

### F7 – Prozessfehler in der Zusammenarbeit
- Mehrfach kamen **abgeschnittene Nachrichten** („Folgende Fehlermeldung wird ausgegeben“ ohne Text, „auf der original SymBox !“ ohne Zusammenhang).
- **Regel für Claude:** Nicht raten. Kurz nach dem **vollständigen Fehlertext**, dem **Schritt, bei dem er auftritt**, und der **Debug-Ausgabe der Instanz** fragen. Hilfreiches Diagnoseskript: `echo php_uname()."\n".PHP_OS_FAMILY."\n".ini_get('memory_limit');`

---

## 3. Verbindliche Regeln für neue Module

### Struktur
- Bibliothek: `library.json` im Wurzelordner, Modul in Unterordner mit `module.json`, `module.php`, `form.json`.
- `module.json`: `"type": 3` (Device), eindeutiges `prefix` (3–5 Großbuchstaben), neue GUIDs (`uuid4`, Großbuchstaben, in `{}`).
- `library.json`: `version`, `build` bei jeder Änderung erhöhen, `compatibility.version` z. B. `"6.0"`.
- Kein `declare(strict_types=1)` verwenden (Formularwerte kommen teils als anderer Typ an); stattdessen nullable Typen und explizite Casts.
- Öffentliche Funktionen erscheinen als `PREFIX_Funktion($id, …)`.

### Formular
- `elements` = gespeicherte Properties, `actions` = Buttons/Ergebnisse.
- Button-`onClick`: `echo PREFIX_Funktion($id);` für Rückmeldungen; Eingaben defensiv mit `??` übergeben (siehe F1).
- Ergebnislisten: Werte per `GetConfigurationForm()` aus einem **Attribut** einfügen (damit sie beim Öffnen sichtbar sind) und nach dem Scan per `UpdateFormField('Liste','values', json_encode(...))` aktualisieren.
- Statuszeilen: `Label` mit `name` und Text in `GetConfigurationForm()` setzen; nach Aktionen per `UpdateFormField(name,'caption',…)`.
- `UpdateFormField` ist außerhalb einer Konsolensitzung (z. B. Timer) wirkungslos, aber unschädlich.

### Sicherheit
- Jede Eingabe, die in einen Shell-Befehl fließt: strikt validieren (`filter_var(..., FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)`, Regex für Interface-Namen, Zahlenbereiche) **und** mit `escapeshellarg()` übergeben.
- Fehler dem Nutzer per `trigger_error($msg, E_USER_WARNING)` melden (erscheint in der Konsole), technische Details per `SendDebug`.
- Heruntergeladene Archive: nur bekannte Quellen (HTTPS), Pfade beim Entpacken prüfen. Keine zusätzliche Signaturprüfung implementiert (bekannte Einschränkung).

### Ressourcen
- 32-MB-Limit beachten (F2). Downloads in Dateien, Verarbeitung im Strom.
- Skript-Laufzeitlimits können bei langen Aktionen greifen (große Netze, mehrere Downloads) → große Aufgaben in Blöcke teilen (z. B. /24-Chunks), idempotent halten, sodass ein zweiter Klick den Rest nachholt.
- Laufzeitdaten außerhalb des Modulordners (F3).

### Plattformen
- `PHP_OS_FAMILY` auswerten; unter Windows eigene Wege (F6). Befehle immer mit `exec()`-Rückgabecode prüfen.
- Kein Systempaket voraussetzen: erst nach System-Installation suchen, dann gebündelte Variante, dann Fallback-Methode.

---

## 4. Designentscheidungen (mit Begründung)

| Entscheidung | Begründung |
|---|---|
| Binary-Beschaffung aus Alpine/musl statt Debian | unabhängig von glibc, reines gzip-Tar, kleine Abhängigkeitskette (F4) |
| Ausführung über musl-Loader mit `--library-path` | keine Änderungen am Host, kein `LD_LIBRARY_PATH`-Export nötig |
| arp-scan mit **leeren** Vendor-Dateien (`--ouifile`, `--macfile`) | verhindert Fehler/Warnungen bei fehlenden Datenbanken; Herstellernamen liefert das Modul selbst |
| Hersteller aus **IEEE-Listen** (MA-L `oui.csv`, MA-M `mam.csv`, MA-S `oui36.csv`) | genaueste Zuordnung; Suche mit längstem Präfix zuerst (9 → 7 → 6 Hex-Zeichen) |
| Fallback-Bezeichnung „Zufällige/lokale MAC (privat)“ | Bit 0x02 im ersten Byte = lokal administriert (private WLAN-Adressen) |
| Netze > /24 in **/24-Blöcke** teilen; Bitcount 16–32 erlaubt | Speicher/Laufzeit begrenzen; Ergebnisse zusammenführen, nach IP sortieren, deduplizieren (`IP|MAC`) |
| arp-scan-Optionen `--plain --quiet --retry=2 --timeout=<ms>` | maschinenlesbare Ausgabe, kurze Laufzeit; `(DUP: n)` am Zeilenende entfernen |
| Anwesenheit: **sofort anwesend**, **abwesend erst nach X verpassten Scans** (Standard 3) | Handys im Standby antworten nicht bei jedem Scan |
| Anwesenheit nur auswerten, wenn das **Standard-Netzwerk** gescannt wurde | manuelle Scans anderer Netze sollen niemanden „abwesend“ setzen |
| Variablen je Gerät mit Ident `P_<MAC ohne :>` (Boolean, Profil `~Presence`) und `S_<MAC>` (Zeitstempel `~UnixTimestamp`); Sammelvariable `AnyPresent` | stabile Idents unabhängig vom Namen; nach `RegisterVariable*` zusätzlich `IPS_SetName`, damit Umbenennungen übernommen werden; Variablen entfernter Geräte per `UnregisterVariable` löschen |
| Geräte aus Scan per Button in Liste übernehmen (`UpdateFormField` auf die Property-Liste), nicht per `onClick`-Variablen | robust trotz F1; Nutzer benennt um und löscht, dann „Änderungen übernehmen“ |
| Timer: `RegisterTimer('ScanTimer',0,'PREFIX_TimerScan($_IPS[\'TARGET\']);')`, Intervall in `ApplyChanges` per `SetTimerInterval` | Standardmuster; Sperre per `SetBuffer('ScanLock', time()+600)` gegen überlappende Scans |
| Skript-API `AddTrackedDevice($id,$mac,$name)` über `IPS_SetProperty` + `IPS_ApplyChanges` | Konfiguration auch aus Skripten pflegbar |
| Eigene IP: Default-Route bevorzugt (`ip -4 route get 1.1.1.1` → `src`), sonst erstes Interface; Interface-Namen vor `@` abschneiden | funktioniert mit mehreren Netzwerkkarten und in Containern |

---

## 5. Offen / nicht verifiziert

- **Kein PHP-Syntaxcheck** in der Entwicklungsumgebung (PHP nicht installiert); alles wurde nur auf dem Zielsystem des Nutzers getestet.
- Alpine-Mirror-URL (`dl-cdn.alpinelinux.org`), Pfade (`main`/`community`), Paketnamen (`arp-scan`, `libpcap`, `libcap2`, `musl`) und IEEE-Download-URLs wurden **nicht aus der Entwicklungsumgebung geprüft** (kein Netzzugriff); liefen laut Nutzer auf der SymBox.
- Ursache von F1 (Formularvariablen im `onClick`) und F3 (Verzeichnis nicht leer) nur plausibel erklärt.
- Verhalten der Skript-Laufzeitgrenze von Symcon bei langen Scans nicht gemessen.
- `NOPASSWD`-sudo bzw. Capabilities nur als Option vorgesehen; tatsächliche Rechte der SymBox nicht geklärt.
- Windows-Variante (UDP + `arp -a`) in der Praxis noch ungetestet.
- Datenschutz: Anwesenheitserkennung über MAC-Adressen ist personenbezogen; im Haushalt unkritisch, bei Dritten (Gäste, Mitarbeiter) rechtlich prüfen.

---

## 6. Code-Muster (bewährt bzw. direkt aus ArpScan)

**Streaming-Download in Datei**
```php
$fh = fopen($file, 'wb');
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Symcon-Modul)']);
$ok = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch); fclose($fh);
if ($ok === false || $code !== 200) { @unlink($file); throw new Exception("Download fehlgeschlagen: $url (HTTP $code)"); }
```

**Multi-Member-gzip lesen (Chunks)**
```php
$gz = gzopen($file, 'rb');
while (!gzeof($gz)) { $c = gzread($gz, 65536); if ($c === false || $c === '') break; /* verarbeiten */ }
gzclose($gz);
```

**Timer + Sperre**
```php
// Create():
$this->RegisterTimer('ScanTimer', 0, 'PREFIX_TimerScan($_IPS[\'TARGET\']);');
// ApplyChanges():
$this->SetTimerInterval('ScanTimer', max(0, $this->ReadPropertyInteger('ScanInterval')) * 60 * 1000);
// TimerScan():
if ((int) $this->GetBuffer('ScanLock') > time()) return;
$this->SetBuffer('ScanLock', (string) (time() + 600));
try { /* Arbeit */ } finally { $this->SetBuffer('ScanLock', '0'); }
```

**Variablen dynamisch aus Liste, mit Aufräumen**
```php
$keep = [];
foreach ($items as $i => $t) {
    $ident = 'P_' . $t['Hex'];
    $this->RegisterVariableBoolean($ident, $t['Name'], '~Presence', 100 + $i);
    IPS_SetName($this->GetIDForIdent($ident), $t['Name']);
    $keep[] = $ident;
}
foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
    $ident = IPS_GetObject($cid)['ObjectIdent'];
    if (strncmp($ident, 'P_', 2) === 0 && !in_array($ident, $keep, true)) $this->UnregisterVariable($ident);
}
```

**Defensiver Button**
```json
{ "type": "Button", "caption": "Scannen", "onClick": "PREFIX_Scan($id, $Network ?? '', $Bitcount ?? 0);" }
```

**Netzwerkadresse berechnen**
```php
$mask = $bits === 0 ? 0 : ((~0 << (32 - $bits)) & 0xFFFFFFFF);
$net  = long2ip(ip2long($ip) & $mask);
```

---

## 7. Checkliste vor Auslieferung eines neuen Moduls

1. Läuft es mit **32 MB** RAM? (keine großen Strings/Arrays, Streaming)
2. Schreibt es **nur außerhalb** des Modulordners?
3. Sind alle `onClick`-Variablen mit `?? default` abgesichert und die Funktionen nullable?
4. Wird jede Shell-Eingabe validiert **und** mit `escapeshellarg` übergeben?
5. Gibt es einen sinnvollen Weg für **Windows** (oder eine klare Meldung)?
6. Sind Fehler als Warnung sichtbar (`trigger_error`) und Details im Debug (`SendDebug`)?
7. Wurden `build` und `version` in `library.json` erhöht? Neue GUIDs nur bei neuen Modulen, nie bei Updates ändern.
8. Zip enthält: `library.json`, `<Modul>/module.json`, `module.php`, `form.json` (kein `runtime/`).
9. Antwort an den Nutzer: **was ungetestet ist**, benennen; beim Fehler nach vollständigem Text, Schritt und Debug-Ausgabe fragen.
