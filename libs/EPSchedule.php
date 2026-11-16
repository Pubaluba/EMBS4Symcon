<?php

/**
 * Fahrpläne, Strompreise und Zukaufpläne ohne Symcon-Abhängigkeit in ein einheitliches Format bringen.
 *
 * Fahrplan (Vertrag 1.0):
 *   {"id": "wp", "name": "Wärmepumpe", "entries": [{"from": <Unix>, "to": <Unix>, "type": "request|normal|lock", "label": "…", "kw": 3.5}]}
 *   type: request = Anforderung (z. B. EVU/EMS fordert Betrieb an), normal = Normalbetrieb, lock = Sperre.
 *   Eingang tolerant: from/start/begin/startsAt, to/end/endsAt oder duration (s), Zeit als Unix-Sekunden,
 *   Millisekunden oder ISO-Text; type auch deutsch (Anforderung, Normal, Sperre) oder 1/0/-1; Leistung kw/power oder w.
 *
 * Preise: [{"from": <Unix>, "to": <Unix>, "price": <ct/kWh>}]
 *   Eingang: Liste mit from/start/startsAt/start_timestamp und price/total/value/marketprice, Tibber-Form
 *   {"today": […], "tomorrow": […]}, aWATTar-Form {"data": […]}. Einheit automatisch (unit-Feld oder Größenordnung)
 *   oder fest: ct/kWh, €/kWh, €/MWh.
 *
 * Zukaufplan: [{"from", "to", "kw", "kwh", "label"}] – geplanter Netzbezug, z. B. vom EMS zu günstigen Preisen.
 */
final class EPSchedule
{
    public const UNIT_AUTO = 0;
    public const UNIT_CT_KWH = 1;
    public const UNIT_EUR_KWH = 2;
    public const UNIT_EUR_MWH = 3;

    private const MAX_ENTRIES = 1000;

    /** Zeitangabe → Unix-Sekunden oder null. */
    public static function parseTime($v): ?int
    {
        if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
            $n = (float)$v;
            if ($n > 1e11) {
                $n /= 1000.0;   // Millisekunden
            }
            return $n > 0 ? (int)round($n) : null;
        }
        if (is_string($v) && trim($v) !== '') {
            $t = strtotime($v);
            return $t === false ? null : $t;
        }
        return null;
    }

    public static function normalizeType($v): string
    {
        $s = strtolower(trim((string)$v));
        $map = [
            'request' => 'request', 'anforderung' => 'request', 'demand' => 'request', 'on' => 'request', '1' => 'request', 'ein' => 'request',
            'lock' => 'lock', 'sperre' => 'lock', 'block' => 'lock', 'blocked' => 'lock', 'off' => 'lock', '-1' => 'lock', 'gesperrt' => 'lock', 'aus' => 'lock',
            'purchase' => 'purchase', 'zukauf' => 'purchase', 'buy' => 'purchase', 'bezug' => 'purchase',
        ];
        return $map[$s] ?? 'normal';
    }

    private static function first(array $row, array $keys)
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                return $row[$k];
            }
        }
        return null;
    }

    /**
     * Einträge (Fahrplan oder Zukaufplan) normalisieren, sortieren, ungültige verwerfen.
     *
     * @return array<int, array{from: int, to: int, type: string, label: string, kw: float|null}>
     */
    public static function normalizeEntries(array $list, string $defaultType = 'normal'): array
    {
        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $from = self::parseTime(self::first($row, ['from', 'start', 'begin', 'startsAt', 'start_timestamp']));
            $to = self::parseTime(self::first($row, ['to', 'end', 'endsAt', 'end_timestamp']));
            if ($from !== null && $to === null && is_numeric($row['duration'] ?? null)) {
                $to = $from + (int)$row['duration'];
            }
            if ($from === null || $to === null || $to <= $from) {
                continue;
            }
            $kw = self::first($row, ['kw', 'power', 'kW']);
            if ($kw === null && is_numeric($row['w'] ?? null)) {
                $kw = (float)$row['w'] / 1000.0;
            }
            $type = isset($row['type']) || isset($row['typ']) ? self::normalizeType($row['type'] ?? $row['typ']) : $defaultType;
            $entry = ['from' => $from, 'to' => $to, 'type' => $type,
                      'label' => mb_substr(trim((string)(self::first($row, ['label', 'name', 'text', 'reason']) ?? '')), 0, 60),
                      'kw' => is_numeric($kw) ? round((float)$kw, 3) : null];
            if (is_numeric($row['kwh'] ?? null)) {
                $entry['kwh'] = round((float)$row['kwh'], 3);
            }
            $out[] = $entry;
            if (count($out) >= self::MAX_ENTRIES) {
                break;
            }
        }
        usort($out, function ($a, $b) {
            return $a['from'] <=> $b['from'];
        });
        return $out;
    }

    /**
     * Ein Fahrplan aus JSON-Text oder Array; eine reine Liste gilt als Einträge.
     *
     * @return array{id: string, name: string, entries: array}|null
     */
    public static function normalizeSchedule($data, string $id, string $name): ?array
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (!is_array($data)) {
            return null;
        }
        $isList = array_keys($data) === range(0, count($data) - 1);
        $entries = $isList ? $data : ($data['entries'] ?? $data['schedule'] ?? $data['fahrplan'] ?? []);
        $sid = trim((string)($isList ? $id : ($data['id'] ?? $id)));
        $sname = trim((string)($isList ? $name : ($data['name'] ?? $name)));
        return ['id' => $sid !== '' ? $sid : $id, 'name' => $sname !== '' ? $sname : $id,
                'entries' => self::normalizeEntries(is_array($entries) ? $entries : [])];
    }

    /**
     * Preise normalisieren auf ct/kWh, sortiert; fehlendes Ende = nächster Beginn bzw. +1 h.
     *
     * @return array<int, array{from: int, to: int, price: float}>
     */
    public static function normalizePrices($data, int $unit = self::UNIT_AUTO): array
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (!is_array($data)) {
            return [];
        }
        $declared = strtolower((string)($data['unit'] ?? ''));
        if (isset($data['today']) || isset($data['tomorrow'])) {
            $list = array_merge((array)($data['today'] ?? []), (array)($data['tomorrow'] ?? []));
        } elseif (isset($data['data']) && is_array($data['data'])) {
            $list = $data['data'];
        } elseif (isset($data['prices']) && is_array($data['prices'])) {
            $list = $data['prices'];
        } else {
            $list = $data;
        }
        $rows = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $from = self::parseTime(self::first($row, ['from', 'start', 'startsAt', 'start_timestamp', 'begin']));
            $price = self::first($row, ['price', 'total', 'value', 'marketprice', 'preis']);
            if ($from === null || !is_numeric($price)) {
                continue;
            }
            $to = self::parseTime(self::first($row, ['to', 'end', 'endsAt', 'end_timestamp']));
            $rowUnit = strtolower((string)($row['unit'] ?? $declared));
            $rows[] = ['from' => $from, 'to' => $to, 'price' => (float)$price, 'unit' => $rowUnit];
        }
        usort($rows, function ($a, $b) {
            return $a['from'] <=> $b['from'];
        });
        $maxAbs = 0.0;
        foreach ($rows as $r) {
            $maxAbs = max($maxAbs, abs($r['price']));
        }
        $out = [];
        foreach ($rows as $i => $r) {
            $f = self::priceFactor($unit, $r['unit'], $maxAbs);
            $to = $r['to'] ?? ($rows[$i + 1]['from'] ?? $r['from'] + 3600);
            if ($to <= $r['from']) {
                $to = $r['from'] + 3600;
            }
            $out[] = ['from' => $r['from'], 'to' => $to, 'price' => round($r['price'] * $f, 3)];
            if (count($out) >= self::MAX_ENTRIES) {
                break;
            }
        }
        return $out;
    }

    /** Faktor nach ct/kWh. */
    private static function priceFactor(int $unit, string $declared, float $maxAbs): float
    {
        switch ($unit) {
            case self::UNIT_CT_KWH:
                return 1.0;
            case self::UNIT_EUR_KWH:
                return 100.0;
            case self::UNIT_EUR_MWH:
                return 0.1;
        }
        $d = str_replace([' ', '€', 'eur'], ['', 'e', 'e'], $declared);
        if ($d === 'e/mwh') {
            return 0.1;
        }
        if ($d === 'e/kwh') {
            return 100.0;
        }
        if ($d === 'ct/kwh' || $d === 'cent/kwh') {
            return 1.0;
        }
        // Ohne Angabe: Werte unter 2 sind €/kWh (Endkundenpreise liegen bei 0,1–0,6 €/kWh), sonst ct/kWh
        return $maxAbs < 2.0 ? 100.0 : 1.0;
    }

    /**
     * Beginn der $n günstigsten Preisabschnitte je Kalendertag im Bereich [$from, $to).
     *
     * @return int[] Zeitstempel (Beginn) der markierten Abschnitte
     */
    public static function cheapest(array $prices, int $from, int $to, int $n): array
    {
        if ($n <= 0) {
            return [];
        }
        $byDay = [];
        foreach ($prices as $p) {
            if ($p['to'] > $from && $p['from'] < $to) {
                $byDay[date('Y-m-d', $p['from'])][] = $p;
            }
        }
        $out = [];
        foreach ($byDay as $list) {
            usort($list, function ($a, $b) {
                return $a['price'] <=> $b['price'] ?: $a['from'] <=> $b['from'];
            });
            foreach (array_slice($list, 0, $n) as $p) {
                $out[] = $p['from'];
            }
        }
        sort($out);
        return $out;
    }

    /** Einträge, die den Bereich [$from, $to) berühren. */
    public static function clip(array $entries, int $from, int $to): array
    {
        return array_values(array_filter($entries, function ($e) use ($from, $to) {
            return $e['to'] > $from && $e['from'] < $to;
        }));
    }

    /** Werte einer Tagesprognose (beliebige Auflösung) auf 96 Viertelstunden wiederholen. */
    public static function toQuarters(array $values): array
    {
        $n = count($values);
        if ($n === 0) {
            return array_fill(0, 96, 0.0);
        }
        $per = max(1, intdiv(96, $n));
        $out = [];
        foreach (array_values($values) as $v) {
            for ($i = 0; $i < $per; $i++) {
                $out[] = round((float)$v);
            }
        }
        return array_slice(array_pad($out, 96, 0.0), 0, 96);
    }
}
