<?php

/**
 * Open-Meteo-Antworten ohne Symcon-Abhängigkeit aufbereiten.
 *
 * Laut Open-Meteo-Doku: Strahlungswerte sind das Mittel der vorangegangenen Stunde, die übrigen Werte gelten zum
 * angegebenen Zeitpunkt. Mit timeformat=unixtime sind die Zeitstempel Sekunden seit 1970 (UTC).
 */
final class EPOpenMeteo
{
    public const BASE = 'https://api.open-meteo.com/v1/forecast';

    public static function url(float $lat, float $lon, array $hourly, int $pastDays, int $forecastDays): string
    {
        return sprintf('%s?latitude=%.4F&longitude=%.4F&hourly=%s&past_days=%d&forecast_days=%d&timeformat=unixtime&timezone=auto&wind_speed_unit=ms',
            self::BASE, $lat, $lon, implode(',', $hourly), max(0, min(92, $pastDays)), max(1, min(16, $forecastDays)));
    }

    /**
     * @return array<int, array<string, float|null>> Zeitstempel => [Variable => Wert]
     */
    public static function parseHourly(array $data, array $vars): array
    {
        $h = $data['hourly'] ?? null;
        if (!is_array($h) || !isset($h['time']) || !is_array($h['time'])) {
            throw new RuntimeException('Antwort ohne Stundenwerte' . (isset($data['reason']) ? ': ' . $data['reason'] : ''));
        }
        $out = [];
        foreach ($h['time'] as $i => $ts) {
            $row = [];
            foreach ($vars as $v) {
                $val = $h[$v][$i] ?? null;
                $row[$v] = is_numeric($val) ? (float)$val : null;
            }
            $out[(int)$ts] = $row;
        }
        return $out;
    }

    /** Wert eines Momentanwerts (z. B. Temperatur, Wind) zum Zeitpunkt $ts, linear zwischen den Stunden. */
    public static function interpolate(array $hourly, string $var, int $ts): ?float
    {
        $h0 = $ts - (($ts % 3600) + 3600) % 3600;
        $v0 = $hourly[$h0][$var] ?? null;
        $v1 = $hourly[$h0 + 3600][$var] ?? null;
        if ($v0 === null || $v1 === null) {
            return $v0 ?? $v1;
        }
        return $v0 + ($v1 - $v0) * ($ts - $h0) / 3600.0;
    }

    /** Wert eines Stundenmittels (Strahlung): der Eintrag am Ende der Stunde, in der $ts liegt. */
    public static function hourMean(array $hourly, string $var, int $ts): ?float
    {
        $end = $ts - (($ts % 3600) + 3600) % 3600 + 3600;
        return $hourly[$end][$var] ?? null;
    }

    /** Tagesmittel (lokales Datum Y-m-d) eines Momentanwerts; nur Tage mit mindestens 20 Stundenwerten. */
    public static function dailyMeans(array $hourly, string $var): array
    {
        $sum = [];
        $cnt = [];
        foreach ($hourly as $ts => $row) {
            if (($row[$var] ?? null) === null) {
                continue;
            }
            $d = date('Y-m-d', (int)$ts);
            $sum[$d] = ($sum[$d] ?? 0.0) + $row[$var];
            $cnt[$d] = ($cnt[$d] ?? 0) + 1;
        }
        $out = [];
        foreach ($sum as $d => $s) {
            if ($cnt[$d] >= 20) {
                $out[$d] = $s / $cnt[$d];
            }
        }
        return $out;
    }
}
