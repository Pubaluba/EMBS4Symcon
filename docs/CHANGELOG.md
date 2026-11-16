# Änderungen

## 1.2 (Build 2)

- Neu: Energiekachel (`EPK`) – Prognosen farbig in der Kachel-Visualisierung, Strompreise mit Markierung der günstigsten
  Abschnitte, Zukaufplan und Fahrpläne (Anforderung, Normalbetrieb, Sperre); Daten per String-Variable oder Funktion.

## 1.1 (Build 1)

**Bitte vor dem Update lesen:**

- ⚠️ **Präfix von Jahresvariablen_Float geändert:** `EMBS_VAR_Float` → `EMBSVARFloat`. Laut Symcon-Doku darf das Präfix nur
  Buchstaben und Ziffern enthalten. Skripte anpassen: `EMBS_VAR_Float_GET(…)` → `EMBSVARFloat_GET(…)`,
  `EMBS_VAR_Float_SET(…)` → `EMBSVARFloat_SET(…)`.
- ⚠️ **Mindestversion Symcon 8.1** für die ganze Bibliothek (vorher 7.1).
- Wer die Prognosemodule schon als Bibliothek „EnergiePrognose“ installiert hatte: diese im Module Control entfernen,
  bevor EMBS4Symcon aktualisiert wird (gleiche Modul-GUIDs).

Neu:
- Module Verbrauchsprognose (`EPL`), Solarprognose (`EPV`), Windprognose (`EPW`), BHKWPrognose (`EPB`).

Jahresvariablen und Jahresvariablen_Float:
- Behoben: Die Standardwerte (85, 75, 60 …) wurden bei jedem Start von Symcon neu gesetzt und haben eigene Monatswerte
  überschrieben (`Create()` läuft laut Doku auch beim Start). Jetzt nur noch beim ersten Anlegen.
- Behoben: Bedienung in der Visualisierung – die Variablen hatten eine Aktion, aber keine `RequestAction`.
- `Setbyident` nimmt nur noch `Value_1` bis `Value_12` an.
- Umstellung auf `IPSModuleStrict` mit Typangaben; Funktionsnamen, Idents und Variablennamen unverändert.

## 1.0

- Jahresvariablen, Jahresvariablen_Float.
