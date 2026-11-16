<?php

/**
 * Kleinwind-Ertrag ohne Symcon-Abhängigkeit.
 *
 * - Wind in Nabenhöhe: zwischen zwei Modellhöhen mit dem Potenzgesetz, dessen Exponent aus den beiden Höhen folgt;
 *   unterhalb der untersten bzw. nur mit 10 m: Exponent der Geländeklasse.
 * - Luftdichte ρ = p / (R · T) aus Luftdruck und Temperatur.
 * - Leistung: Herstellerkennlinie (Wind m/s → kW, mit Dichtekorrektur ρ/1,225) oder Formel P = ½ · ρ · A · cp · v³,
 *   jeweils begrenzt auf die Nennleistung, 0 unter der Einschalt- und ab der Abschaltgeschwindigkeit.
 * - Böigkeit: Erwartungswert der Leistung über v · (1 + TI · z), z normalverteilt (5-Punkt-Gauß-Hermite). Weil die
 *   Leistung mit v³ wächst, liegt der Ertrag bei böigem Wind über dem Wert für die mittlere Geschwindigkeit.
 */
final class EPWind
{
    public const RHO_STD = 1.225;

    /** Geländeklasse => [Höhenexponent α, Turbulenzintensität, Bezeichnung] (übliche Richtwerte) */
    public const TERRAIN = [
        0 => [0.11, 0.08, 'Wasser, Küste'],
        1 => [0.14, 0.12, 'offenes, flaches Gelände'],
        2 => [0.20, 0.16, 'Felder mit Hecken, Dorfrand'],
        3 => [0.25, 0.20, 'Vorstadt, lockere Bebauung'],
        4 => [0.30, 0.25, 'Stadt, Wald'],
    ];

    private const GH_NODES = [0.0, 1.355626, -1.355626, 2.856970, -2.856970];
    private const GH_WEIGHTS = [0.533333, 0.222076, 0.222076, 0.011257, 0.011257];

    /**
     * Wind in Nabenhöhe.
     *
     * @param array<int, float|null> $levels Höhe in m => Geschwindigkeit in m/s
     * @param bool $modelProfile true: Höhenprofil aus den Modellhöhen; false: nur 10 m + Exponent der Geländeklasse
     */
    public static function hubSpeed(array $levels, float $hub, float $alpha, bool $modelProfile = true): ?float
    {
        $pts = [];
        foreach ($levels as $h => $v) {
            if ($v !== null && is_numeric($v) && $v >= 0) {
                $pts[(int)$h] = (float)$v;
            }
        }
        if (!$modelProfile) {
            $pts = isset($pts[10]) ? [10 => $pts[10]] : array_slice($pts, 0, 1, true);
        }
        if (count($pts) === 0) {
            return null;
        }
        ksort($pts);
        $hs = array_keys($pts);
        $lo = $hs[0];
        if ($hub <= $lo || count($hs) === 1) {
            return $pts[$lo] * ($hub / $lo) ** $alpha;
        }
        for ($i = 1; $i < count($hs); $i++) {
            $h1 = $hs[$i - 1];
            $h2 = $hs[$i];
            if ($hub <= $h2 || $i === count($hs) - 1) {
                $v1 = $pts[$h1];
                $v2 = $pts[$h2];
                $a = ($v1 > 0.2 && $v2 > 0.2) ? log($v2 / $v1) / log($h2 / $h1) : $alpha;
                $a = max(0.0, min(0.6, $a));
                return $v1 * ($hub / $h1) ** $a;
            }
        }
        return null;
    }

    public static function airDensity(?float $pressureHpa, ?float $tempC): float
    {
        if ($pressureHpa === null || $tempC === null || $pressureHpa < 500) {
            return self::RHO_STD;
        }
        return $pressureHpa * 100.0 / (287.05 * ($tempC + 273.15));
    }

    /**
     * Leistung in kW bei Geschwindigkeit $v.
     *
     * @param array $cfg ratedKw, area (m²), cp, cutIn, cutOut, curve (Liste [m/s, kW], aufsteigend; leer = Formel)
     */
    public static function power(float $v, array $cfg, float $rho): float
    {
        $rated = (float)$cfg['ratedKw'];
        if ($v < (float)$cfg['cutIn'] || $v >= (float)$cfg['cutOut'] || $rated <= 0) {
            return 0.0;
        }
        $curve = $cfg['curve'] ?? [];
        if (count($curve) >= 2) {
            $p = self::curveValue($v, $curve) * $rho / self::RHO_STD;
        } else {
            $p = 0.5 * $rho * (float)$cfg['area'] * (float)$cfg['cp'] * $v ** 3 / 1000.0;
        }
        return max(0.0, min($rated, $p));
    }

    /** Erwartete Leistung (kW) bei mittlerer Geschwindigkeit $v und Turbulenzintensität $ti. */
    public static function expectedPower(float $v, array $cfg, float $rho, float $ti): float
    {
        if ($ti <= 0) {
            return self::power($v, $cfg, $rho);
        }
        $sum = 0.0;
        foreach (self::GH_NODES as $i => $z) {
            $sum += self::GH_WEIGHTS[$i] * self::power(max(0.0, $v * (1.0 + $ti * $z)), $cfg, $rho);
        }
        return $sum;
    }

    /** Lineare Interpolation einer Kennlinie [[m/s, kW], …]; außerhalb: 0 unterhalb, letzter Wert oberhalb. */
    public static function curveValue(float $v, array $curve): float
    {
        usort($curve, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        if ($v <= $curve[0][0]) {
            return $v < $curve[0][0] ? 0.0 : (float)$curve[0][1];
        }
        for ($i = 1; $i < count($curve); $i++) {
            if ($v <= $curve[$i][0]) {
                [$v1, $p1] = $curve[$i - 1];
                [$v2, $p2] = $curve[$i];
                return $v2 > $v1 ? $p1 + ($v - $v1) / ($v2 - $v1) * ($p2 - $p1) : (float)$p2;
            }
        }
        return (float)$curve[count($curve) - 1][1];
    }
}
