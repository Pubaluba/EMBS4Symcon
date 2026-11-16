<?php

require_once __DIR__ . '/EPSeries.php';
require_once __DIR__ . '/EPMath.php';

/**
 * Gemeinsame Ausgabe aller Prognosemodule ohne Symcon-Abhängigkeit: Tagesprognose im Vertragsformat 1.0,
 * Energie im Zeitfenster, Begrenzung auf eine Höchstleistung, Kalibrierfaktor aus Ist/Modell.
 */
final class EPForecast
{
    public const CONTRACT = '1.0';

    /**
     * Tagesprognose im Vertragsformat aus 96 Viertelstunden (W).
     *
     * @param array{p10: float[], p50: float[], p90: float[], mean?: float[]} $q
     */
    public static function build(string $date, array $q, int $resolution, array $extra = []): array
    {
        $res = in_array($resolution, [15, 30, 60], true) ? $resolution : 15;
        $mean = $q['mean'] ?? $q['p50'];
        $r = function (array $q96) use ($res) {
            return array_map(function ($v) {
                return round((float)$v);
            }, EPSeries::resample($q96, $res));
        };
        return array_merge([
            'contractVersion' => self::CONTRACT, 'date' => $date, 'slots' => intdiv(1440, $res), 'resolution' => $res . 'min', 'unit' => 'W',
            'p10' => $r($q['p10']), 'p50' => $r($q['p50']), 'p90' => $r($q['p90']), 'mean' => $r($mean),
            'kwh' => round(EPSeries::kwh($mean), 2), 'kwhP10' => round(EPSeries::kwh($q['p10']), 2), 'kwhP90' => round(EPSeries::kwh($q['p90']), 2),
            'generated' => time(),
        ], $extra);
    }

    /**
     * Energie im Zeitfenster [$from, $to) aus Tagesprognosen.
     *
     * @param callable $forecast fn(int $offset): ?array – Tagesprognose für heute + $offset oder null
     */
    public static function window(callable $forecast, int $horizon, int $from, int $to): array
    {
        $out = ['contractVersion' => self::CONTRACT, 'from' => $from, 'to' => $to, 'kwh' => 0.0, 'kwhP10' => 0.0, 'kwhP90' => 0.0, 'coverage' => 0.0];
        if ($to <= $from) {
            return $out;
        }
        $today = strtotime('today');
        $cache = [];
        $covered = 0;
        for ($t = $from; $t < $to;) {
            $day = strtotime('today', $t);
            $next = min($to, $t + EPSeries::QSEC - ($t - $day) % EPSeries::QSEC);
            $next = $next > $t ? $next : $t + 1;
            $off = (int)round(($day - $today) / 86400);
            if ($off >= 0 && $off < $horizon) {
                if (!array_key_exists($off, $cache)) {
                    $f = $forecast($off);
                    $cache[$off] = (is_array($f) && isset($f['mean'])) ? $f : null;
                }
                $f = $cache[$off];
                if ($f !== null) {
                    $slot = intdiv(EPSeries::wallQuarter($t) * 15, intdiv(1440, count($f['mean'])));
                    $h = ($next - $t) / 3600.0 / 1000.0;
                    $out['kwh'] += $f['mean'][$slot] * $h;
                    $out['kwhP10'] += $f['p10'][$slot] * $h;
                    $out['kwhP90'] += $f['p90'][$slot] * $h;
                    $covered += $next - $t;
                }
            }
            $t = $next;
        }
        foreach (['kwh', 'kwhP10', 'kwhP90'] as $k) {
            $out[$k] = round($out[$k], 3);
        }
        $out['coverage'] = round($covered / ($to - $from), 3);
        return $out;
    }

    /**
     * Profil auf $maxW begrenzen und die Tagesenergie erhalten (für Erzeuger mit feststehender Tagesenergie, z. B.
     * BHKW): Der abgeschnittene Teil geht zuerst in Viertelstunden, in denen schon erzeugt wird, und erst wenn dort
     * kein Platz mehr ist, gleichmäßig in die übrigen (die Anlage läuft dann länger). Passt die Energie insgesamt
     * nicht in 96 × $maxW, bleibt der Überschuss weg.
     *
     * @param float[] $q
     * @return float[]
     */
    public static function capRedistribute(array $q, float $maxW): array
    {
        if ($maxW <= 0) {
            return $q;
        }
        $excess = 0.0;
        foreach ($q as $i => $v) {
            if ($v > $maxW) {
                $excess += $v - $maxW;
                $q[$i] = $maxW;
            }
        }
        foreach ([true, false] as $onlyRunning) {
            if ($excess <= 1e-6) {
                break;
            }
            $room = 0.0;
            foreach ($q as $v) {
                if ($v < $maxW && (!$onlyRunning || $v > 0)) {
                    $room += $maxW - $v;
                }
            }
            if ($room <= 1e-6) {
                continue;
            }
            $f = min(1.0, $excess / $room);
            foreach ($q as $i => $v) {
                if ($v < $maxW && (!$onlyRunning || $v > 0)) {
                    $q[$i] = $v + ($maxW - $v) * $f;
                }
            }
            $excess -= $f * $room;
        }
        return $q;
    }

    /** Jeden Wert auf $maxW begrenzen (Wechselrichter, Nennleistung). */
    public static function cap(array $q, float $maxW): array
    {
        if ($maxW <= 0) {
            return $q;
        }
        return array_map(function ($v) use ($maxW) {
            return min($maxW, max(0.0, (float)$v));
        }, $q);
    }

    /**
     * Kalibrierung aus Tagesenergien: Faktor = Median(Ist/Modell), Band = 10-/90-%-Quantil relativ zum Median.
     *
     * @param array<int, array{0: float, 1: float}> $pairs [Ist-kWh, Modell-kWh]
     * @return array{factor: float, low: float, high: float, days: int}|null null bei weniger als $minDays Tagen
     */
    public static function calibration(array $pairs, float $minModelKwh, int $minDays, float $minFactor, float $maxFactor): ?array
    {
        $ratios = [];
        foreach ($pairs as [$ist, $model]) {
            if ($model >= $minModelKwh && $ist >= 0) {
                $ratios[] = $ist / $model;
            }
        }
        if (count($ratios) < $minDays) {
            return null;
        }
        $med = EPMath::median($ratios);
        $factor = max($minFactor, min($maxFactor, $med));
        $low = $med > 0 ? max(0.2, min(1.0, EPMath::quantile($ratios, 0.1) / $med)) : 0.7;
        $high = $med > 0 ? max(1.0, min(2.5, EPMath::quantile($ratios, 0.9) / $med)) : 1.3;
        return ['factor' => $factor, 'low' => $low, 'high' => $high, 'days' => count($ratios)];
    }
}
