<?php

require_once __DIR__ . '/EPMath.php';

/**
 * Gradtag-Regression ohne Symcon-Abhängigkeit: Tagesenergie = a + b · Heizgrad (+ c · Kühlgrad).
 * Heizgrad = max(0, Heizgrenze − Tagesmittel), Kühlgrad = max(0, Tagesmittel − Kühlgrenze).
 * Das Band ist die Streuung Ist/Modell (10-/50-/90-%-Quantil) über die Lerntage.
 */
final class EPDegreeDay
{
    public const HEAT = 0;
    public const COOL = 1;
    public const BOTH = 2;

    public static function features(int $mode, float $t, float $heatLimit, float $coolLimit): array
    {
        $hdd = max(0.0, $heatLimit - $t);
        $cdd = max(0.0, $t - $coolLimit);
        if ($mode === self::COOL) {
            return [1.0, $cdd];
        }
        return $mode === self::BOTH ? [1.0, $hdd, $cdd] : [1.0, $hdd];
    }

    /**
     * @param array<int, array{t: float, kwh: float}> $days
     * @return array{coef: float[], band: float[], n: int, mode: int, heatLimit: float, coolLimit: float}|null
     */
    public static function fit(array $days, int $mode, float $heatLimit, float $coolLimit, int $minDays): ?array
    {
        $X = [];
        $y = [];
        foreach ($days as $d) {
            $X[] = self::features($mode, (float)$d['t'], $heatLimit, $coolLimit);
            $y[] = (float)$d['kwh'];
        }
        if (count($X) < $minDays) {
            return null;
        }
        $coef = EPMath::leastSquares($X, $y);
        if ($coef === null) {
            return null;
        }
        $model = ['coef' => $coef, 'band' => [0.75, 1.0, 1.3], 'n' => count($X), 'mode' => $mode, 'heatLimit' => $heatLimit, 'coolLimit' => $coolLimit];
        $ratios = [];
        foreach ($days as $i => $d) {
            $p = self::predict($model, (float)$d['t']);
            if ($p >= 1.0) {
                $ratios[] = $y[$i] / $p;
            }
        }
        if (count($ratios) >= 15) {
            $c = function (float $v): float {
                return max(0.3, min(3.0, $v));
            };
            $model['band'] = [$c(EPMath::quantile($ratios, 0.1)), $c(EPMath::quantile($ratios, 0.5)), $c(EPMath::quantile($ratios, 0.9))];
        }
        return $model;
    }

    public static function predict(array $model, float $t): float
    {
        $x = self::features($model['mode'], $t, $model['heatLimit'], $model['coolLimit']);
        $s = 0.0;
        foreach ($model['coef'] as $i => $c) {
            $s += $c * $x[$i];
        }
        return max(0.0, $s);
    }
}
