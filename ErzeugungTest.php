<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/symcon_stub.php';
require_once __DIR__ . '/../../Solarprognose/module.php';
require_once __DIR__ . '/../../Windprognose/module.php';
require_once __DIR__ . '/../../BHKWPrognose/module.php';

/** Nachgebildetes Open-Meteo: feste, nachvollziehbare Stundenwerte für die letzten 92 und die nächsten 3 Tage. */
final class GenWeather
{
    public const LAT = 49.45;
    public const LON = 11.08;
    private static $hourly = null;
    public static $fail = false;

    public static function tday(int $ts): float
    {
        $doy = (int)date('z', $ts);
        return 8.0 - 9.0 * cos(2 * M_PI * ($doy - 20) / 365.0) + 2.0 * sin((int)date('Ymd', $ts) * 1.3);
    }

    public static function hourly(): array
    {
        if (self::$hourly !== null) {
            return self::$hourly;
        }
        $out = [];
        for ($t = strtotime('today -92 days'); $t < strtotime('today +3 days'); $t += 3600) {
            $seed = (int)date('Ymd', $t);
            $c = 0.5 + 0.5 * sin($seed * 0.91);
            [$zen] = EPSun::position($t - 1800, self::LAT, self::LON);
            $s = max(0.0, sin(deg2rad(90 - $zen)));
            $dni = $s > 0 ? 850 * $s ** 0.25 * (1 - 0.75 * $c) : 0.0;
            $dhi = $s > 0 ? (60 + 120 * $c) * $s ** 0.6 : 0.0;
            $h = (int)date('G', $t);
            $v10 = max(0.0, 4.0 + 2.5 * sin(2 * M_PI * $h / 24) + 2.0 * sin($seed * 1.3));
            $out[$t] = [
                'shortwave_radiation' => round($dni * cos(deg2rad($zen)) * ($s > 0 ? 1 : 0) + $dhi, 1),
                'direct_normal_irradiance' => round($dni, 1), 'diffuse_radiation' => round($dhi, 1),
                'temperature_2m' => round(self::tday($t) + 4 * sin(2 * M_PI * ($h - 9) / 24), 1), 'cloud_cover' => round($c * 100),
                'wind_speed_10m' => round($v10, 2), 'wind_speed_80m' => round($v10 * 1.5, 2),
                'wind_speed_120m' => round($v10 * 1.6, 2), 'wind_speed_180m' => round($v10 * 1.7, 2), 'surface_pressure' => 990.0,
            ];
        }
        return self::$hourly = $out;
    }

    public static function json(string $url): string
    {
        if (self::$fail) {
            throw new RuntimeException('HTTP 503');
        }
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $vars = explode(',', $q['hourly']);
        $from = strtotime('today -' . (int)$q['past_days'] . ' days');
        $h = ['time' => []];
        foreach (self::hourly() as $t => $row) {
            if ($t < $from) {
                continue;
            }
            $h['time'][] = $t;
            foreach ($vars as $v) {
                $h[$v][] = $row[$v];
            }
        }
        return json_encode(['hourly' => $h]);
    }

    /** Modell-PV-Leistung (W) wie im Modul, für die nachgebildete Messung. */
    public static function pvW(int $t, array $gens): float
    {
        $hh = self::hourly();
        $dni = EPOpenMeteo::hourMean($hh, 'direct_normal_irradiance', $t);
        $dhi = EPOpenMeteo::hourMean($hh, 'diffuse_radiation', $t);
        $ghi = EPOpenMeteo::hourMean($hh, 'shortwave_radiation', $t);
        $tAir = EPOpenMeteo::interpolate($hh, 'temperature_2m', $t);
        [$zen, $az] = EPSun::position($t, self::LAT, self::LON);
        $w = 0.0;
        foreach ($gens as [$kwp, $tilt, $orient]) {
            $w += EPPV::dcPower($kwp, EPPV::poa($dni, $dhi, $ghi, $zen, $az, $tilt, $orient), $tAir, -0.37) * 0.9;
        }
        return $w;
    }

    public static function windW(int $t, array $cfg, float $hub): float
    {
        $hh = self::hourly();
        $lv = [];
        foreach ([10, 80, 120, 180] as $h) {
            $lv[$h] = EPOpenMeteo::interpolate($hh, 'wind_speed_' . $h . 'm', $t);
        }
        $v = EPWind::hubSpeed($lv, $hub, 0.20);
        $rho = EPWind::airDensity(EPOpenMeteo::interpolate($hh, 'surface_pressure', $t), EPOpenMeteo::interpolate($hh, 'temperature_2m', $t));
        return EPWind::expectedPower($v, $cfg, $rho, 0.16) * 1000.0;
    }

    public static function bhkwKwh(int $ts): float
    {
        return min(480.0, 60.0 + 25.0 * max(0.0, 15.0 - self::tday($ts)));
    }

    public static function bhkwW(int $t): float
    {
        $h = (int)date('G', $t);
        return self::bhkwKwh($t) / 24.0 * (($h >= 6 && $h < 22) ? 1.2 : 0.6) * 1000.0;
    }
}

final class TestSolar extends Solarprognose
{
    public $urls = [];

    protected function downloadToFile(string $url, string $file, int $timeout = 300): void
    {
        $this->urls[] = $url;
        file_put_contents($file, GenWeather::json($url));
    }
}

final class TestWind extends Windprognose
{
    protected function downloadToFile(string $url, string $file, int $timeout = 300): void
    {
        file_put_contents($file, GenWeather::json($url));
    }
}

final class TestBHKW extends BHKWPrognose
{
    protected function downloadToFile(string $url, string $file, int $timeout = 300): void
    {
        file_put_contents($file, GenWeather::json($url));
    }
}

final class ErzeugungTest extends TestCase
{
    private $dir;
    private const GENS = [[10.0, 30.0, 180.0], [5.0, 30.0, 90.0]];
    private const WIND = ['ratedKw' => 5.0, 'area' => 20.0, 'cp' => 0.3, 'cutIn' => 3.0, 'cutOut' => 25.0, 'curve' => []];

    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Berlin');
        $this->dir = sys_get_temp_dir() . '/ep_gen_' . uniqid();
        mkdir($this->dir);
        FakeSymcon::reset($this->dir);
        FakeSymcon::$profiles = ['P_kW' => 'kW', 'P_W' => 'W'];
        GenWeather::$fail = false;
        $gens = self::GENS;
        FakeSymcon::addVar(401, 'PV Erzeugung', 'power', function (int $t) use ($gens) {
            return 0.9 * GenWeather::pvW($t, $gens);
        }, 'P_W');
        FakeSymcon::addVar(402, 'Wind Erzeugung', 'power', function (int $t) {
            return 0.8 * GenWeather::windW($t, self::WIND, 25.0);
        }, 'P_W');
        FakeSymcon::addVar(501, 'BHKW elektrisch', 'power', [GenWeather::class, 'bhkwW'], 'P_kW', 0, 0.001);
        FakeSymcon::addVar(502, 'Außentemperatur', 'temp', [GenWeather::class, 'tday']);
        for ($o = 0; $o < 3; $o++) {
            FakeSymcon::addVar(511 + $o, 'Tagesmittel ' . $o, 'value', null, '', 0, 1.0, GenWeather::tday(strtotime('today +' . $o . ' days')));
        }
    }

    protected function tearDown(): void
    {
        $rm = function (string $d) use (&$rm) {
            foreach ((array)glob($d . '/*') as $f) {
                is_dir($f) ? $rm($f) : @unlink($f);
            }
            @rmdir($d);
        };
        $rm($this->dir);
    }

    private function make(string $class, int $id, array $props)
    {
        $m = new $class($id);
        $m->Create();
        foreach (array_merge(['Active' => true, 'Latitude' => GenWeather::LAT, 'Longitude' => GenWeather::LON], $props) as $k => $v) {
            $m->setProperty($k, $v);
        }
        $m->ApplyChanges();
        return $m;
    }

    private function assertBand(array $fc, string $label): void
    {
        foreach ($fc['p50'] as $i => $v) {
            $this->assertTrue($fc['p10'][$i] <= $v + 1 && $v <= $fc['p90'][$i] + 1, $label . ' Band ' . $i);
        }
    }

    private function truthKwh(callable $w, int $dayStart): float
    {
        $sum = 0.0;
        for ($t = $dayStart; $t < strtotime('tomorrow', $dayStart); $t += 900) {
            $sum += $w($t + 450) * 0.25 / 1000.0;
        }
        return $sum;
    }

    // ---------------------------------------------------------------- Solar

    private function solarGens(): array
    {
        return [['Name' => 'Süd', 'Kwp' => 10, 'Tilt' => 30, 'Azimuth' => 180], ['Name' => 'Ost', 'Kwp' => 5, 'Tilt' => 30, 'Azimuth' => 90]];
    }

    public function testSolarLearnsFactorFromMeasurement(): void
    {
        $m = $this->make(TestSolar::class, 6001, ['Generators' => $this->solarGens(), 'MeasVar' => 401, 'CalibDays' => 30]);
        $this->assertSame(102, $m->status);
        $status = $m->Rebuild();
        $this->assertStringContainsString('✅', $status);
        $this->assertStringContainsString('past_days=30', $m->urls[0]);
        $fc = $m->GetForecast(1);
        $this->assertEqualsWithDelta(0.9, $fc['factor'], 0.01, 'gelernter Faktor');
        $this->assertGreaterThan(20, $fc['calibrationDays']);
        $this->assertSame('Messung', $fc['bandSource']);
        $gens = self::GENS;
        $truth = 0.9 * $this->truthKwh(function ($t) use ($gens) {
            return GenWeather::pvW($t, $gens);
        }, strtotime('tomorrow'));
        $this->assertEqualsWithDelta($truth, $fc['kwh'], max(0.1, 0.02 * $truth), 'PV morgen');
        $this->assertCount(2, $fc['generators']);
        $this->assertEqualsWithDelta($fc['kwh'], $fc['generators'][0]['kwh'] + $fc['generators'][1]['kwh'], 0.05);
        $this->assertBand($fc, 'PV');
        $this->assertSame(0.0, (float)$fc['p50'][0], 'nachts 0');
    }

    public function testSolarWithoutMeasurementAndInverterLimit(): void
    {
        $m = $this->make(TestSolar::class, 6002, ['Generators' => $this->solarGens(), 'InverterKw' => 8.0]);
        $m->Rebuild();
        $fc = $m->GetForecast(1);
        $this->assertSame(1.0, (float)$fc['factor']);
        $this->assertSame('Bewölkung (Schätzung)', $fc['bandSource']);
        $this->assertTrue(max($fc['p50']) <= 8000 && max($fc['p90']) <= 8000, 'Wechselrichtergrenze');
        $this->assertStringContainsString('past_days=0', $m->urls[0]);
        $win = $m->GetEnergyWindow(strtotime('tomorrow'), strtotime('tomorrow +1 day'));
        $this->assertEqualsWithDelta($fc['kwh'], $win['kwh'], 0.05);
        $this->assertEqualsWithDelta(1.0, $win['coverage'], 1e-9);
    }

    public function testSolarWeatherFailureUsesCacheThenReports(): void
    {
        $m = $this->make(TestSolar::class, 6003, ['Generators' => $this->solarGens()]);
        $m->Rebuild();
        $kwh = $m->GetForecast(1)['kwh'];
        GenWeather::$fail = true;
        $meta = json_decode($m->attrs['WeatherMeta'], true);
        $meta['fetched'] = time() - 7200;
        $m->attrs['WeatherMeta'] = json_encode($meta);
        $s = $m->Rebuild();
        $this->assertStringContainsString('Vorhersage von vor 2', $s);
        $this->assertEqualsWithDelta($kwh, $m->GetForecast(1)['kwh'], 0.01);
        $meta['fetched'] = time() - 86400;
        $m->attrs['WeatherMeta'] = json_encode($meta);
        $this->assertStringContainsString('Keine Wetterdaten', $m->Rebuild());
        $this->assertEqualsWithDelta($kwh, $m->GetForecast(1)['kwh'], 0.01, 'alte Prognose bleibt stehen');
    }

    public function testSolarConfigProblems(): void
    {
        $this->assertSame(201, $this->make(TestSolar::class, 6004, [])->status);
        $this->assertSame(204, $this->make(TestSolar::class, 6005, ['Generators' => $this->solarGens(), 'Latitude' => 0.0, 'Longitude' => 0.0])->status);
        FakeSymcon::$vars[401]['logged'] = false;
        $this->assertSame(203, $this->make(TestSolar::class, 6006, ['Generators' => $this->solarGens(), 'MeasVar' => 401])->status);
    }

    // ---------------------------------------------------------------- Wind

    public function testWindLearnsFactor(): void
    {
        $m = $this->make(TestWind::class, 7001, ['RatedKw' => 5.0, 'RotorArea' => 20.0, 'HubHeight' => 25.0, 'Cp' => 0.3, 'Terrain' => 2, 'MeasVar' => 402]);
        $this->assertSame(102, $m->status);
        $this->assertStringContainsString('✅', $m->Rebuild());
        $fc = $m->GetForecast(1);
        $this->assertEqualsWithDelta(0.8, $fc['factor'], 0.01);
        $truth = 0.8 * $this->truthKwh(function ($t) {
            return GenWeather::windW($t, self::WIND, 25.0);
        }, strtotime('tomorrow'));
        $this->assertEqualsWithDelta($truth, $fc['kwh'], max(0.1, 0.02 * $truth), 'Wind morgen');
        $this->assertBand($fc, 'Wind');
        $this->assertTrue(max($fc['p90']) <= 5000);
        $this->assertCount(96, $fc['windHub']);
        $this->assertGreaterThan(0.0, $fc['fullLoadHours']);
        $this->assertEqualsWithDelta(1.8816, $m->GetPowerAt(8.0), 0.001);
        $form = json_encode(json_decode($m->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('8 m/s: 1,88 kW', $form);
    }

    public function testWindWithCurveAndTerrainProfile(): void
    {
        $m = $this->make(TestWind::class, 7002, ['RatedKw' => 5.0, 'RotorArea' => 0.0, 'HubHeight' => 12.0, 'ProfileSource' => 1, 'Terrain' => 4,
            'Curve' => [['Speed' => 3, 'Power' => 0.1], ['Speed' => 6, 'Power' => 1.0], ['Speed' => 10, 'Power' => 4.0], ['Speed' => 12, 'Power' => 5.0]]]);
        $this->assertSame(102, $m->status);
        $m->Rebuild();
        $fc = $m->GetForecast(0);
        $this->assertSame(1.0, (float)$fc['factor']);
        $this->assertEqualsWithDelta(2.5, $m->GetPowerAt(8.0), 1e-6);
        $this->assertGreaterThan(0.0, $fc['kwh']);
        $this->assertSame(201, $this->make(TestWind::class, 7003, ['RatedKw' => 5.0, 'RotorArea' => 0.0])->status);
    }

    // ---------------------------------------------------------------- BHKW

    public function testBhkwFollowsTemperature(): void
    {
        $m = $this->make(TestBHKW::class, 8001, ['MeasVar' => 501, 'TempVar' => 502, 'RatedKw' => 20.0, 'LookbackDays' => 200,
            'TempSource' => 1, 'TempFc0' => 511, 'TempFc1' => 512, 'TempFc2' => 513]);
        $this->assertSame(102, $m->status);
        for ($i = 0; $i < 5 && strpos($s = $m->Rebuild(), '⏳') !== false; $i++) {
        }
        $this->assertStringContainsString('✅', $s);
        for ($o = 0; $o < 3; $o++) {
            $ds = strtotime('today +' . $o . ' days');
            $fc = $m->GetForecast($o);
            $truth = GenWeather::bhkwKwh($ds);
            $this->assertEqualsWithDelta($truth, $fc['kwh'], 0.03 * $truth, 'BHKW Tag ' . $o);
            $this->assertTrue(max($fc['p90']) <= 20000 + 1, 'Nennleistung');
            $this->assertBand($fc, 'BHKW');
            $this->assertSame('Variable', $fc['temperatureSource']);
        }
        $this->assertEqualsWithDelta(60.0, $m->GetForecast(1)['coef'][0], 2.0);
        $this->assertEqualsWithDelta(25.0, $m->GetForecast(1)['coef'][1], 0.5);
    }

    public function testBhkwClosureAndOpenMeteo(): void
    {
        $tm = strtotime('tomorrow');
        $date = json_encode(['year' => (int)date('Y', $tm), 'month' => (int)date('n', $tm), 'day' => (int)date('j', $tm)]);
        $m = $this->make(TestBHKW::class, 8002, ['MeasVar' => 501, 'TempVar' => 502, 'LookbackDays' => 120, 'TempSource' => 0,
            'Closures' => [['From' => $date, 'To' => $date, 'Note' => 'Wartung']]]);
        $m->Rebuild();
        $fc = $m->GetForecast(1);
        $this->assertSame(0.0, (float)$fc['kwh']);
        $this->assertTrue($fc['closed']);
        $fc2 = $m->GetForecast(2);
        $this->assertSame('Open-Meteo', $fc2['temperatureSource']);
        $this->assertGreaterThan(50.0, $fc2['kwh']);
        $this->assertSame(205, $this->make(TestBHKW::class, 8003, ['MeasVar' => 501, 'TempVar' => 0])->status);
        $this->assertSame(201, $this->make(TestBHKW::class, 8004, [])->status);
    }

    public function testKernelGuardForAllModules(): void
    {
        FakeSymcon::$runlevel = 10102;
        foreach ([TestSolar::class, TestWind::class, TestBHKW::class] as $i => $c) {
            $m = $this->make($c, 9000 + $i, []);
            $this->assertSame(0, $m->timers['RebuildTimer']);
            $this->assertArrayHasKey(0, $m->msgs);
        }
    }
}
