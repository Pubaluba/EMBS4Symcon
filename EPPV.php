<?php

require_once __DIR__ . '/EPSun.php';

/**
 * PV-Ertrag ohne Symcon-Abhängigkeit.
 *
 * Einstrahlung auf die Modulebene (isotropes Himmelsmodell):
 *   Direkt  = DNI · max(0, cos Einfallswinkel)
 *   Himmel  = DHI · (1 + cos Neigung) / 2
 *   Boden   = GHI · Albedo · (1 − cos Neigung) / 2
 * Modultemperatur nach dem NOCT-Ansatz (NOCT 45 °C): T_Zelle = T_Luft + E · 25/800.
 * Leistung DC = kWp · E/1000 · (1 + TK · (T_Zelle − 25 °C)); Systemwirkungsgrad und Wechselrichtergrenze setzt das Modul.
 */
final class EPPV
{
    public const ALBEDO = 0.2;

    /** Einstrahlung auf die Modulebene in W/m². */
    public static function poa(float $dni, float $dhi, float $ghi, float $zen, float $sunAz, float $tilt, float $orient): float
    {
        $cb = cos(deg2rad($tilt));
        $beam = $zen < 90.0 ? max(0.0, $dni) * max(0.0, EPSun::cosIncidence($zen, $sunAz, $tilt, $orient)) : 0.0;
        $sky = max(0.0, $dhi) * (1.0 + $cb) / 2.0;
        $ground = max(0.0, $ghi) * self::ALBEDO * (1.0 - $cb) / 2.0;
        return $beam + $sky + $ground;
    }

    public static function cellTemp(float $tAir, float $poa): float
    {
        return $tAir + $poa * 25.0 / 800.0;
    }

    /** DC-Leistung in W. $tempCoeffPct z. B. −0,37 (%/K). */
    public static function dcPower(float $kwp, float $poa, float $tAir, float $tempCoeffPct): float
    {
        if ($kwp <= 0 || $poa <= 0) {
            return 0.0;
        }
        $f = 1.0 + $tempCoeffPct / 100.0 * (self::cellTemp($tAir, $poa) - 25.0);
        return max(0.0, $kwp * $poa * $f);   // kWp · 1000 W · E/1000
    }
}
