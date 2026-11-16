<?php

/**
 * Kalenderfunktionen ohne Symcon-Abhängigkeit: Tagtyp, gesetzliche Feiertage je Bundesland, Tageslänge.
 *
 * Feiertage: bundesweite Feiertage plus die landesweiten Feiertage je Bundesland. Feiertage, die nur in einzelnen
 * Gemeinden gelten (z. B. Mariä Himmelfahrt in Teilen Bayerns, Fronleichnam in Teilen Sachsens/Thüringens,
 * Augsburger Friedensfest), sind bewusst nicht enthalten. Betriebsferien und Brückentage pflegt man in der Liste
 * „Abwesenheiten / Betriebsferien“ des Moduls.
 */
final class EPCalendar
{
    public const WORKDAY  = 0;
    public const SATURDAY = 1;
    public const SUNDAY   = 2;   // Sonntag oder Feiertag

    public const STATES = [
        ''   => 'nur bundesweite Feiertage',
        'BW' => 'Baden-Württemberg', 'BY' => 'Bayern', 'BE' => 'Berlin', 'BB' => 'Brandenburg', 'HB' => 'Bremen',
        'HH' => 'Hamburg', 'HE' => 'Hessen', 'MV' => 'Mecklenburg-Vorpommern', 'NI' => 'Niedersachsen',
        'NW' => 'Nordrhein-Westfalen', 'RP' => 'Rheinland-Pfalz', 'SL' => 'Saarland', 'SN' => 'Sachsen',
        'ST' => 'Sachsen-Anhalt', 'SH' => 'Schleswig-Holstein', 'TH' => 'Thüringen',
    ];

    /** @var array<string, array<string, string>> Jahr|Land => [Y-m-d => Name] */
    private static $cache = [];

    /** Ostersonntag als [Monat, Tag] (Gaußsche Osterformel in der Form nach Meeus/Jones/Butcher, gregorianisch). */
    public static function easter(int $year): array
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return [$month, $day];
    }

    /** @return array<string, string> Y-m-d => Name */
    public static function holidays(int $year, string $state): array
    {
        $key = $year . '|' . $state;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        [$em, $ed] = self::easter($year);
        $easter = gmmktime(12, 0, 0, $em, $ed, $year);
        $rel = function (int $days) use ($easter): string {
            return gmdate('Y-m-d', $easter + $days * 86400);
        };
        $fix = function (int $m, int $d) use ($year): string {
            return sprintf('%04d-%02d-%02d', $year, $m, $d);
        };
        $in = function (array $states) use ($state): bool {
            return in_array($state, $states, true);
        };

        $h = [
            $fix(1, 1)   => 'Neujahr',
            $rel(-2)     => 'Karfreitag',
            $rel(1)      => 'Ostermontag',
            $fix(5, 1)   => 'Tag der Arbeit',
            $rel(39)     => 'Christi Himmelfahrt',
            $rel(50)     => 'Pfingstmontag',
            $fix(10, 3)  => 'Tag der Deutschen Einheit',
            $fix(12, 25) => '1. Weihnachtstag',
            $fix(12, 26) => '2. Weihnachtstag',
        ];
        if ($in(['BW', 'BY', 'ST'])) {
            $h[$fix(1, 6)] = 'Heilige Drei Könige';
        }
        if (($state === 'BE' && $year >= 2019) || ($state === 'MV' && $year >= 2023)) {
            $h[$fix(3, 8)] = 'Internationaler Frauentag';
        }
        if ($in(['BW', 'BY', 'HE', 'NW', 'RP', 'SL'])) {
            $h[$rel(60)] = 'Fronleichnam';
        }
        if ($state === 'SL') {
            $h[$fix(8, 15)] = 'Mariä Himmelfahrt';
        }
        if ($state === 'TH' && $year >= 2019) {
            $h[$fix(9, 20)] = 'Weltkindertag';
        }
        $reformation = $in(['BB', 'MV', 'SN', 'ST', 'TH']) || ($in(['HB', 'HH', 'NI', 'SH']) && $year >= 2018) || $year === 2017;
        if ($reformation) {
            $h[$fix(10, 31)] = 'Reformationstag';
        }
        if ($in(['BW', 'BY', 'NW', 'RP', 'SL'])) {
            $h[$fix(11, 1)] = 'Allerheiligen';
        }
        if ($state === 'SN') {
            // Buß- und Bettag: der Mittwoch vor dem 23. November (also zwischen 16. und 22. November)
            $nov22 = gmmktime(12, 0, 0, 11, 22, $year);
            $back = ((int)gmdate('w', $nov22) - 3 + 7) % 7;
            $h[gmdate('Y-m-d', $nov22 - $back * 86400)] = 'Buß- und Bettag';
        }
        return self::$cache[$key] = $h;
    }

    public static function holidayName(int $ts, string $state): ?string
    {
        $date = date('Y-m-d', $ts);
        return self::holidays((int)substr($date, 0, 4), $state)[$date] ?? null;
    }

    public static function dayType(int $ts, string $state): int
    {
        if (self::holidayName($ts, $state) !== null) {
            return self::SUNDAY;
        }
        $w = (int)date('N', $ts);
        if ($w === 7) {
            return self::SUNDAY;
        }
        return $w === 6 ? self::SATURDAY : self::WORKDAY;
    }

    /**
     * Astronomische Tageslänge in Stunden (Näherung über die Sonnendeklination, ohne Refraktion).
     * Dient nur als Maß für die Jahreszeit; Abweichungen von einigen Minuten spielen keine Rolle.
     */
    public static function dayLengthHours(int $ts, float $latitude): float
    {
        $n = (int)date('z', $ts) + 1;
        $decl = deg2rad(23.44) * sin(2 * M_PI * (284 + $n) / 365.0);
        $x = -tan(deg2rad($latitude)) * tan($decl);
        $x = max(-1.0, min(1.0, $x));
        return 2.0 * rad2deg(acos($x)) / 15.0;
    }
}
