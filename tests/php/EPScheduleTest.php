<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../libs/EPSchedule.php';

final class EPScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Berlin');
    }

    public function testParseTime(): void
    {
        $this->assertSame(1794800000, EPSchedule::parseTime(1794800000));
        $this->assertSame(1794800000, EPSchedule::parseTime(1794800000000));
        $this->assertSame(1794800000, EPSchedule::parseTime('1794800000'));
        $this->assertSame(strtotime('2026-11-16T10:00:00+01:00'), EPSchedule::parseTime('2026-11-16T10:00:00.000+01:00'));
        $this->assertNull(EPSchedule::parseTime(''));
        $this->assertNull(EPSchedule::parseTime(null));
    }

    public function testScheduleNormalisation(): void
    {
        $t = strtotime('today 10:00');
        $s = EPSchedule::normalizeSchedule(json_encode(['id' => 'wp', 'name' => 'Wärmepumpe', 'entries' => [
            ['start' => $t + 7200, 'end' => $t + 9000, 'type' => 'Sperre', 'label' => 'EVU'],
            ['from' => $t, 'duration' => 3600, 'type' => 'Anforderung', 'w' => 3500],
            ['from' => $t, 'to' => $t - 10, 'type' => 'normal'],   // ungültig
            ['from' => $t + 3600, 'to' => $t + 7200],
        ]]), 'x', 'X');
        $this->assertSame('wp', $s['id']);
        $this->assertSame('Wärmepumpe', $s['name']);
        $this->assertCount(3, $s['entries']);
        $this->assertSame('request', $s['entries'][0]['type']);
        $this->assertSame(3.5, $s['entries'][0]['kw']);
        $this->assertSame('normal', $s['entries'][1]['type']);
        $this->assertSame('lock', $s['entries'][2]['type']);
        $this->assertSame('EVU', $s['entries'][2]['label']);
        $plain = EPSchedule::normalizeSchedule([['from' => $t, 'to' => $t + 60, 'type' => -1]], 'id1', 'Name 1');
        $this->assertSame('id1', $plain['id']);
        $this->assertSame('lock', $plain['entries'][0]['type']);
        $this->assertNull(EPSchedule::normalizeSchedule('kein json', 'a', 'b'));
    }

    public function testTibberPrices(): void
    {
        $d = '2026-11-16';
        $data = ['today' => [['total' => 0.2812, 'startsAt' => $d . 'T00:00:00.000+01:00'], ['total' => 0.2533, 'startsAt' => $d . 'T01:00:00.000+01:00']],
                 'tomorrow' => [['total' => 0.30, 'startsAt' => '2026-11-17T00:00:00.000+01:00']]];
        $p = EPSchedule::normalizePrices($data);
        $this->assertCount(3, $p);
        $this->assertEqualsWithDelta(28.12, $p[0]['price'], 1e-9);
        $this->assertSame($p[1]['from'], $p[0]['to'], 'Ende = nächster Beginn');
        $this->assertSame($p[2]['from'] + 3600, $p[2]['to'], 'letztes Ende = +1 h');
    }

    public function testAwattarAndFixedUnits(): void
    {
        $ms = strtotime('today') * 1000;
        $data = ['data' => [['start_timestamp' => $ms, 'end_timestamp' => $ms + 3600000, 'marketprice' => 95.5, 'unit' => 'Eur/MWh']]];
        $p = EPSchedule::normalizePrices($data);
        $this->assertEqualsWithDelta(9.55, $p[0]['price'], 1e-9);
        $list = [['from' => strtotime('today'), 'price' => 25.0]];
        $this->assertEqualsWithDelta(25.0, EPSchedule::normalizePrices($list)[0]['price'], 1e-9, 'Werte ab 2 gelten als ct/kWh');
        $this->assertEqualsWithDelta(2500.0, EPSchedule::normalizePrices($list, EPSchedule::UNIT_EUR_KWH)[0]['price'], 1e-9);
    }

    public function testCheapestPerDay(): void
    {
        $t0 = strtotime('today');
        $prices = [];
        foreach ([30, 20, 10, 25, 15] as $i => $v) {
            $prices[] = ['from' => $t0 + $i * 3600, 'to' => $t0 + ($i + 1) * 3600, 'price' => (float)$v];
        }
        $prices[] = ['from' => $t0 + 86400, 'to' => $t0 + 90000, 'price' => 50.0];
        $c = EPSchedule::cheapest($prices, $t0, $t0 + 2 * 86400, 2);
        $this->assertSame([$t0 + 2 * 3600, $t0 + 4 * 3600, $t0 + 86400], $c);
        $this->assertSame([], EPSchedule::cheapest($prices, $t0, $t0 + 86400, 0));
    }

    public function testToQuarters(): void
    {
        $q = EPSchedule::toQuarters(array_fill(0, 24, 1000));
        $this->assertCount(96, $q);
        $this->assertEqualsWithDelta(1000.0, $q[95], 1e-9);
        $this->assertCount(96, EPSchedule::toQuarters([]));
    }
}
