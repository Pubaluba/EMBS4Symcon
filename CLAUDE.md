# Symcon-Modulentwicklung – Anweisungen für Claude

Diese Datei wird von Claude Code beim Start automatisch gelesen. Sie gilt für jedes Modul in diesem Repository,
solange die Person nichts anderes sagt. Ausführlich: `docs/RULES.md` (Regeln mit IDs), `docs/LESSONS-ArpScan.md` und
`docs/LESSONS-EnergiePrognose.md` (Fehlerchroniken), `docs/NEW-LESSON.md` (neue Erkenntnis festhalten).

## Zuerst

- Symcon-Dokumentation für KI-Agenten: https://www.symcon.de/de/llms.txt . Für PHP-Funktionen zuerst
  `https://www.symcon.de/de/llms/function-index.md` bzw. `function-index-modules.md` laden und die Signatur dort nachlesen,
  nicht aus dem Gedächtnis schreiben.
- Neue Module: `python3 .dev/scaffold.py module <Name> <PRAEFIX>` (kopiert `.dev/template/MeinModul`, neue GUID).
- Nach **jeder** Änderung: `python3 .dev/symcon_lint.py --strict`, `python3 -m unittest discover -s tests/lint` und
  `php -d memory_limit=32M .dev/run_php_tests.php` (läuft ohne PHPUnit/Composer).
  Der Hook in `.claude/settings.json` startet den Linter nach Dateiänderungen automatisch.
- Vor einem Release: `python3 .dev/symcon_lint.py --base <git-Referenz>` und `python3 .dev/build_zip.py`.

## Verbindliche Regeln (Kurzfassung)

1. Basisklasse `IPSModuleStrict` (Symcon ≥ 8.1), alle öffentlichen Funktionen vollständig typisiert. Kein `declare(strict_types=1)`.
2. Läuft mit **32 MB** RAM je PHP-Thread: Downloads in Dateien streamen, zeilen-/chunkweise verarbeiten, keine großen Strings/Arrays.
3. **Nie in den Modulordner schreiben.** Laufzeitdaten nach `IPS_GetKernelDir()` (`runtimeDir()` in `libs/EPHelper.php`).
4. Button-`onClick`: Variablen mit `?? Standardwert`, Funktionen nullable, leere Werte fallen auf `ReadProperty*` zurück.
   Eingaben für Buttons in `actions`; `NumberSpinner` (nicht `NumberSpinBox`).
5. Shell-Eingaben validieren **und** `escapeshellarg` (`runCommand()`); Rückgabecode prüfen.
6. Plattformen beachten (Linux-ARM/SymBox, Windows 64 Bit, Docker) oder klar melden, dass sie nicht unterstützt werden.
7. Fehler als Warnung (`fail()` → `trigger_error`), Details per `SendDebug`.
8. Bei jeder Änderung `build` in `library.json` erhöhen. GUIDs bei Updates **nie** ändern.
9. Im Wurzelverzeichnis nur Modulordner (mit `module.json`) und `libs/ docs/ imgs/ tests/ actions/`. Werkzeuge in `.dev/`.
10. Logik ohne `IPS_*`-Abhängigkeit in `libs/` auslagern und mit PHPUnit testen; Modulklassen dünn halten.
11. Klassen/Traits in `libs/` mit Kürzel `EP` benennen (E3). Beim Start auf `KR_READY` warten (E4).
12. Archivwerte: nur Änderungen werden gespeichert (E2); höchstens 10 000 Zeilen je Abfrage, deshalb tageweise lesen.

## Ehrlich berichten

- Ist **kein PHP** installiert, PHP-Code **nicht** als „getestet“ oder „syntaktisch geprüft“ bezeichnen. Tests mit der
  Symcon-Nachbildung (`tests/php/stubs/`) zeigen die Logik, nicht das Verhalten des echten Symcon. Der Linter prüft Muster, nicht PHP-Syntax. `php -l` und PHPUnit laufen in der CI oder lokal.
- Am Ende jeder Lieferung nennen: **was geprüft wurde, was nicht** (Zielsystem, Windows, Laufzeitgrenzen, Rechte der SymBox).
- Ursachen als *bestätigt* oder *vermutet* kennzeichnen (vgl. F1 und F3 in `docs/LESSONS-ArpScan.md`).
- Nicht raten: Bei einem Fehler nach dem **vollständigen Fehlertext**, dem **Schritt, bei dem er auftritt**, und der Ausgabe von
  `.dev/diagnose.php` fragen. Abgeschnittene Nachrichten nicht ergänzen, sondern nachfragen.

## Manuell prüfen (nicht automatisierbar)

- Lässt sich das Modul auf einer **Test-Instanz** laden, konfigurieren, per Button und Timer ausführen?
- Funktioniert die Variante für Windows und für die SymBox (Rechte, fehlende Programme)?
- Datenschutz: personenbezogene Daten (z. B. MAC-Adressen, Anwesenheit) – Zweck und Personenkreis bedenken.
- Fremden Code nur nach Prüfung der `LICENSE` der verwendeten Version übernehmen (E1).
- Neue Erkenntnisse nach `docs/NEW-LESSON.md` festhalten und nach Möglichkeit in eine Lint-Regel mit Test gießen.
