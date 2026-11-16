# Regelwerk

Jede Regel hat eine ID. `python3 .dev/symcon_lint.py` meldet Verstöße mit genau dieser ID.
**Herkunft** sagt, worauf die Regel beruht:

- **Doku** = in der offiziellen Symcon-Dokumentation nachgelesen (Stand dieser Erstellung, Symcon 8.x)
- **ArpScan F#** = Fehler aus der Entwicklung des ArpScan-Moduls (`docs/LESSONS-ArpScan.md`), vom Nutzer auf dem Zielsystem beobachtet
- **Hausregel** = Konvention dieses Repositories, keine Symcon-Vorgabe

**Durchsetzung:** `Lint` = automatisch geprüft (Heuristik, siehe Grenzen unten), `Test` = durch Tests abgedeckt, `Manuell` = nur Checkliste/Review.

## Struktur und Metadaten

| ID | Regel | Herkunft | Durchsetzung |
|---|---|---|---|
| L000 | JSON-Dateien müssen gültig sein | Doku | Lint |
| L001 | `library.json`: `id`, `author`, `name`, `version` (String), `build` (Ganzzahl) | Doku (Libraries) | Lint |
| L002 | GUID: `{8-4-4-4-12}`, nur Großbuchstaben 0-9/A-F, mit Klammern | Doku (Libraries) | Lint |
| L003 | GUIDs sind eindeutig | Doku | Lint |
| L004 | `module.json`: `id`, `name`, `type` (0-6), `prefix`; Namenszeichen A-Z a-z 0-9 Leerzeichen _ | Doku (Module) | Lint |
| L005 | `prefix` nur Buchstaben/Ziffern (Doku); 3-5 Großbuchstaben (Hausregel, Warnung); eindeutig | Doku + Hausregel | Lint |
| L008 | Jeder Ordner im Wurzelverzeichnis braucht eine `module.json`, außer `libs/ docs/ imgs/ tests/ actions/` und Punkt-Ordner. Sonst markiert Symcon ihn als fehlerhaft. Werkzeuge liegen deshalb in `.dev/` | Doku (Structure) | Lint |
| L009 | `IPSModuleStrict` braucht `compatibility.version` ≥ 8.1 | Doku (Module) | Lint |
| L010 | Modulordner heißt wie die Klasse (Warnung) | Doku (Structure, „should“) | Lint |
| L011 | Kein Ordner `runtime/` im Modulordner | ArpScan F3 | Lint |

## PHP

| ID | Regel | Herkunft | Durchsetzung |
|---|---|---|---|
| P001 | Kein `declare(strict_types=1)` (Formularwerte kommen teils als anderer Typ an). Nullable Typen + explizite Casts | ArpScan F1/Abschn. 3 | Lint |
| P002 | Klassenname = Modulname aus `module.json` (ohne Leerzeichen) | Doku (Module) | Lint |
| P003 | Öffentliche Funktionen: Parameter- und Rückgabetypen vollständig (Pflicht bei `IPSModuleStrict`) | Doku (Module) | Lint |
| P004 | Funktionsnamen nur a-z A-Z 0-9; `$InstanceID` nie als Parametername | Doku (Module) | Lint |
| P005 | Nie in den Modulordner schreiben (auch nicht über Variablen, die auf `__DIR__` beruhen). Laufzeitdaten nach `IPS_GetKernelDir()` | ArpScan F3 | Lint |
| P006 | Keine URL-Downloads in PHP-Strings (`file_get_contents('https…')` = Fehler; `CURLOPT_RETURNTRANSFER`, `gzdecode` = Warnung). Streamen: `CURLOPT_FILE`, `gzopen`/`gzread` | ArpScan F2/F5 | Lint |
| P007 | `exec`/`shell_exec`/`system`/`passthru`/`proc_open`/`popen` nur mit `escapeshellarg` (≤ 6 Zeilen davor). Eingaben zusätzlich validieren. Bevorzugt `runCommand()` aus `libs/EPHelper.php` | Abschn. 3 „Sicherheit“ | Lint |
| P008 | `exec()` mit `$result_code`; Rückgabecode prüfen | Abschn. 3 „Plattformen“ | Lint |
| P009 | Kein `symlink()`; Archiv-Links als Kopien auflösen | ArpScan F5 | Lint |
| P010 | Kein `sleep()`/`usleep()` (blockiert einen der begrenzten PHP-Threads, Standard 50, Minimum 25) | Doku (Limitationen) | Lint |
| P011 | Keine festen Pfade `/var/lib/symcon…`; `IPS_GetKernelDir()` | ArpScan Abschn. 1 | Lint |
| P012 | Dateien mit Programmaufrufen brauchen eine Plattformweiche (`PHP_OS_FAMILY`/`platform()`) oder eine klare Meldung | ArpScan F4/F6 | Lint |
| P013 | `SendDebug` für technische Details; Fehler per `trigger_error(…, E_USER_WARNING)` (`fail()`) | Abschn. 3 | Lint (nur SendDebug) |
| P019 | `IPSModuleStrict` statt `IPSModule` für neue Module | Doku (Module) | Lint (Warnung) |
| P020 | `ReadProperty*('X')` setzt `RegisterProperty*('X')` voraus | Eigene Folgerung | Lint |
| P021 | Registrierte Property fehlt im Formular (Warnung) | Eigene Folgerung | Lint |
| P022 | `Read/WriteAttribute*('X')` setzt `RegisterAttribute*('X')` voraus | Eigene Folgerung | Lint |
| P023 | `RegisterTimer`-Skript: Präfix = `module.json`-Präfix, Funktion existiert und ist öffentlich | Eigene Folgerung | Lint |

## Formular (`form.json`)

| ID | Regel | Herkunft | Durchsetzung |
|---|---|---|---|
| F001 | Nur die Bereiche `elements`, `actions`, `status` (+ `$schema`) | Doku (Configuration Forms) | Lint |
| F002 | Eingabeelemente brauchen `name` | Doku | Lint |
| F003 | Nur bekannte Elementtypen (z. B. `NumberSpinner`, nicht `NumberSpinBox`) | Doku | Lint |
| F004 | `name` eindeutig | Eigene Folgerung | Lint |
| F005 | Jede `$Variable` im `onClick` muss ein Element gleichen Namens haben | ArpScan F1 | Lint |
| F006 | `$Variable` im `onClick` immer mit `?? Standardwert`; Funktion nullable, Fallback auf `ReadProperty*` | ArpScan F1 | Lint (Warnung) |
| F007 | Eingaben für Buttons in `actions` legen (so zeigt es die Doku). Werte aus `elements` per `ReadProperty` lesen | Doku-Muster; **Ursache von F1 unbestätigt** | Lint (Warnung) |
| F008 | `status`: `code` (Zahl), `icon`, `caption` | Doku | Lint |
| F009 | `UpdateFormField('X', …)`: Element `X` muss im Formular existieren | Eigene Folgerung | Lint (Warnung) |
| F010 | Eingabeelement in `elements` ohne `RegisterProperty` (Warnung) | Eigene Folgerung | Lint |
| F011 | `onClick` ruft `PREFIX_Funktion($id…)`: Präfix stimmt, Funktion ist öffentlich | Eigene Folgerung | Lint |

## Release

| ID | Regel | Herkunft | Durchsetzung |
|---|---|---|---|
| R001 | Änderungen an Modulen/`libs/`: `build` in `library.json` erhöhen | ArpScan Abschn. 7 | Lint `--base` |
| R002 | GUIDs (Bibliothek, Module) bei Updates nie ändern | ArpScan Abschn. 7 | Lint `--base` |
| R000 | Basis-Referenz nicht auflösbar → Prüfung übersprungen (Warnung) | – | Lint |

## Ressourcen und Grenzen von Symcon (Doku „Limitationen“)

Nicht automatisch prüfbar, aber beim Entwurf zu beachten:

- **Speicher je PHP-Thread: 32 MB Standard, maximal 64 MB** (nur über `php.ini`). `ini_set('memory_limit', …)` im Modul nicht darauf verlassen.
- **Buffer** (`SetBuffer`/`GetBuffer`): weiches Limit 256 kB, hartes 1024 kB, mehr wird abgeschnitten. Nur kleine Zustände (Sperren, Zähler) ablegen.
- **String-Variablen**: über 1024 kB Fehler und nicht geschrieben; ab 8 kB sollten Daten anderswo liegen (Datei, Medienobjekt).
- **RegisterVariable-/Cutter-Buffer**: nur die letzten 64 kB bleiben erhalten.
- `AC_*`-Abfragen liefern höchstens 10 000 Zeilen, ohne Warnung.
- Variablenprofile: höchstens 128 Assoziationen. Objekte: höchstens 50 000.
- Lokale Modulentwicklung: zum Neuladen ist ein kurzer Dienst-Neustart nötig, bei Updates über das Repository nicht.

## Testbarkeit

- Logik, die ohne `IPS_*`-Funktionen auskommt, gehört in Klassen unter `libs/` (Vorbild: `EPSeries`, `EPMath`) und wird mit PHPUnit getestet (`tests/php/`).
- Modulklassen bleiben dünn: Eingaben validieren, `libs/`-Logik aufrufen, Ergebnis in Variablen/Formular schreiben.
- Alles, was nur auf dem Zielsystem prüfbar ist (Rechte, Plattform, Laufzeitgrenzen), prüft `.dev/diagnose.php` und ein Test auf einer **Test-Instanz**, nie zuerst auf der produktiven Haussteuerung.

## Grenzen des Linters

- Regex-Heuristiken auf Zeilenbasis, keine PHP-Analyse. Mehrzeilige Konstrukte und dynamisch gebaute Aufrufe können durchrutschen oder Fehlalarme erzeugen.
- Unterdrücken geht nur mit Begründung: `// lint-ok: P007 Grund`.
- Er prüft **nicht**, ob PHP-Syntax stimmt (`php -l` läuft in der CI), ob das Modul in Symcon funktioniert, und nicht gegen die offiziellen JSON-Schemas (die `$schema`-Zeilen aktivieren sie im Editor).
- Zusätzlich lohnt der offizielle **Module Validator** von Symcon (siehe Doku „SDK/Tools“).
