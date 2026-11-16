# Verbrauchsprognose

Prognose des Stromverbrauchs für **heute, morgen und übermorgen** in 15-, 30- oder 60-Minuten-Schritten, mit
Unsicherheitsband (P10/P50/P90). Gedacht als Planungsgrundlage für ein Energiemanagement (EMS), für Wohnhäuser und
Gewerbe.

## Funktionsumfang

- **Grundlast** aus ähnlichen Tagen der Vergangenheit: Tagtyp (Werktag, Samstag, Sonntag/Feiertag je Bundesland),
  Tageslänge, Tagesmitteltemperatur, Anwesenheit bzw. Betrieb offen/geschlossen.
- **Zwei Arten, die Grundlast zu bilden:**
  1. Hauptzähler abzüglich Wärmepumpe, Wallbox und weiterer Abzüge
  2. Summe beliebig vieler ungesteuerter Verbraucher (Unterzähler)
- **Drei Messarten je Variable:** Leistung (W/kW/MW), Energie je Intervall (z. B. 15-min-kWh aus einem Lastgang) oder
  Zählerstand (Wh/kWh/MWh). Einheit automatisch aus dem Variablenprofil oder fest gewählt.
- **Wärmepumpe/Klima:** je Gerät eine Regression Tagesenergie ~ Heiz-/Kühlgradzahl, Tagesverlauf aus Tagen mit
  ähnlicher Temperatur. Betriebsart Heizen, Kühlen oder beides.
- **Wallboxen:**
  - *steuerbar*: das EMS plant die Ladung; das Modul meldet Bedarf, „fertig bis“ und spätesten Start
  - *sofort laden*: Ladevorgang beim Anstecken (Statusvariable) oder per Knopf; steht als feste Last in der Prognose
  - Ladestand aus einer SoC-Variable oder, ohne sie, aus Akkugröße und angenommenem Start-SoC
- **Temperaturvorhersage:** Open-Meteo (kostenlos, ohne Schlüssel), eigene Variablen oder Mittel der Vorjahre.
- **Abwesenheiten / Betriebsferien / Brückentage** als Datumsliste, für Vergangenheit und Zukunft.
- **Lange Historie (bis 5 Jahre):** wird einmal schrittweise eingelesen (je Lauf etwa 15 s) und in einem Tagesspeicher
  im Symcon-Verzeichnis abgelegt; spätere Läufe lesen nur noch neue Tage.

## Voraussetzungen

- Symcon ab **8.1** (Basisklasse `IPSModuleStrict`, 15-Minuten-Aggregat des Archivs).
- Archive Control mit **archivierten** Verbrauchsvariablen; für Wärmepumpen eine archivierte Außentemperatur.
- Für Open-Meteo: Internetzugang der Symcon-Installation und der Standort (Breiten-/Längengrad) im Formular.

## Einrichtung

1. Instanz „Verbrauchsprognose“ anlegen.
2. **Allgemein:** Standort eintragen, Bundesland wählen, Historie (Tage) und Auflösung festlegen.
3. **Verbrauch:** Quelle wählen und die Variablen mit Messart und Einheit eintragen.
   - Lastgang mit 15-min-kWh: Messart „Energie je Intervall“, Intervall 15 Minuten, „Zeitstempel markiert das
     Intervallende“ an (übliche Zählweise; bei Daten mit Zeitstempel am Intervallbeginn ausschalten).
   - Zählerstand: im Archiv möglichst Aggregationstyp „Zähler“ einstellen (schneller); sonst werden Rohwerte gelesen.
4. **Temperatur und Betrieb:** Außentemperatur, Vorhersagequelle, optional Anwesenheit/Betrieb und Betriebsferien.
5. **Wärmepumpe / Klima** und **Wallboxen** eintragen.
6. „Prognose aktiv“ anhaken, übernehmen. Die erste Berechnung startet nach wenigen Sekunden; solange die Historie noch
   eingelesen wird, steht im Status „⏳ … von … Tagen“, und das Modul rechnet minütlich weiter.
7. Unter „📋 Erkannte Quellen“ prüfen, ob Messart, Einheit und Archivierung stimmen (⚠️ = bitte ansehen).

## Ausgaben

| Variable | Inhalt |
|---|---|
| Prognose heute/morgen/übermorgen (JSON) | vollständiges Profil, siehe unten |
| Erwartung heute/morgen/übermorgen (kWh) | Tagessumme (Mittelwert) |
| Wärmepumpe/Klima heute/morgen/übermorgen (kWh) | Geräteanteil |
| Wallbox-Bedarf steuerbar (kWh) | Summe des Bedarfs aller steuerbaren, angesteckten Wallboxen |
| Status, Letzte Berechnung | Rückmeldung und Hinweise |

JSON (Vertrag `contractVersion` 1.0, Leistungen in W je Slot):

```
{ "contractVersion":"1.0", "date":"2026-11-17", "slots":96, "resolution":"15min", "unit":"W",
  "p10":[…], "p50":[…], "p90":[…], "mean":[…],
  "kwh":45.3, "kwhP10":38.1, "kwhP90":53.9, "kwhBase":28.4, "kwhHeat":16.9, "kwhWallbox":0.0,
  "base":[…], "heat":[…], "wallbox":[…],
  "neighbors":12, "temperature":4.2, "temperatureSource":"Open-Meteo", "generated":1794834000 }
```

`p10/p50/p90/mean` enthalten Grundlast + Wärmepumpe + laufende Sofort-Ladevorgänge. Steuerbare Wallboxen sind **nicht**
enthalten, ihr Bedarf kommt über `EPL_GetWallboxDemand`.

## PHP-Befehle

| Befehl | Rückgabe |
|---|---|
| `EPL_Rebuild($id)` | Neuberechnung, Text mit Ergebnis |
| `EPL_GetForecast($id, $offset)` | Prognose als Array (0 = heute, 1 = morgen, 2 = übermorgen) |
| `EPL_GetEnergyWindow($id, $von, $bis)` | `kwh`, `kwhP10`, `kwhP90`, `kwhWallbox`, `coverage` im Zeitfenster (Unix-Zeit) |
| `EPL_GetWallboxDemand($id)` | je Wallbox: SoC und Quelle, Bedarf (kWh), fertig bis, spätester Start, Ladeleistung |
| `EPL_ChargeNow($id, $nr)` | Sofort-Ladevorgang für Wallbox Nr. `$nr` (Zeile der Liste, ab 1) einplanen |
| `EPL_StopCharge($id, $nr)` | Sofort-Ladevorgang beenden |
| `EPL_ClearCache($id)` | Tagesspeicher löschen, Historie neu einlesen (z. B. nach einem Datenimport) |

Beispiel EMS-Skript:

```php
$fc = EPL_GetForecast(12345, 1);                       // morgen
$nacht = EPL_GetEnergyWindow(12345, time(), strtotime('tomorrow 07:00'));
$puffer = $nacht['kwhP90'];                            // vorsichtig planen
foreach (EPL_GetWallboxDemand(12345) as $wb) {
    if ($wb['mode'] === 'steuerbar' && $wb['needKwh'] > 0) {
        // Ladung bis $wb['readyAt'] einplanen, spätestens ab $wb['latestStart']
    }
}
```

## Grenzen

- Einheit „automatisch“ liest das **Variablenprofil**. Variablen ohne Profil (z. B. nur mit „Darstellung“) bekommen W bzw.
  kWh angenommen – dann die Einheit im Formular selbst wählen (die Quellenübersicht zeigt ⚠️).
- Feiertage nur landesweit; Feiertage einzelner Gemeinden über die Liste „Abwesenheiten / Betriebsferien“ pflegen.
- „Sofort laden“ ohne SoC-Variable schätzt den Bedarf aus Akkugröße und Start-SoC; das Ende wird nach Ladezeit angenommen.
- Ein Tag zählt bei der Verbraucherliste nur, wenn **alle** Verbraucher Daten haben. Ein neu hinzugefügter Unterzähler
  verkürzt daher die nutzbare Historie, bis er genug Tage gesammelt hat.
- Der Tagesspeicher liegt unter `<Symcon-Verzeichnis>/EnergiePrognose/i<Instanz-ID>/` und wird beim Löschen der Instanz
  nicht automatisch entfernt (vorher „Tagesspeicher leeren“ klicken).
