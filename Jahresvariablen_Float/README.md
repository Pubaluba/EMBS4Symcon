# Jahresvariablen_Float

Zwölf Monatswerte als Variablen (Kommazahl, beliebig), z. B. für monatsabhängige Sollwerte oder Faktoren.
Werte lassen sich in der Visualisierung bedienen oder per Skript lesen und setzen.
Beim ersten Anlegen werden Standardwerte gesetzt (Jan 85, Feb 75, Mär 60, Apr 15, Mai–Aug 10, Sep 15, Okt 60, Nov 75, Dez 85);
danach bleiben eigene Werte auch über Neustarts erhalten.

Bis Version 1.0 hieß das Präfix `EMBS_VAR_Float`; siehe [CHANGELOG](../docs/CHANGELOG.md).

| Befehl | Wirkung |
|---|---|
| `EMBSVARFloat_GET($id, $monat)` | Wert des Monats (1 = Januar … 12 = Dezember), `null` bei ungültigem Monat |
| `EMBSVARFloat_SET($id, $monat, $wert)` | Wert setzen, `true`/`false` |

Beispiel: Sollwert des aktuellen Monats

```php
$wert = EMBSVARFloat_GET(12345, (int)date('n'));
```
