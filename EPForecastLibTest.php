<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../libs/EPForecast.php';
require_once __DIR__ . '/../../libs/EPOpenMeteo.php';
require_once __DIR__ . '/../../libs/EPDegreeDay.php';

final class EPForecastLibTest extends TestCase
{
    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Berlin');
    }

    public function testOpenMeteoParsingAndTimeSemantics(): void
    {
        $url = EPOpenMeteo::url(49.45, 11.08, ['temperature_2m', 'shortwave_radiation'], 30, 3);
        $this->assertStringContainsString('past_days=30', $url);
        $this->assertStringContainsString('timeformat=unixtime', $url);
        $this->assertStringContainsString('wind_speed_unit=ms', $url);
        $h0 = mktime(10, 0, 0, 6, 10, 2026);
        $data = ['hourly' => ['time' => [$h0, $h0 + 3600], 'temperature_2m' => [10.0, 20.0], 'shortwave_radiation' => [300, 500]]];
        $hourly = EPOpenMeteo::parseHourly($data, ['temperature_2m', 'shortwave_radiation']);
        $this->assertEqualsWithDelta(15.0, EPOpenMeteo::interpolate($hourly, 'temperature_2m', $h0 + 1800), 1e-9);
        $this->assertEqualsWithDelta(500.0, EPOpenMeteo::hourMean($hourly, 'shortwave_radiation', $h0 + 900), 1e-9, 'Stundenmittel gilt für die vorangehende Stunde');
        $this->expectException(RuntimeException::class);
        EPOpenMeteo::parseHourly(['error' => true, 'reason' => 'kaputt'], ['x']);
    }

    public function testDailyMeansNeedFullDay(): void
    {
        $h = [];
        $d = mktime(0, 0, 0, 6, 10, 2026);
        for ($i = 0; $i < 24; $i++) {
            $h[$d + $i * 3600] = ['t' => (float)$i];
        }
        $h[$d + 86400] = ['t' => 99.0];
        $m = EPOpenMeteo::dailyMeans($h, 't');
        $this->assertEqualsWithDelta(11.5, $m['2026-06-10'], 1e-9);
        $this->assertFalse(isset($m['2026-06-11']));
    }

    public function testBuildAndWindow(): void
    {
        $q = array_fill(0, 96, 1000.0);
        $fc = EPForecast::build('2026-06-10', ['p10' => $q, 'p50' => $q, 'p90' => $q], 60, ['x' => 1]);
        $this->assertSame(24, $fc['slots']);
        $this->assertEqualsWithDelta(24.0, $fc['kwh'], 1e-9);
        $this->assertSame(1, $fc['x']);
        $w = EPForecast::window(function (int $o) use ($fc) {
            return $o === 0 ? $fc : null;
        }, 3, strtotime('today 10:00'), strtotime('today 12:30'));
        $this->assertEqualsWithDelta(2.5, $w['kwh'], 1e-6);
        $this->assertEqualsWithDelta(1.0, $w['coverage'], 1e-9);
    }

    public function testCapRedistributeKeepsEnergy(): void
    {
        $q = array_fill(0, 96, 0.0);
        for ($i = 40; $i < 56; $i++) {
            $q[$i] = 3000.0;
        }
        for ($i = 30; $i < 40; $i++) {
            $q[$i] = 500.0;
        }
        $c = EPForecast::capRedistribute($q, 2000.0);
        $this->assertEqualsWithDelta(array_sum($q), array_sum($c), 1e-6);
        $this->assertTrue(max($c) <= 2000.0 + 1e-9);
        $this->assertTrue($c[0] > 0.0, 'passt nicht in die Laufzeit: Anlage läuft länger');
        $c2 = EPForecast::capRedistribute(array_fill(0, 96, 3000.0), 2000.0);
        $this->assertEqualsWithDelta(96 * 2000.0, array_sum($c2), 1e-6, 'mehr als 24 h Volllast geht nicht');
        $c3 = EPForecast::capRedistribute([0.0, 1500.0, 2500.0, 1000.0], 2000.0);
        $this->assertSame(0.0, $c3[0], 'genug Platz bei laufenden Viertelstunden');
        $this->assertEqualsWithDelta(5000.0, array_sum($c3), 1e-6);
    }

    public function testCalibration(): void
    {
        $pairs = [];
        for ($i = 0; $i < 20; $i++) {
            $pairs[] = [9.0 + ($i % 3) * 0.1, 10.0];
        }
        $pairs[] = [0.1, 0.5];   // zu wenig Ertrag, wird ignoriert
        $c = EPForecast::calibration($pairs, 1.0, 7, 0.5, 1.5);
        $this->assertEqualsWithDelta(0.91, $c['factor'], 0.011);
        $this->assertSame(20, $c['days']);
        $this->assertTrue($c['low'] <= 1.0 && $c['high'] >= 1.0);
        $this->assertNull(EPForecast::calibration(array_slice($pairs, 0, 5), 1.0, 7, 0.5, 1.5));
    }

    public function testDegreeDayFit(): void
    {
        $days = [];
        for ($t = -5; $t <= 25; $t++) {
            $days[] = ['t' => (float)$t, 'kwh' => 60 + 25 * max(0, 15 - $t)];
        }
        $m = EPDegreeDay::fit($days, EPDegreeDay::HEAT, 15.0, 99.0, 14);
        $this->assertEqualsWithDelta(60.0, $m['coef'][0], 1e-6);
        $this->assertEqualsWithDelta(25.0, $m['coef'][1], 1e-6);
        $this->assertEqualsWithDelta(310.0, EPDegreeDay::predict($m, 5.0), 1e-6);
        $this->assertNull(EPDegreeDay::fit(array_slice($days, 0, 5), EPDegreeDay::HEAT, 15.0, 99.0, 14));
    }
}
