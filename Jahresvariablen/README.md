# Jahresvariablen

Zwölf Monatswerte als Variablen (Ganzzahl, 0–100 % (Schieberegler)), z. B. für monatsabhängige Sollwerte oder Faktoren.
Werte lassen sich in der Visualisierung bedienen oder per Skript lesen und setzen.
Beim ersten Anlegen werden Standardwerte gesetzt (Jan 85, Feb 75, Mär 60, Apr 15, Mai–Aug 10, Sep 15, Okt 60, Nov 75, Dez 85);
danach bleiben eigene Werte auch über Neustarts erhalten.

| Befehl | Wirkung |
|---|---|
| `Jahresvariablen_Get($id, $monat)` | Wert des Monats (1 = Januar … 12 = Dezember), `null` bei ungültigem Monat |
| `Jahresvariablen_Set($id, $monat, $wert)` | Wert setzen, `true`/`false` |
| `Jahresvariablen_Setbyident($id, $ident, $wert)` | Wert per Ident (`Value_1` … `Value_12`) setzen |

Beispiel: Sollwert des aktuellen Monats

```php
$wert = Jahresvariablen_Get(12345, (int)date('n'));
```
