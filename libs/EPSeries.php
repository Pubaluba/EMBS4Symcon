<?php

/**
 * Umrechnung von Archivwerten in Tagesprofile ohne Symcon-Abhängigkeit.
 *
 * Ergebnis ist immer ein Profil aus 96 WANDUHR-Viertelstunden (00:00–00:15 = 0 … 23:45–24:00 = 95) mit der
 * mittleren Leistung in W. Gerechnet wird über die reale Zeit; an Umstellungstagen fällt die doppelte Stunde im
 * Oktober gemittelt in dieselben Viertelstunden, die fehlende Stunde im März wird aus der vorherigen gefüllt.
 *
 * Messarten:
 *  - Leistung (W/kW/MW): 15-Minuten-Aggregat des Archivs, Avg = zeitgewichtete mittlere Leistung.
 *  - Energie je Intervall (Wh/kWh/MWh): jeder Archivwert ist die Energie eines festen Intervalls (z. B. 15 min).
 *    Das Archiv speichert nur Wertänderungen; ein fehlender Eintrag heißt „gleicher Wert wie zuvor“. Deshalb gilt
 *    für jedes Intervall der zuletzt gespeicherte Wert (höchstens $maxCarrySec alt).
 *  - Zählerstand (Wh/kWh/MWh): Energie einer Viertelstunde = Zählerstand am Ende − Zählerstand am Anfang. Mit
 *    Aggregationstyp „Zähler“ liefert das Archiv diese Zuwächse direkt (Avg = Summe der positiven Zuwächse).
 */
final class EPSeries
{
    public const QUARTERS = 96;
    public const QSEC = 900;

    public const TYPE_POWER    = 0;
    public const TYPE_INTERVAL = 1;
    public const TYPE_COUNTER  = 2;

    /** @return int[] Beginn jeder realen Viertelstunde im Bereich [dayStart, dayEnd) */
    public static function quarterStarts(int $dayStart, int $dayEnd): array
    {
        $out = [];
        for ($t = $dayStart; $t < $dayEnd; $t += self::QSEC) {
            $out[] = $t;
        }
        return $out;
    }

    /** Wanduhr-Viertelstunde (0..95) eines Zeitpunkts in der lokalen Zeitzone. */
    public static function wallQuarter(int $ts): int
    {
        return (int)date('G', $ts) * 4 + intdiv((int)date('i', $ts), 15);
    }

    /** @return array<int, null> alle realen Viertelstunden des Tages, ohne Wert */
    private static function emptyDay(int $dayStart, int $dayEnd): array
    {
        return array_fill_keys(self::quarterStarts($dayStart, $dayEnd), null);
    }

    /**
     * Reale Viertelstunden (Beginn => W oder null) auf das Wanduhr-Profil abbilden.
     *
     * @param array<int, float|null> $byStart
     * @param bool $requireCoverage true: null, wenn weniger als die Hälfte der Viertelstunden einen Wert hat
     * @return float[]|null
     */
    public static function toWallProfile(array $byStart, bool $requireCoverage = true): ?array
    {
        $sum = array_fill(0, self::QUARTERS, 0.0);
        $cnt = array_fill(0, self::QUARTERS, 0);
        $covered = 0;
        foreach ($byStart as $start => $w) {
            if ($w === null) {
                continue;
            }
            $q = self::wallQuarter((int)$start);
            $sum[$q] += $w;
            $cnt[$q]++;
            $covered++;
        }
        if ($requireCoverage && $covered * 2 < max(1, count($byStart))) {
            return null;
        }
        $out = array_fill(0, self::QUARTERS, null);
        $first = null;
        for ($q = 0; $q < self::QUARTERS; $q++) {
            if ($cnt[$q] > 0) {
                $out[$q] = $sum[$q] / $cnt[$q];
                if ($first === null) {
                    $first = $out[$q];
                }
            }
        }
        $last = $first ?? 0.0;
        for ($q = 0; $q < self::QUARTERS; $q++) {
            if ($out[$q] === null) {
                $out[$q] = $last;
            } else {
                $last = $out[$q];
            }
        }
        return $out;
    }

    /**
     * Leistung aus dem 15-Minuten-Aggregat (AC_GetAggregatedValues, Stufe 8).
     *
     * @param array<int, array> $rows Archivzeilen mit TimeStamp, Avg
     * @param float $factor Umrechnung nach W (kW: 1000)
     * @return array<int, float|null>
     */
    public static function fromAggregatedPower(array $rows, int $dayStart, int $dayEnd, float $factor): array
    {
        $out = self::emptyDay($dayStart, $dayEnd);
        foreach ($rows as $r) {
            $t = (int)($r['TimeStamp'] ?? 0);
            if (!array_key_exists($t, $out) || !isset($r['Avg'])) {
                continue;
            }
            $out[$t] = (float)$r['Avg'] * $factor;
        }
        return $out;
    }

    /**
     * Zählerstand mit Aggregationstyp „Zähler“: Avg ist der Zuwachs der Viertelstunde.
     *
     * @param float $whPerUnit Wh je Zählereinheit (kWh: 1000)
     * @return array<int, float|null>
     */
    public static function fromAggregatedCounter(array $rows, int $dayStart, int $dayEnd, float $whPerUnit): array
    {
        $out = self::emptyDay($dayStart, $dayEnd);
        foreach ($rows as $r) {
            $t = (int)($r['TimeStamp'] ?? 0);
            if (!array_key_exists($t, $out) || !isset($r['Avg'])) {
                continue;
            }
            $dur = max(1, (int)($r['Duration'] ?? self::QSEC));
            $out[$t] = max(0.0, (float)$r['Avg']) * $whPerUnit * 3600.0 / $dur;
        }
        return $out;
    }

    /**
     * Zählerstand aus den Rohwerten: Zählerstand zu einem Zeitpunkt = letzter gespeicherter Wert davor.
     * Ein Rücksprung (Zählertausch, Überlauf) macht die Viertelstunde unbekannt statt negativ.
     *
     * @param array<int, array> $rows Archivzeilen mit TimeStamp, Value (beliebige Reihenfolge)
     * @param float|null $prev Zählerstand vor Tagesbeginn (letzter Wert vor $dayStart), null = unbekannt
     * @return array<int, float|null>
     */
    public static function fromCounterReadings(array $rows, ?float $prev, int $dayStart, int $dayEnd, float $whPerUnit): array
    {
        $pts = self::sortedPoints($rows);
        $out = self::emptyDay($dayStart, $dayEnd);
        $idx = 0;
        $n = count($pts);
        $cur = $prev;
        $at = function (int $t) use (&$idx, &$cur, $pts, $n) {
            while ($idx < $n && $pts[$idx][0] <= $t) {
                $cur = $pts[$idx][1];
                $idx++;
            }
            return $cur;
        };
        $ma = $at($dayStart);
        foreach (array_keys($out) as $a) {
            $mb = $at($a + self::QSEC);
            if ($ma !== null && $mb !== null && $mb >= $ma) {
                $out[$a] = ($mb - $ma) * $whPerUnit * 4.0;
            }
            $ma = $mb;
        }
        return $out;
    }

    /**
     * Energie je festem Intervall aus den Rohwerten.
     *
     * @param array<int, array> $rows Archivzeilen mit TimeStamp, Value; sollten bis $dayEnd + Intervall reichen
     * @param array|null $prevRow letzte Archivzeile vor Tagesbeginn (für den übertragenen Wert), null = keine
     * @param int $intervalMin Intervalllänge in Minuten (Teiler von 60)
     * @param bool $stampIsEnd true: Zeitstempel markiert das Intervallende (übliche Lastgang-Zählweise)
     * @param float $whPerUnit Wh je Werteinheit (kWh: 1000)
     * @param int $maxCarrySec ältere Werte gelten als Ausfall statt als „unverändert“
     * @return array<int, float|null>
     */
    public static function fromIntervalEnergy(array $rows, ?array $prevRow, int $dayStart, int $dayEnd, int $intervalMin,
                                              bool $stampIsEnd, float $whPerUnit, int $maxCarrySec = 86400): array
    {
        $iv = max(1, $intervalMin) * 60;
        if ($prevRow !== null) {
            $rows[] = $prevRow;
        }
        $pts = self::sortedPoints($rows);
        $n = count($pts);
        $idx = 0;
        $last = null;
        $steps = [];
        for ($s = $dayStart; $s < $dayEnd; $s += $iv) {
            $e = $s + $iv;
            $lookup = ($stampIsEnd ? $e : $s) + intdiv($iv, 2);
            while ($idx < $n && $pts[$idx][0] <= $lookup) {
                $last = $pts[$idx];
                $idx++;
            }
            $w = null;
            if ($last !== null && $lookup - $last[0] <= $maxCarrySec) {
                $w = max(0.0, $last[1]) * $whPerUnit * 3600.0 / $iv;
            }
            $steps[] = [$s, min($e, $dayEnd), $w];
        }
        return self::stepsToQuarters($steps, $dayStart, $dayEnd);
    }

    /**
     * Stufenfunktion [von, bis, W|null] auf reale Viertelstunden verteilen (zeitgewichtetes Mittel).
     * Eine Viertelstunde mit weniger als 7,5 min bekannter Leistung bleibt unbekannt.
     *
     * @param array<int, array{0:int,1:int,2:float|null}> $steps aufsteigend, nicht überlappend
     * @return array<int, float|null>
     */
    public static function stepsToQuarters(array $steps, int $dayStart, int $dayEnd, bool $unknownIsZero = false): array
    {
        $out = self::emptyDay($dayStart, $dayEnd);
        foreach (array_keys($out) as $a) {
            $b = $a + self::QSEC;
            $ws = 0.0;
            $sec = 0;
            foreach ($steps as [$from, $to, $w]) {
                if ($to <= $a) {
                    continue;
                }
                if ($from >= $b) {
                    break;
                }
                if ($w === null) {
                    continue;
                }
                $ov = min($b, $to) - max($a, $from);
                if ($ov > 0) {
                    $ws += $w * $ov;
                    $sec += $ov;
                }
            }
            if ($unknownIsZero) {
                $out[$a] = $ws / self::QSEC;
            } elseif ($sec * 2 >= self::QSEC) {
                $out[$a] = $ws / $sec;
            }
        }
        return $out;
    }

    /**
     * 96 Viertelstunden auf die gewünschte Auflösung mitteln (15, 30 oder 60 min).
     *
     * @param float[] $q96
     * @return float[]
     */
    public static function resample(array $q96, int $slotMinutes): array
    {
        $per = max(1, intdiv($slotMinutes, 15));
        if ($per === 1) {
            return array_values($q96);
        }
        $out = [];
        for ($i = 0; $i < self::QUARTERS; $i += $per) {
            $out[] = array_sum(array_slice($q96, $i, $per)) / $per;
        }
        return $out;
    }

    /** Energie in kWh eines Profils mit mittlerer Leistung (W) je Slot. */
    public static function kwh(array $profileW, int $slotMinutes = 15): float
    {
        return array_sum($profileW) * $slotMinutes / 60.0 / 1000.0;
    }

    /** @return array<int, array{0:int,1:float}> aufsteigend nach Zeit */
    private static function sortedPoints(array $rows): array
    {
        $pts = [];
        foreach ($rows as $r) {
            if (isset($r['TimeStamp'], $r['Value']) && is_numeric($r['Value'])) {
                $pts[] = [(int)$r['TimeStamp'], (float)$r['Value']];
            }
        }
        usort($pts, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        return $pts;
    }
}
