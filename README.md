# EMBS4Symcon

Symcon-Bibliothek von Benjamin Sommer: Energieprognosen als Grundlage für ein Energiemanagement (Wohnhaus und
Gewerbe) und Hilfsmodule. Eigene Entwicklung, ohne Code aus anderen Prognose-Modulen.

| Modul | Präfix | Inhalt |
|---|---|---|
| [Verbrauchsprognose](Verbrauchsprognose/README.md) | `EPL` | Grundlast, Wärmepumpe/Klima, Wallboxen, kWh-/Zählerstand-Eingänge |
| [Solarprognose](Solarprognose/README.md) | `EPV` | PV je Generator, Temperaturverlust, Wechselrichtergrenze, Kalibrierung aus der Messung |
| [Windprognose](Windprognose/README.md) | `EPW` | Kleinwind: Nabenhöhe, Rotorfläche oder Kennlinie, Luftdichte, Böigkeit, Kalibrierung |
| [BHKWPrognose](BHKWPrognose/README.md) | `EPB` | wärmegeführtes BHKW über die Außentemperatur, Stillstandstage |
| [Jahresvariablen](Jahresvariablen/README.md) | `Jahresvariablen` | zwölf Monatswerte (Ganzzahl 0–100 %) |
| [Jahresvariablen_Float](Jahresvariablen_Float/README.md) | `EMBSVARFloat` | zwölf Monatswerte (Kommazahl) |

Die vier Prognosemodule liefern dasselbe Format (`GetForecast`: `p10`/`p50`/`p90`/`mean` in W je Slot, `kwh`, Vertrag 1.0;
`GetEnergyWindow`: Energie im Zeitfenster). Ein EMS kann so Verbrauch und Erzeugung direkt gegeneinanderrechnen:

```php
$von = time(); $bis = strtotime('tomorrow 07:00');
$bedarf = EPL_GetEnergyWindow(11111, $von, $bis)['kwh'];
$erzeugung = EPV_GetEnergyWindow(22222, $von, $bis)['kwh'] + EPW_GetEnergyWindow(33333, $von, $bis)['kwh']
           + EPB_GetEnergyWindow(44444, $von, $bis)['kwh'];
```

Voraussetzung: Symcon ab 8.1 (gilt seit Version 1.1 für die ganze Bibliothek). Änderungen: [CHANGELOG.md](docs/CHANGELOG.md). Einbinden über das **Module Control** mit der URL des Repositorys.
**Erst auf einer Test-Instanz ausprobieren.**

## Entwicklung

Das Repository beruht auf dem eigenen Starter-Repository (Regelwerk aus der ArpScan-Entwicklung).

| Pfad | Zweck |
|---|---|
| `Verbrauchsprognose/`, `Solarprognose/`, `Windprognose/`, `BHKWPrognose/`, `Jahresvariablen/`, `Jahresvariablen_Float/` | Module (`module.php`, `form.json`, `module.json`, README) |
| `libs/` | Logik ohne Symcon-Abhängigkeit: `EPSeries` (Archivwerte → Viertelstundenprofil), `EPDayCache` (Tagesspeicher), `EPCalendar` (Feiertage, Tageslänge), `EPMath` (Quantile, Regression), `EPWallbox` (Ladeplanung), `EPSun` (Sonnenstand), `EPPV` (Modulebene, Temperatur), `EPWind` (Nabenhöhe, Dichte, Kennlinie), `EPOpenMeteo`, `EPDegreeDay` (Gradtag-Regression), `EPForecast` (Ausgabeformat, Kalibrierung). Traits: `EPHelper` (Laufzeitverzeichnis, Download, Fehler), `EPModuleCommon` (gemeinsame Modulteile der Erzeugungsprognosen) |
| `tests/php/` | PHPUnit-Tests der `libs/` und Ende-zu-Ende-Test des Moduls mit nachgebildetem Symcon/Archiv (`tests/php/stubs/`) |
| `tests/lint/` | Tests des Linters |
| `.dev/symcon_lint.py` | Statische Prüfungen |
| `.dev/run_php_tests.php` | PHP-Tests ohne PHPUnit/Composer ausführen |
| `.dev/scaffold.py` | Neues Modul aus `.dev/template/MeinModul` |
| `.dev/build_zip.py` | Release-ZIP nach sauberem Lint |
| `.dev/diagnose.php` | Diagnoseskript für das Zielsystem |
| `docs/` | Regelwerk (`RULES.md`), Fehlerchroniken, Vorlage für neue Erkenntnisse |
| `CLAUDE.md` | Anweisungen für Claude Code |

```bash
python3 .dev/symcon_lint.py --strict
python3 -m unittest discover -s tests/lint
php -d memory_limit=32M .dev/run_php_tests.php      # oder: composer install && vendor/bin/phpunit
python3 .dev/build_zip.py                            # Release-ZIP in dist/
```

Neue Module: `python3 .dev/scaffold.py module <Name> <PRÄFIX>`. Klassennamen in `libs/` tragen das Kürzel `EP`, damit
sie nicht mit gleichnamigen Klassen anderer Bibliotheken zusammenstoßen.
