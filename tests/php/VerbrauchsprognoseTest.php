<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/symcon_stub.php';
require_once __DIR__ . '/../../Verbrauchsprognose/module.php';

/** Bekannter „wahrer“ Verbrauch, aus dem das nachgebildete Archiv seine Werte erzeugt. */
final class Truth
{
    public static function temp(int $t): float
    {
        $doy = (int)date('z', $t);
        return 10.0 - 9.0 * cos(2 * M_PI * ($doy - 20) / 365.0) + 1.5 * sin((int)date('Ymd', $t) * 1.7);
    }

    private static function h(int $t): int
    {
        return (int)date('G', $t);
    }

    public static function office(int $t): float
    {
        $h = self::h($t);
        switch (EPCalendar::dayType($t, '')) {
            case EPCalendar::WORKDAY:
                return ($h >= 7 && $h < 17) ? 600.0 : 150.0;
            case EPCalendar::SATURDAY:
                return ($h >= 8 && $h < 13) ? 250.0 : 150.0;
        }
        return 150.0;
    }

    public static function vent(int $t): float
    {
        $h = self::h($t);
        return ($h >= 6 && $h < 22) ? 200.0 : 100.0;
    }

    public static function rest(int $t): float
    {
        return 120.0 + (self::h($t) === 12 ? 400.0 : 0.0);
    }

    public static function heat(int $t): float
    {
        $h = self::h($t);
        return max(0.0, 15.0 - self::temp($t)) * 120.0 * (($h >= 6 && $h < 22) ? 1.3 : 0.4);
    }

    public static function wallbox(int $t): float
    {
        $h = self::h($t);
        return ((int)date('z', $t) % 3 === 0 && $h >= 18 && $h < 20) ? 11000.0 : 0.0;
    }

    public static function main(int $t): float
    {
        return self::office($t) + self::vent($t) + self::rest($t) + self::heat($t) + self::wallbox($t);
    }

    /** Energie (kWh) eines Tages für eine der Funktionen. */
    public static function kwh(string $fn, int $dayStart): float
    {
        $sum = 0.0;
        for ($t = $dayStart; $t < strtotime('tomorrow', $dayStart); $t += 900) {
            $sum += self::$fn($t + 450) * 0.25 / 1000.0;
        }
        return $sum;
    }
}

/** Testfassung: Wetterabruf ohne Netz (liefert eine feste Open-Meteo-Antwort). */
final class TestVerbrauchsprognose extends Verbrauchsprognose
{
    public $lastUrl = '';

    protected function downloadToFile(string $url, string $file, int $timeout = 300): void
    {
        $this->lastUrl = $url;
        $days = [];
        $means = [];
        for ($o = 0; $o < 3; $o++) {
            $days[] = date('Y-m-d', strtotime('today +' . $o . ' days'));
            $means[] = round(Truth::temp(strtotime('today +' . $o . ' days')), 1);
        }
        file_put_contents($file, json_encode(['daily' => ['time' => $days, 'temperature_2m_mean' => $means]]));
    }
}

final class VerbrauchsprognoseTest extends TestCase
{
    private $dir;

    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Berlin');
        $this->dir = sys_get_temp_dir() . '/ep_kernel_' . uniqid();
        mkdir($this->dir);
        FakeSymcon::reset($this->dir);
        FakeSymcon::$profiles = ['P_kW' => 'kW', 'P_W' => 'W', 'P_kWh' => 'kWh'];
        FakeSymcon::addVar(101, 'Außentemperatur', 'temp', [Truth::class, 'temp']);
        FakeSymcon::addVar(201, 'Hauptzähler', 'power', [Truth::class, 'main'], 'P_W');
        FakeSymcon::addVar(202, 'Wärmepumpe', 'power', [Truth::class, 'heat'], 'P_kW', 0, 0.001);
        FakeSymcon::addVar(203, 'Wallbox Leistung', 'power', [Truth::class, 'wallbox']);
        FakeSymcon::addVar(301, 'Büro', 'power', [Truth::class, 'office'], 'P_W');
        FakeSymcon::addVar(302, 'Lüftung 15-min-kWh', 'interval', [Truth::class, 'vent'], 'P_kWh');
        FakeSymcon::addVar(303, 'Rest Zählerstand', 'counter', [Truth::class, 'rest'], 'P_kWh');
        for ($o = 0; $o < 3; $o++) {
            FakeSymcon::addVar(111 + $o, 'Tagesmittel ' . $o, 'value', null, '', 0, 1.0, Truth::temp(strtotime('today +' . $o . ' days')));
        }
        FakeSymcon::addVar(121, 'SoC Auto 2', 'value', null, '', 0, 1.0, 0.5);
        FakeSymcon::addVar(122, 'Auto 2 angesteckt', 'value', null, '', 0, 1.0, true);
        FakeSymcon::addVar(123, 'Auto 3 angesteckt', 'value', null, '', 0, 1.0, false);
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

    private function module(int $id, array $props, string $class = Verbrauchsprognose::class): Verbrauchsprognose
    {
        $m = new $class($id);
        $m->Create();
        $base = ['Active' => true, 'LookbackDays' => 120, 'TempVar' => 101, 'TempSource' => 1, 'TempFc0' => 111, 'TempFc1' => 112, 'TempFc2' => 113,
                 'HeatDevices' => [['Name' => 'WP', 'VariableID' => 202, 'Type' => 0, 'Unit' => 0, 'Mode' => 0]],
                 'Wallboxes' => [
                     ['Name' => 'Carport', 'Mode' => 1, 'PowerVar' => 203, 'Type' => 0, 'Unit' => 0, 'SocVar' => 0, 'PlugVar' => 0,
                      'CapacityKwh' => 60, 'StartSoc' => 20, 'TargetSoc' => 80, 'ChargeKw' => 11, 'ReadyAt' => '07:00'],
                     ['Name' => 'Auto 2', 'Mode' => 0, 'PowerVar' => 0, 'Type' => 0, 'Unit' => 0, 'SocVar' => 121, 'PlugVar' => 122,
                      'CapacityKwh' => 60, 'StartSoc' => 20, 'TargetSoc' => 100, 'ChargeKw' => 11, 'ReadyAt' => '07:00'],
                     ['Name' => 'Auto 3', 'Mode' => 1, 'PowerVar' => 0, 'Type' => 0, 'Unit' => 0, 'SocVar' => 0, 'PlugVar' => 123,
                      'CapacityKwh' => 40, 'StartSoc' => 30, 'TargetSoc' => 80, 'ChargeKw' => 7.4, 'ReadyAt' => '07:00'],
                 ]];
        foreach (array_merge($base, $props) as $k => $v) {
            $m->setProperty($k, $v);
        }
        $m->ApplyChanges();
        return $m;
    }

    private function rebuildUntilDone(Verbrauchsprognose $m): string
    {
        for ($i = 0; $i < 10; $i++) {
            $s = $m->Rebuild();
            if (strpos($s, '⏳') === false) {
                return $s;
            }
        }
        return $s;
    }

    private function assertForecastPlausible(array $fc, int $dayStart, string $label): void
    {
        $this->assertSame(date('Y-m-d', $dayStart), $fc['date'], $label);
        $this->assertSame(96, $fc['slots']);
        $truthBase = Truth::kwh('office', $dayStart) + Truth::kwh('vent', $dayStart) + Truth::kwh('rest', $dayStart);
        $this->assertEqualsWithDelta($truthBase, $fc['kwhBase'], 0.03 * $truthBase, $label . ' Grundlast');
        $truthHeat = Truth::kwh('heat', $dayStart);
        $this->assertEqualsWithDelta($truthHeat, $fc['kwhHeat'], max(0.3, 0.05 * $truthHeat), $label . ' Wärmepumpe');
        for ($q = 0; $q < 96; $q++) {
            $this->assertTrue($fc['p10'][$q] <= $fc['p50'][$q] + 1 && $fc['p50'][$q] <= $fc['p90'][$q] + 1, $label . ' Band ' . $q);
        }
        $this->assertEqualsWithDelta($fc['kwhBase'] + $fc['kwhHeat'] + $fc['kwhWallbox'], $fc['kwh'], 0.05, $label . ' Summe');
    }

    public function testMainMeterMinusControlledLoads(): void
    {
        $m = $this->module(5001, ['Source' => 0, 'MainVar' => 201]);
        $this->assertSame(102, $m->status);
        $status = $this->rebuildUntilDone($m);
        $this->assertStringContainsString('✅', $status);
        for ($o = 0; $o < 3; $o++) {
            $this->assertForecastPlausible($m->GetForecast($o), strtotime('today +' . $o . ' days'), 'Hauptzähler Tag ' . $o);
        }
        $fc = $m->GetForecast(1);
        $this->assertSame('Variable', $fc['temperatureSource']);
        $this->assertSame(0.0, (float)$fc['kwhWallbox']);
    }

    public function testSumOfConsumersWithEnergyInputs(): void
    {
        $m = $this->module(5002, ['Source' => 1, 'Consumers' => [
            ['Name' => 'Büro', 'VariableID' => 301, 'Type' => 0, 'Unit' => 0],
            ['Name' => 'Lüftung', 'VariableID' => 302, 'Type' => 1, 'Unit' => 0],
            ['Name' => 'Rest', 'VariableID' => 303, 'Type' => 2, 'Unit' => 0],
        ]]);
        $this->rebuildUntilDone($m);
        for ($o = 0; $o < 3; $o++) {
            $this->assertForecastPlausible($m->GetForecast($o), strtotime('today +' . $o . ' days'), 'Verbraucherliste Tag ' . $o);
        }
    }

    public function testCounterWithCounterAggregation(): void
    {
        FakeSymcon::$vars[303]['aggType'] = 1;
        $m = $this->module(5003, ['Source' => 1, 'HeatDevices' => [], 'Consumers' => [
            ['Name' => 'Rest', 'VariableID' => 303, 'Type' => 2, 'Unit' => 2],
        ]]);
        $this->rebuildUntilDone($m);
        $fc = $m->GetForecast(1);
        $this->assertEqualsWithDelta(Truth::kwh('rest', strtotime('tomorrow')), $fc['kwhBase'], 0.05);
        $this->assertSame(0.0, (float)$fc['kwhHeat']);
    }

    public function testCacheIsReusedOnNextRun(): void
    {
        $m = $this->module(5004, ['Source' => 0, 'MainVar' => 201]);
        $this->rebuildUntilDone($m);
        FakeSymcon::$calls = 0;
        $m2 = $this->module(5004, ['Source' => 0, 'MainVar' => 201]);
        $m2->Rebuild();
        $this->assertLessThan(10, FakeSymcon::$calls, 'Archivaufrufe beim zweiten Lauf');
        $m2->ClearCache();
        FakeSymcon::$calls = 0;
        $m3 = $this->module(5004, ['Source' => 0, 'MainVar' => 201]);
        $m3->Rebuild();
        $this->assertGreaterThan(100, FakeSymcon::$calls, 'nach dem Leeren wird neu gelesen');
    }

    public function testWallboxChargeNowAndDemand(): void
    {
        $m = $this->module(5005, ['Source' => 0, 'MainVar' => 201]);
        $this->rebuildUntilDone($m);
        $msg = $m->ChargeNow(1);
        $this->assertStringContainsString('40.0 kWh', $msg);
        $sum = (float)$m->GetForecast(0)['kwhWallbox'] + (float)$m->GetForecast(1)['kwhWallbox'];
        $this->assertEqualsWithDelta(40.0, $sum, 0.4, 'Ladeblock heute + morgen');
        $win = $m->GetEnergyWindow(time(), time() + 6 * 3600);
        $this->assertGreaterThan(39.0, $win['kwhWallbox']);
        $this->assertEqualsWithDelta(1.0, $win['coverage'], 0.001);

        $demand = $m->GetWallboxDemand();
        $this->assertSame('angenommen', $demand[0]['socSource']);
        $this->assertTrue($demand[0]['sessionActive']);
        $this->assertSame('Variable', $demand[1]['socSource']);
        $this->assertEqualsWithDelta(50.0, $demand[1]['soc'], 0.01);
        $this->assertEqualsWithDelta(33.33, $demand[1]['needKwh'], 0.01);
        $this->assertNotNull($demand[1]['latestStart']);
        $this->assertEqualsWithDelta(33.33, (float)$m->values['WallboxDemand'], 0.01);

        $m->StopCharge(1);
        $this->assertSame(0.0, (float)$m->GetForecast(0)['kwhWallbox']);
        $this->assertStringContainsString('gibt es nicht', $m->ChargeNow(9));
    }

    public function testPlugInStartsImmediateCharging(): void
    {
        $m = $this->module(5006, ['Source' => 0, 'MainVar' => 201]);
        $this->assertArrayHasKey(123, $m->msgs);
        $this->rebuildUntilDone($m);
        $m->MessageSink(time(), 123, VM_UPDATE, []);   // Wert unverändert (false): nichts passiert
        $this->assertFalse($m->GetWallboxDemand()[2]['sessionActive']);
        FakeSymcon::$vars[123]['value'] = true;
        $m->MessageSink(time(), 123, VM_UPDATE, []);
        $d = $m->GetWallboxDemand();
        $this->assertTrue($d[2]['sessionActive']);
        $this->assertGreaterThan(0, $m->timers['WallboxTimer']);
        $m->MessageSink(time(), 123, VM_UPDATE, []);   // erneute Aktualisierung ohne Wechsel startet nicht neu
        $this->assertTrue($m->GetWallboxDemand()[2]['sessionActive']);
        FakeSymcon::$vars[123]['value'] = false;
        $m->MessageSink(time(), 123, VM_UPDATE, []);
        $this->assertFalse($m->GetWallboxDemand()[2]['sessionActive']);
    }

    public function testOpenMeteoAndForm(): void
    {
        $m = $this->module(5007, ['Source' => 0, 'MainVar' => 201, 'TempSource' => 0, 'Latitude' => 49.4521, 'Longitude' => 11.0767],
            TestVerbrauchsprognose::class);
        $this->rebuildUntilDone($m);
        $this->assertStringContainsString('latitude=49.4521&longitude=11.0767', $m->lastUrl);
        $fc = $m->GetForecast(2);
        $this->assertSame('Open-Meteo', $fc['temperatureSource']);
        $this->assertEqualsWithDelta(Truth::temp(strtotime('today +2 days')), $fc['temperature'], 0.06);
        $form = json_decode($m->GetConfigurationForm(), true);
        $this->assertTrue(is_array($form));
        $txt = json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Hauptzähler „Hauptzähler“ (#201): Leistung in W (Profil)', $txt);
        $this->assertStringContainsString('Wärmepumpe/Klima „WP“ (#202): Leistung in kW (Profil)', $txt);
    }

    public function testUnitMismatchAndMissingSource(): void
    {
        $m = $this->module(5008, ['Source' => 1, 'Consumers' => [['Name' => 'Falsch', 'VariableID' => 303, 'Type' => 0, 'Unit' => 0]]]);
        $txt = json_encode(json_decode($m->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('passt nicht zur Messart', $txt);
        $m2 = $this->module(5009, ['Source' => 1, 'Consumers' => []]);
        $this->assertSame(201, $m2->status);
        $this->assertStringContainsString('⛔', $m2->Rebuild());
    }

    public function testKernelNotReadyWaitsForStart(): void
    {
        FakeSymcon::$runlevel = 10102;
        $m = $this->module(5011, ['Source' => 0, 'MainVar' => 201]);
        $this->assertSame(0, $m->timers['RebuildTimer']);
        $this->assertArrayHasKey(0, $m->msgs);
        FakeSymcon::$runlevel = 10103;
        $m->MessageSink(time(), 0, IPS_KERNELSTARTED, []);
        $this->assertSame(5000, $m->timers['RebuildTimer']);
        $this->assertSame(102, $m->status);
    }

    public function testArchivingLaterSwitchedOnRecovers(): void
    {
        FakeSymcon::$vars[201]['logged'] = false;
        $m = $this->module(5012, ['Source' => 0, 'MainVar' => 201]);
        $this->assertSame(203, $m->status);
        $this->assertSame(5000, $m->timers['RebuildTimer'], 'Timer läuft weiter');
        FakeSymcon::$vars[201]['logged'] = true;
        $this->assertStringContainsString('✅', $this->rebuildUntilDone($m));
        $this->assertSame(102, $m->status);
    }

    public function testLongHistoryWithinBudgetAndMemory(): void
    {
        $m = $this->module(5010, ['Source' => 1, 'LookbackDays' => 1095, 'Consumers' => [
            ['Name' => 'Büro', 'VariableID' => 301, 'Type' => 0, 'Unit' => 0],
            ['Name' => 'Lüftung', 'VariableID' => 302, 'Type' => 1, 'Unit' => 0],
            ['Name' => 'Rest', 'VariableID' => 303, 'Type' => 2, 'Unit' => 0],
        ]]);
        $t0 = microtime(true);
        $first = $m->Rebuild();
        $this->assertLessThan(25.0, microtime(true) - $t0, 'ein Lauf bleibt im Zeitbudget');
        $this->rebuildUntilDone($m);
        $t1 = microtime(true);
        $m->Rebuild();
        $this->assertLessThan(10.0, microtime(true) - $t1, 'Lauf mit gefülltem Speicher');
        $this->assertForecastPlausible($m->GetForecast(1), strtotime('tomorrow'), '3 Jahre');
        fwrite(STDERR, "\n  [3 Jahre] erster Lauf: " . substr($first, 0, 60) . '… | Lauf mit Speicher: ' . round(microtime(true) - $t1, 2) . " s\n");
    }
}
