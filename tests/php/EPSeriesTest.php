<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../libs/EPSeries.php';

final class EPSeriesTest extends TestCase
{
    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Berlin');
    }

    private function day(string $date): array
    {
        $s = strtotime($date);
        return [$s, strtotime('tomorrow', $s)];
    }

    public function testWallQuarter(): void
    {
        $this->assertSame(55, EPSeries::wallQuarter(mktime(13, 47, 0, 6, 10, 2026)));
        $this->assertSame(0, EPSeries::wallQuarter(mktime(0, 0, 0, 6, 10, 2026)));
        $this->assertSame(95, EPSeries::wallQuarter(mktime(23, 59, 59, 6, 10, 2026)));
    }

    public function testAggregatedPowerWithUnitFactor(): void
    {
        [$s, $e] = $this->day('2026-06-10');
        $rows = [];
        foreach (EPSeries::quarterStarts($s, $e) as $i => $t) {
            $rows[] = ['TimeStamp' => $t, 'Avg' => $i / 10.0, 'Duration' => 900];   // kW
        }
        $p = EPSeries::toWallProfile(EPSeries::fromAggregatedPower(array_reverse($rows), $s, $e, 1000.0));
        $this->assertCount(96, $p);
        $this->assertEqualsWithDelta(0.0, $p[0], 1e-9);
        $this->assertEqualsWithDelta(9500.0, $p[95], 1e-6);
    }

    public function testOctoberChangeoverAveragesDoubleHour(): void
    {
        [$s, $e] = $this->day('2026-10-25');
        $starts = EPSeries::quarterStarts($s, $e);
        $this->assertCount(100, $starts);
        $rows = [];
        $seen = [];
        foreach ($starts as $t) {
            $q = EPSeries::wallQuarter($t);
            $second = isset($seen[$q]);
            $seen[$q] = true;
            $rows[] = ['TimeStamp' => $t, 'Avg' => $second ? 3000.0 : 1000.0];
        }
        $p = EPSeries::toWallProfile(EPSeries::fromAggregatedPower($rows, $s, $e, 1.0));
        $this->assertEqualsWithDelta(2000.0, $p[9], 1e-6);
        $this->assertEqualsWithDelta(1000.0, $p[20], 1e-6);
    }

    public function testMarchChangeoverFillsMissingHour(): void
    {
        [$s, $e] = $this->day('2026-03-29');
        $starts = EPSeries::quarterStarts($s, $e);
        $this->assertCount(92, $starts);
        $rows = [];
        foreach ($starts as $t) {
            $rows[] = ['TimeStamp' => $t, 'Avg' => (float)EPSeries::wallQuarter($t)];
        }
        $p = EPSeries::toWallProfile(EPSeries::fromAggregatedPower($rows, $s, $e, 1.0));
        $this->assertEqualsWithDelta(7.0, $p[8], 1e-9);
        $this->assertEqualsWithDelta(7.0, $p[11], 1e-9);
        $this->assertEqualsWithDelta(12.0, $p[12], 1e-9);
    }

    public function testCoverageBelowHalfIsNull(): void
    {
        [$s, $e] = $this->day('2026-06-10');
        $rows = [];
        foreach (array_slice(EPSeries::quarterStarts($s, $e), 0, 40) as $t) {
            $rows[] = ['TimeStamp' => $t, 'Avg' => 500.0];
        }
        $this->assertNull(EPSeries::toWallProfile(EPSeries::fromAggregatedPower($rows, $s, $e, 1.0)));
    }

    public function testAggregatedCounter(): void
    {
        [$s, $e] = $this->day('2026-06-10');
        $rows = [];
        foreach (EPSeries::quarterStarts($s, $e) as $t) {
            $rows[] = ['TimeStamp' => $t, 'Avg' => 0.5, 'Duration' => 900];   // 0,5 kWh je Viertelstunde = 2 kW
        }
        $p = EPSeries::toWallProfile(EPSeries::fromAggregatedCounter($rows, $s, $e, 1000.0));
        $this->assertEqualsWithDelta(2000.0, $p[40], 1e-6);
        $this->assertEqualsWithDelta(48.0, EPSeries::kwh($p), 1e-6);
    }

    public function testCounterReadingsEveryQuarter(): void
    {
        [$s, $e] = $this->day('2026-06-10');
        $rows = [];
        $m = 1000.0;
        foreach (EPSeries::quarterStarts($s, $e) as $t) {
            $m += 0.25;
            $rows[] = ['TimeStamp' => $t + 900, 'Value' => $m];
        }
        $p = EPSeries::toWallProfile(EPSeries::fromCounterReadings(array_reverse($rows), 1000.0, $s, $e, 1000.0));
        $this->assertEqualsWithDelta(1000.0, $p[0], 1e-6);
        $this->assertEqualsWithDelta(24.0, EPSeries::kwh($p), 1e-6);
    }

    public function testCounterResetIsUnknownNotNegative(): void
    {
        [$s, $e] = $this->day('2026-06-10');
        $rows = [];
        $m = 50.0;
        foreach (EPSeries::quarterStarts($s, $e) as $i => $t) {
            $m = $i === 40 ? 0.0 : $m + 0.25;   // Zählertausch
            $rows[] = ['TimeStamp' => $t + 900, 'Value' => $m];
        }
        $q = EPSeries::fromCounterReadings($rows, 50.0, $s, $e, 1000.0);
        $this->assertNull($q[$s + 40 * 900]);
        $p = EPSeries::toWallProfile($q);
        $this->assertEqualsWithDelta(1000.0, $p[40], 1e-6);   // aus der Nachbar-Viertelstunde gefüllt
    }

    public function testCounterSparseReadingsKeepDailyEnergy(): void
    {
        [$s, $e] = $this->day('2026-06-10');
        $rows = [];
        for ($h = 1; $h <= 24; $h++) {
            $rows[] = ['TimeStamp' => $s + $h * 3600, 'Value' => 10.0 + $h];   // stündlich +1 kWh
        }
        $p = EPSeries::toWallProfile(EPSeries::fromCounterReadings($rows, 10.0, $s, $e, 1000.0));
        $this->assertEqualsWithDelta(24.0, EPSeries::kwh($p), 1e-6);
    }

    public function testIntervalEnergyChangeOnlyLogging(): void
    {
        [$s, $e] = $this->day('2026-06-10');
        // 0,05 kWh je Viertelstunde bis 08:00, danach 0,1 kWh; das Archiv kennt nur die beiden Wertänderungen
        $prev = ['TimeStamp' => $s - 60, 'Value' => 0.05];
        $rows = [['TimeStamp' => $s + 8 * 3600 + 900, 'Value' => 0.1]];
        $p = EPSeries::toWallProfile(EPSeries::fromIntervalEnergy($rows, $prev, $s, $e, 15, true, 1000.0));
        $this->assertEqualsWithDelta(200.0, $p[31], 1e-6);
        $this->assertEqualsWithDelta(400.0, $p[32], 1e-6);
        $this->assertEqualsWithDelta(8 * 0.2 + 16 * 0.4, EPSeries::kwh($p), 1e-6);
    }

    public function testIntervalEnergyStampAtBegin(): void
    {
        [$s, $e] = $this->day('2026-06-10');
        $prev = ['TimeStamp' => $s - 900, 'Value' => 0.05];
        $rows = [['TimeStamp' => $s + 8 * 3600, 'Value' => 0.1]];
        $p = EPSeries::toWallProfile(EPSeries::fromIntervalEnergy($rows, $prev, $s, $e, 15, false, 1000.0));
        $this->assertEqualsWithDelta(200.0, $p[31], 1e-6);
        $this->assertEqualsWithDelta(400.0, $p[32], 1e-6);
    }

    public function testIntervalEnergyHourlyValues(): void
    {
        [$s, $e] = $this->day('2026-06-10');
        $rows = [];
        for ($h = 1; $h <= 25; $h++) {
            $rows[] = ['TimeStamp' => $s + $h * 3600, 'Value' => 1.0 + ($h % 2) * 0.000001];
        }
        $p = EPSeries::toWallProfile(EPSeries::fromIntervalEnergy($rows, null, $s, $e, 60, true, 1000.0));
        $this->assertEqualsWithDelta(1000.0, $p[50], 0.01);
    }

    public function testIntervalEnergyTooOldIsMissing(): void
    {
        [$s, $e] = $this->day('2026-06-10');
        $prev = ['TimeStamp' => $s - 3 * 86400, 'Value' => 0.05];
        $this->assertNull(EPSeries::toWallProfile(EPSeries::fromIntervalEnergy([], $prev, $s, $e, 15, true, 1000.0)));
    }

    public function testResampleAndKwh(): void
    {
        $q = [];
        for ($i = 0; $i < 96; $i++) {
            $q[] = (float)($i % 4) * 100.0;
        }
        $h = EPSeries::resample($q, 60);
        $this->assertCount(24, $h);
        $this->assertEqualsWithDelta(150.0, $h[3], 1e-9);
        $this->assertEqualsWithDelta(EPSeries::kwh($q), EPSeries::kwh($h, 60), 1e-9);
    }
}
