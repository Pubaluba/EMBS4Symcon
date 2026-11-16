# Solarprognose (PV)

PV-Erzeugung für **heute, morgen und übermorgen** in 15-, 30- oder 60-Minuten-Schritten mit Unsicherheitsband
(P10/P50/P90), aus der Strahlungsvorhersage von Open-Meteo (kostenlos, ohne Schlüssel).

## Funktionsumfang

- Beliebig viele **Generatoren** (Dachflächen) mit kWp, Neigung und Ausrichtung (Kompass: 90 = Ost, 180 = Süd, 270 = West).
- Einstrahlung auf die Modulebene aus Direkt-, Diffus- und Globalstrahlung und dem Sonnenstand je Viertelstunde.
- **Temperaturverlust** über die Modultemperatur (Temperaturkoeffizient einstellbar), **Systemwirkungsgrad** und
  **Wechselrichtergrenze**.
- **Kalibrierung aus der Messung** (optional): Das Modul vergleicht die gemessene Tagesenergie der letzten Tage mit dem,
  was es aus den Wetterdaten derselben Tage berechnet (Open-Meteo liefert die Vortage mit), und lernt daraus einen
  Faktor für Verschattung, Verschmutzung und Abweichungen. Ab 14 Lerntagen kommt auch das Band aus der Messung.
- Messung als Leistung, Energie je Intervall oder Zählerstand; negative Zählweise ist egal (Betrag).

## Einrichtung

1. Standort eintragen, Generatoren anlegen, Wechselrichtergrenze und Wirkungsgrad prüfen.
2. Optional die archivierte PV-Messung wählen (Messart und Einheit wie bei der Verbrauchsprognose).
3. „Prognose aktiv“ anhaken. Neuberechnung standardmäßig alle 3 Stunden und um 00:15 Uhr.

## Ausgaben

Variablen „PV-Prognose heute/morgen/übermorgen (JSON)“ und „… (kWh)“. JSON wie bei allen Modulen der Bibliothek
(`p10`, `p50`, `p90`, `mean` in W je Slot, `kwh`, `kwhP10`, `kwhP90`), zusätzlich `totalKwp`, `generators`
(kWh je Generator), `factor`, `calibrationDays`, `bandSource` („Messung“ oder „Bewölkung (Schätzung)“).

| Befehl | Rückgabe |
|---|---|
| `EPV_Rebuild($id)` | Neuberechnung, Text mit Ergebnis |
| `EPV_GetForecast($id, $offset)` | Prognose als Array (0 = heute, 1 = morgen, 2 = übermorgen) |
| `EPV_GetEnergyWindow($id, $von, $bis)` | `kwh`, `kwhP10`, `kwhP90`, `coverage` im Zeitfenster |
| `EPV_ClearCache($id)` | Tagesspeicher der Messung löschen |

## Grenzen

- Kein Horizont-/Verschattungsmodell je Uhrzeit; feste Verschattung steckt nur im gelernten Tagesfaktor.
- Ohne Messung ist das Band eine Schätzung aus der Bewölkung (±10 % bei klarem Himmel bis ±50 % bei Bedeckung).
- Schnee auf den Modulen wird nicht erkannt.
- Strahlungswerte von Open-Meteo sind Stundenmittel; innerhalb der Stunde verteilt das Modul nach dem Sonnenstand.
