<?php

require_once __DIR__ . '/EPSeries.php';

/**
 * Wallbox-Planung ohne Symcon-Abhängigkeit.
 *
 * Betriebsarten:
 *  - steuerbar: Das EMS entscheidet, wann geladen wird. Das Modul meldet nur den Bedarf (kWh, fertig bis,
 *    spätester Start); in die Verbrauchsprognose geht nichts ein.
 *  - sofort laden: Ein Ladevorgang (per Knopf oder beim Anstecken) lädt mit voller Leistung bis zum Ziel-SoC.
 *    Dieser Block steht als feste Last in der Prognose.
 */
final class EPWallbox
{
    public const MODE_CONTROLLED = 0;
    public const MODE_IMMEDIATE  = 1;

    /** SoC in Prozent; Werte zwischen 0 und 1 gelten als Anteil, wenn $isFraction gesetzt ist. */
    public static function normalizeSoc(float $value, bool $isFraction): float
    {
        $pct = $isFraction ? $value * 100.0 : $value;
        return max(0.0, min(100.0, $pct));
    }

    /** Energie aus dem Netz (kWh), um von $soc auf $target zu kommen; $efficiency = Ladewirkungsgrad (0..1]. */
    public static function needKwh(float $capacityKwh, float $soc, float $target, float $efficiency): float
    {
        if ($capacityKwh <= 0 || $target <= $soc) {
            return 0.0;
        }
        return $capacityKwh * ($target - $soc) / 100.0 / max(0.5, min(1.0, $efficiency));
    }

    /** „HH:MM“ → [Stunde, Minute] oder null. */
    public static function parseTime(string $hhmm): ?array
    {
        if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*$/', $hhmm, $m)) {
            return null;
        }
        $h = (int)$m[1];
        $i = (int)$m[2];
        return ($h <= 23 && $i <= 59) ? [$h, $i] : null;
    }

    /** Nächster Zeitpunkt mit der Uhrzeit $hhmm ab $now (heute, falls noch nicht vorbei, sonst morgen). */
    public static function nextTime(int $now, string $hhmm): ?int
    {
        $t = self::parseTime($hhmm);
        if ($t === null) {
            return null;
        }
        $today = mktime($t[0], $t[1], 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
        if ($today > $now) {
            return $today;
        }
        return mktime($t[0], $t[1], 0, (int)date('n', $now), (int)date('j', $now) + 1, (int)date('Y', $now));
    }

    /** Ladedauer in Sekunden für $kwh bei $powerKw. */
    public static function durationSec(float $kwh, float $powerKw): int
    {
        if ($kwh <= 0 || $powerKw <= 0) {
            return 0;
        }
        return (int)round($kwh / $powerKw * 3600.0);
    }

    /**
     * Ladeblock eines Sofort-Ladevorgangs: von Beginn bis zum geschätzten Ende.
     * $remainingKwh ist der Restbedarf ab $now; liegt der Beginn in der Vergangenheit, zählt die Zeit seit Beginn mit.
     *
     * @return array{0:int,1:int} [von, bis]
     */
    public static function block(int $start, int $now, float $remainingKwh, float $powerKw): array
    {
        $from = min($start, $now);
        $to = max($start, $now) + self::durationSec($remainingKwh, $powerKw);
        return [$from, $to];
    }

    /**
     * Mittlere Leistung (W) je Wanduhr-Viertelstunde eines Tages für einen Block [von, bis) mit $powerW.
     *
     * @return float[] 96 Werte
     */
    public static function dayOverlay(int $dayStart, int $dayEnd, int $from, int $to, float $powerW): array
    {
        if ($to <= $dayStart || $from >= $dayEnd || $to <= $from || $powerW <= 0) {
            return array_fill(0, EPSeries::QUARTERS, 0.0);
        }
        $q = EPSeries::stepsToQuarters([[$from, $to, $powerW]], $dayStart, $dayEnd, true);
        return EPSeries::toWallProfile($q, false);
    }
}
