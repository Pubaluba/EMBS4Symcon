<?php

/**
 * Sonnenstand ohne Symcon-Abhängigkeit (Näherungsformeln nach dem NOAA-Sonnenrechner, Genauigkeit ~0,1°, ohne
 * Refraktion). Azimut als Kompassrichtung: 0 = Nord, 90 = Ost, 180 = Süd, 270 = West.
 */
final class EPSun
{
    /** @return array{0: float, 1: float} [Zenitwinkel in Grad, Azimut in Grad] */
    public static function position(int $ts, float $lat, float $lon): array
    {
        $jd = $ts / 86400.0 + 2440587.5;
        $t = ($jd - 2451545.0) / 36525.0;
        $l0 = fmod(280.46646 + $t * (36000.76983 + $t * 0.0003032), 360.0);
        $m = 357.52911 + $t * (35999.05029 - 0.0001537 * $t);
        $e = 0.016708634 - $t * (0.000042037 + 0.0000001267 * $t);
        $mr = deg2rad($m);
        $c = sin($mr) * (1.914602 - $t * (0.004817 + 0.000014 * $t)) + sin(2 * $mr) * (0.019993 - 0.000101 * $t) + sin(3 * $mr) * 0.000289;
        $omega = deg2rad(125.04 - 1934.136 * $t);
        $lambda = deg2rad($l0 + $c - 0.00569 - 0.00478 * sin($omega));
        $eps0 = 23.0 + (26.0 + (21.448 - $t * (46.815 + $t * (0.00059 - $t * 0.001813))) / 60.0) / 60.0;
        $eps = deg2rad($eps0 + 0.00256 * cos($omega));
        $decl = asin(sin($eps) * sin($lambda));
        $y = tan($eps / 2) ** 2;
        $l0r = deg2rad($l0);
        $eqTime = 4.0 * rad2deg($y * sin(2 * $l0r) - 2 * $e * sin($mr) + 4 * $e * $y * sin($mr) * cos(2 * $l0r)
                - 0.5 * $y * $y * sin(4 * $l0r) - 1.25 * $e * $e * sin(2 * $mr));   // Minuten
        $utcMin = (($ts % 86400) + 86400) % 86400 / 60.0;
        $tst = fmod($utcMin + $eqTime + 4.0 * $lon, 1440.0);
        if ($tst < 0) {
            $tst += 1440.0;
        }
        $ha = deg2rad($tst / 4.0 - 180.0);
        $latr = deg2rad($lat);
        $cosZ = sin($latr) * sin($decl) + cos($latr) * cos($decl) * cos($ha);
        $zen = rad2deg(acos(max(-1.0, min(1.0, $cosZ))));
        $az = rad2deg(atan2(sin($ha), cos($ha) * sin($latr) - tan($decl) * cos($latr))) + 180.0;
        return [$zen, fmod($az + 360.0, 360.0)];
    }

    /** Kosinus des Einfallswinkels auf eine Fläche mit Neigung $tilt (0 = waagrecht) und Ausrichtung $orient (Kompass). */
    public static function cosIncidence(float $zen, float $sunAz, float $tilt, float $orient): float
    {
        $z = deg2rad($zen);
        $b = deg2rad($tilt);
        return cos($z) * cos($b) + sin($z) * sin($b) * cos(deg2rad($sunAz - $orient));
    }
}
