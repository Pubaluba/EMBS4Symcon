<?php

/** Statistik-Hilfen ohne Symcon-Abhängigkeit. */
final class EPMath
{
    /**
     * Gewichtetes Quantil. Werte werden aufsteigend sortiert; das Quantil ist der erste Wert, bei dem das
     * kumulierte Gewicht den Anteil $p des Gesamtgewichts erreicht (zwischen zwei Werten linear interpoliert).
     *
     * @param float[] $values
     * @param float[] $weights gleiche Schlüssel wie $values
     */
    public static function weightedQuantile(array $values, array $weights, float $p): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $pairs = [];
        foreach ($values as $i => $v) {
            $pairs[] = [(float)$v, max(0.0, (float)($weights[$i] ?? 0.0))];
        }
        usort($pairs, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        $total = 0.0;
        foreach ($pairs as $pv) {
            $total += $pv[1];
        }
        if ($total <= 0.0) {
            return self::quantile(array_column($pairs, 0), $p);
        }
        // Mittelpunkt-Regel: jeder Wert steht für die Mitte seines Gewichtsanteils
        $p = max(0.0, min(1.0, $p));
        $target = $p * $total;
        $cum = 0.0;
        $prevPos = null;
        $prevVal = null;
        foreach ($pairs as [$v, $w]) {
            $pos = $cum + $w / 2.0;
            if ($pos >= $target) {
                if ($prevPos === null || $pos === $prevPos) {
                    return $v;
                }
                $f = ($target - $prevPos) / ($pos - $prevPos);
                return $prevVal + $f * ($v - $prevVal);
            }
            $prevPos = $pos;
            $prevVal = $v;
            $cum += $w;
        }
        return $pairs[$n - 1][0];
    }

    /** Ungewichtetes Quantil (lineare Interpolation) einer beliebigen Liste. */
    public static function quantile(array $values, float $p): float
    {
        $values = array_values(array_map('floatval', $values));
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        sort($values);
        $pos = max(0.0, min(1.0, $p)) * ($n - 1);
        $lo = (int)floor($pos);
        $hi = (int)ceil($pos);
        return $values[$lo] + ($pos - $lo) * ($values[$hi] - $values[$lo]);
    }

    /**
     * Gewichte für die nächsten Nachbarn: Gauß-Kern, Breite = mittlere Distanz der Nachbarn.
     * Gleiche Distanzen ergeben gleiche Gewichte; Distanz 0 ergibt Gewicht 1.
     *
     * @param float[] $distances
     * @return float[]
     */
    public static function kernelWeights(array $distances): array
    {
        $n = count($distances);
        if ($n === 0) {
            return [];
        }
        $sigma = max(1e-6, array_sum($distances) / $n);
        $out = [];
        foreach ($distances as $i => $d) {
            $out[$i] = exp(-0.5 * ($d / $sigma) ** 2);
        }
        return $out;
    }

    /**
     * Kleinste Quadrate über die Normalgleichungen (XᵀX)·b = Xᵀy, gelöst mit Gauß-Elimination und Spaltenpivot.
     * Liefert null bei zu wenigen Zeilen oder (nahezu) singulärer Matrix.
     *
     * @param array<int, float[]> $X Zeilen mit gleicher Spaltenzahl
     * @param float[] $y
     * @return float[]|null Koeffizienten
     */
    public static function leastSquares(array $X, array $y): ?array
    {
        $rows = count($X);
        if ($rows === 0 || $rows !== count($y)) {
            return null;
        }
        $cols = count(reset($X));
        if ($rows < $cols) {
            return null;
        }
        $A = array_fill(0, $cols, array_fill(0, $cols + 1, 0.0));
        foreach (array_values($X) as $r => $row) {
            $row = array_values($row);
            $yy = (float)array_values($y)[$r];
            for ($i = 0; $i < $cols; $i++) {
                for ($j = 0; $j < $cols; $j++) {
                    $A[$i][$j] += $row[$i] * $row[$j];
                }
                $A[$i][$cols] += $row[$i] * $yy;
            }
        }
        for ($c = 0; $c < $cols; $c++) {
            $pivot = $c;
            for ($r = $c + 1; $r < $cols; $r++) {
                if (abs($A[$r][$c]) > abs($A[$pivot][$c])) {
                    $pivot = $r;
                }
            }
            if (abs($A[$pivot][$c]) < 1e-9) {
                return null;
            }
            if ($pivot !== $c) {
                [$A[$pivot], $A[$c]] = [$A[$c], $A[$pivot]];
            }
            for ($r = 0; $r < $cols; $r++) {
                if ($r === $c) {
                    continue;
                }
                $f = $A[$r][$c] / $A[$c][$c];
                for ($k = $c; $k <= $cols; $k++) {
                    $A[$r][$k] -= $f * $A[$c][$k];
                }
            }
        }
        $b = [];
        for ($i = 0; $i < $cols; $i++) {
            $b[$i] = $A[$i][$cols] / $A[$i][$i];
        }
        return $b;
    }

    public static function median(array $values): float
    {
        return self::quantile($values, 0.5);
    }
}
