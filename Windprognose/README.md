# Windprognose (Kleinwind)

Erzeugung einer Kleinwindanlage für **heute, morgen und übermorgen** mit Unsicherheitsband (P10/P50/P90), aus der
Windvorhersage von Open-Meteo.

## Funktionsumfang

- Anlagendaten: **Nennleistung, Rotorfläche, Nabenhöhe**, Ein- und Abschaltgeschwindigkeit, Leistungsbeiwert cp.
- Optional die **Leistungskennlinie des Herstellers** (Wind m/s → kW); sie ersetzt dann die Formel P = ½ · ρ · A · cp · v³.
- **Wind in Nabenhöhe**: aus den Modellhöhen 10, 80, 120 und 180 m (Potenzgesetz zwischen den Höhen) oder nur aus 10 m
  mit dem Höhenexponenten der gewählten **Geländeklasse**.
- **Luftdichte** aus Luftdruck und Temperatur (kalte Luft bringt mehr Ertrag).
- **Böigkeit** über die Turbulenzintensität der Geländeklasse: Weil die Leistung mit v³ wächst, liefert böiger Wind mehr
  als die mittlere Geschwindigkeit vermuten lässt; oberhalb der Nennleistung wirkt es umgekehrt.
- **Band**: Windgeschwindigkeit −/+15 % (heute), +5 % je weiterem Tag.
- **Kalibrierung** aus der Messung wie bei der PV-Prognose (Faktor 0,3 bis 1,5).

## Einrichtung

1. Standort, Nennleistung, Rotorfläche (Horizontalläufer π·(D/2)², Vertikalläufer Höhe × Durchmesser) und Nabenhöhe.
2. Kennlinie aus dem Datenblatt eintragen, falls vorhanden – genauer als die Formel.
3. Geländeklasse wählen; mit „Leistung bei diesem Wind anzeigen“ die Einstellungen prüfen.
4. Optional die archivierte Messung für die Kalibrierung.

## Ausgaben

Variablen „Windprognose heute/morgen/übermorgen (JSON)“ und „… (kWh)“. JSON wie bei allen Modulen, zusätzlich
`windHub` (m/s je Slot in Nabenhöhe), `hubHeight`, `ratedKw`, `fullLoadHours`, `factor`, `calibrationDays`.

| Befehl | Rückgabe |
|---|---|
| `EPW_Rebuild($id)` | Neuberechnung |
| `EPW_GetForecast($id, $offset)` | Prognose als Array |
| `EPW_GetEnergyWindow($id, $von, $bis)` | Energie im Zeitfenster |
| `EPW_GetPowerAt($id, $wind)` | Leistung (kW) bei einer Windgeschwindigkeit in Nabenhöhe, ohne Böigkeit |
| `EPW_ClearCache($id)` | Tagesspeicher der Messung löschen |

## Grenzen

- Kleinwindanlagen auf niedrigen Masten stehen oft in verwirbelter Luft hinter Gebäuden und Bäumen. Das Wettermodell kennt
  diese Hindernisse nicht; die Prognose ist deutlich unsicherer als bei PV. Eine Messung mit Kalibrierung hilft.
- Keine Richtungsabhängigkeit (Hindernisse nur in einer Himmelsrichtung werden nicht berücksichtigt).
- Vereisung und Sturmabschaltung über die Abschaltgeschwindigkeit hinaus (Hysterese) sind nicht abgebildet.
