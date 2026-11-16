# BHKW-Prognose (wärmegeführt)

Elektrische Erzeugung eines **wärmegeführten** BHKW für **heute, morgen und übermorgen** mit Unsicherheitsband.

## Funktionsweise

Ein wärmegeführtes BHKW läuft, wenn Wärme gebraucht wird; die Stromerzeugung folgt also der Außentemperatur.

- **Tagesenergie** = a + b · Heizgrad, gelernt aus der gemessenen Erzeugung und der archivierten Außentemperatur
  (Heizgrad = Heizgrenze − Tagesmitteltemperatur). `a` ist der Sockel für Warmwasser bzw. Prozesswärme im Sommer.
  Begrenzt auf Nennleistung × 24 h.
- **Tagesverlauf** aus den Tagen mit ähnlichster Temperatur und gleichem Tagtyp (Werktag, Samstag, Sonn-/Feiertag je
  Bundesland). Keine Viertelstunde über der Nennleistung; passt die Energie nicht in die bisherigen Laufzeiten, verteilt
  das Modul den Rest auf weitere Viertelstunden (die Anlage läuft dann länger).
- **Band** aus der Streuung Ist/Modell über die Lerntage.
- **Stillstandstage** (Wartung, Betriebsruhe): vergangene werden beim Lernen übersprungen, geplante ergeben 0.
- Temperaturvorhersage aus Open-Meteo, eigenen Variablen oder dem Mittel der Vorjahre.

## Voraussetzungen

- Archivierte elektrische Erzeugung (Leistung, Energie je Intervall oder Zählerstand) und archivierte Außentemperatur.
- Mindestens 14 Tage mit beidem; besser eine ganze Heizperiode.

## Ausgaben

Variablen „BHKW-Prognose heute/morgen/übermorgen (JSON)“ und „… (kWh)“. JSON wie bei allen Modulen, zusätzlich
`temperature`, `temperatureSource`, `ratedKw`, `runHours` (Volllaststunden), `closed`, `modelDays`, `coef` ([a, b]).

| Befehl | Rückgabe |
|---|---|
| `EPB_Rebuild($id)` | Neuberechnung |
| `EPB_GetForecast($id, $offset)` | Prognose als Array |
| `EPB_GetEnergyWindow($id, $von, $bis)` | Energie im Zeitfenster |
| `EPB_ClearCache($id)` | Tagesspeicher der Messung löschen |

## Grenzen

- Nur für wärmegeführten Betrieb. Strom- oder fahrplangeführte BHKW (Betrieb nach Strompreis oder EMS) folgen nicht der
  Temperatur.
- Ein Wärmespeicher verschiebt Laufzeiten innerhalb des Tages; die Tagesenergie bleibt davon unberührt, der Verlauf
  wird aus den Vergleichstagen übernommen.
- Takten (häufiges An/Aus) wird nur als Mittelwert je Viertelstunde abgebildet.
