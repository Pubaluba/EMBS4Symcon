# Erkenntnisse aus der Entwicklung von EnergiePrognose

### E1 – Lizenz der konkreten Version prüfen, nicht die Projektseite
- **Symptom:** Ein Fremdmodul sollte für Gewerbekunden angepasst werden. Die GitHub-Startseite zeigte „MIT license“, die
  hochgeladene Beta-Version stand aber unter PolyForm Noncommercial (gewerbliche Nutzung nur mit Zusatzlizenz).
- **Ursache:** bestätigt – Lizenzwechsel zwischen den Ständen; die Startseite zeigte einen älteren Stand.
- **Lösung:** Eigenentwicklung ohne fremden Code (Verfahren sind frei, der Code ist geschützt).
- **Regel:** Vor dem Anpassen fremden Codes die `LICENSE` der tatsächlich verwendeten Version lesen und mit dem geplanten
  Einsatz (privat/gewerblich) abgleichen.
- **Absicherung:** Manuell (CLAUDE.md, „Manuell prüfen“).

### E2 – Das Archiv speichert nur Wertänderungen
- **Symptom:** (vorbeugend) Lastgänge mit gleichbleibenden 15-min-kWh-Werten hätten Lücken.
- **Ursache:** bestätigt laut Doku `AC_GetLoggedValues`: „nur geänderte Werte werden gespeichert“.
- **Lösung:** `EPSeries::fromIntervalEnergy()` übernimmt für jedes Intervall den zuletzt gespeicherten Wert (höchstens 24 h alt).
- **Regel:** Rohwerte nie als lückenlose Zeitreihe behandeln; fehlende Einträge heißen „unverändert“.
- **Absicherung:** Test `EPSeriesTest::testIntervalEnergyChangeOnlyLogging`, Archiv-Nachbildung loggt nur Änderungen.

### E3 – Klassennamen in libs/ mit Kürzel versehen
- **Symptom:** (vorbeugend) Mehrere Bibliotheken aus demselben Starter hätten alle einen Trait `ModuleHelper`.
- **Ursache:** vermutet – Symcon lädt Module verschiedener Bibliotheken in dieselbe PHP-Umgebung; doppelte Klassennamen
  führen dann zu „Cannot declare class“. Nicht auf dem Zielsystem nachgestellt.
- **Lösung:** `ModuleHelper` → `EPHelper`, alle Klassen in `libs/` mit `EP`-Kürzel.
- **Regel:** Klassen und Traits in `libs/` tragen ein bibliothekseigenes Kürzel.
- **Absicherung:** Manuell (Review).

### E4 – Beim Symcon-Start auf den Kernel warten
- **Symptom:** (vorbeugend) `ApplyChanges` läuft beim Start, bevor das Archiv bereit ist; das Modul hätte „nicht archiviert“
  gemeldet und die Timer abgeschaltet.
- **Ursache:** vermutet (übliches Muster), Meldung `IPS_KERNELSTARTED` und `KR_READY` laut Doku belegt.
- **Lösung:** `RegisterMessage(0, IPS_KERNELSTARTED)`, bis `KR_READY` nichts tun; Timer laufen auch bei einem
  Konfigurationsproblem weiter und prüfen erneut.
- **Absicherung:** Tests `testKernelNotReadyWaitsForStart`, `testArchivingLaterSwitchedOnRecovers`.

### E5 – Inhalt von VM_UPDATE nicht voraussetzen
- **Symptom:** (vorbeugend) Die Belegung von `$Data` bei `VM_UPDATE` ist in der Doku nicht beschrieben.
- **Lösung:** Aktuellen Variablenwert lesen und mit dem zuletzt gemerkten Zustand vergleichen.
- **Absicherung:** Test `testPlugInStartsImmediateCharging` (ruft `MessageSink` mit leerem `$Data`).

### E6 – Begrenzen auf die Nennleistung darf keine Energie verschlucken
- **Symptom:** Test `EPForecastLibTest::testCapRedistributeKeepsEnergy` schlug fehl: Beim Begrenzen eines BHKW-Profils auf
  die Nennleistung ging der Teil verloren, der nicht in die bisherigen Laufzeiten passte.
- **Ursache:** bestätigt – der Überschuss wurde nur auf Viertelstunden mit Erzeugung verteilt.
- **Lösung:** Zweite Stufe verteilt den Rest auf die übrigen Viertelstunden (Anlage läuft länger); nur mehr als 24 h
  Volllast fällt weg.
- **Absicherung:** derselbe Test mit drei Fällen.

### E7 – Kalibrierung gegen Wetter derselben Tage, nicht gegen alte Prognosen
- **Entscheidung:** PV und Wind vergleichen die Messung mit dem Modell aus den Wetterdaten der Vortage (Open-Meteo
  `past_days`) statt mit gespeicherten Prognosen. Vorteil: sofort nutzbar, ohne wochenlang Prognosen zu sammeln, und
  unabhängig von Prognosefehlern des Wetters. Nachteil: Der Faktor gleicht Anlage und Modell ab, nicht die
  Vorhersagegüte für morgen.
- **Absicherung:** Ende-zu-Ende-Tests (Messung = 90 % bzw. 80 % des Modells → gelernter Faktor 0,90 bzw. 0,80).

### E8 – Create() läuft auch beim Start von Symcon
- **Symptom:** Jahresvariablen setzten ihre Standardwerte in `Create()`; eigene Monatswerte wären bei jedem Neustart
  überschrieben worden.
- **Ursache:** bestätigt laut Doku: `Create()` wird „beim Erstellen der Instanz und beim Start von IP-Symcon“ aufgerufen.
- **Lösung:** Werte nur setzen, wenn die Variable neu ist (`IPS_GetObjectIDByIdent` vor dem Registrieren).
- **Regel:** In `Create()` nur registrieren, keine Werte setzen – außer für neu angelegte Variablen.
- **Absicherung:** Test `JahresvariablenTest::testDefaultsOnlyOnFirstCreate`.

### E9 – Präfix nur aus Buchstaben und Ziffern
- **Symptom:** Präfix `EMBS_VAR_Float` mit Unterstrichen.
- **Ursache:** bestätigt laut Doku (module.json: „darf nur Zahlen und Buchstaben enthalten“).
- **Lösung:** `EMBSVARFloat`, Skripte müssen angepasst werden (CHANGELOG). Bestehende Präfixe, die die Hausregel
  (3–5 Großbuchstaben) verletzen, aber gültig sind, stehen in `LEGACY_PREFIXES` im Linter.
- **Absicherung:** Lint-Regel L005.

### E10 – EnableAction braucht RequestAction
- **Symptom:** Variablen mit Aktion, aber ohne `RequestAction` im Modul.
- **Ursache:** vermutet – ohne `RequestAction` kann Symcon die Bedienung aus der Visualisierung nicht ans Modul geben.
- **Lösung:** `RequestAction` mit Prüfung des Idents.
- **Absicherung:** Test `testRequestAction`; Lint-Regel noch offen.
