<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/symcon_stub.php';
require_once __DIR__ . '/../../Energiekachel/module.php';

/** Prognosen der Quellmodule, wie sie EPL_/EPV_/EPW_/EPB_GetForecast liefern würden. */
final class TileFixtures
{
    public static $calls = [];

    public static function forecast(string $kind, int $offset): array
    {
        self::$calls[] = $kind;
        $mean = [];
        for ($h = 0; $h < 24; $h++) {
            switch ($kind) {
                case 'pv':
                    $mean[] = max(0, 6000 * sin(($h - 6) / 12 * M_PI));
                    break;
                case 'wind':
                    $mean[] = 800 + 300 * sin($h / 4);
                    break;
                case 'bhkw':
                    $mean[] = ($h >= 6 && $h < 22) ? 4000 : 1500;
                    break;
                default:
                    $mean[] = 900 + ($h >= 7 && $h < 17 ? 1200 : 0);
            }
        }
        $fc = ['contractVersion' => '1.0', 'date' => date('Y-m-d', strtotime('today +' . $offset . ' days')), 'slots' => 24, 'resolution' => '60min',
               'mean' => $mean, 'p50' => $mean, 'p10' => array_map(function ($v) { return $v * 0.8; }, $mean),
               'p90' => array_map(function ($v) { return $v * 1.2; }, $mean), 'kwh' => array_sum($mean) / 1000];
        if ($kind === 'cons') {
            $fc['heat'] = array_fill(0, 24, 500);
            $fc['wallbox'] = array_fill(0, 24, 0);
            $fc['base'] = array_map(function ($v) { return $v - 500; }, $mean);
        }
        return $fc;
    }
}

if (!function_exists('EPL_GetForecast')) {
    function EPL_GetForecast(int $id, int $o): array { return TileFixtures::forecast('cons', $o); }
    function EPV_GetForecast(int $id, int $o): array { return TileFixtures::forecast('pv', $o); }
    function EPW_GetForecast(int $id, int $o): array { return TileFixtures::forecast('wind', $o); }
    function EPB_GetForecast(int $id, int $o): array { return TileFixtures::forecast('bhkw', $o); }
}

final class EnergiekachelTest extends TestCase
{
    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Berlin');
        FakeSymcon::reset(sys_get_temp_dir());
        TileFixtures::$calls = [];
        $root = __DIR__ . '/../..';
        foreach (['Verbrauchsprognose' => 4101, 'Solarprognose' => 4102, 'Windprognose' => 4103, 'BHKWPrognose' => 4104] as $folder => $iid) {
            $guid = json_decode(file_get_contents($root . '/' . $folder . '/module.json'), true)['id'];
            FakeSymcon::$instances[$guid] = [$iid];
            FakeSymcon::addVar($iid, $folder, 'value', null);
        }
        $t = strtotime('today');
        FakeSymcon::addVar(601, 'Fahrplan WP', 'value', null, '', 0, 1.0, json_encode([
            ['from' => $t + 11 * 3600, 'to' => $t + 13 * 3600, 'type' => 'Sperre', 'label' => 'EVU'],
            ['from' => $t - 86400, 'to' => $t - 80000, 'type' => 'request'],   // gestern: fällt raus
        ]));
        FakeSymcon::addVar(602, 'Tibber', 'value', null, '', 0, 1.0, json_encode(['today' => [
            ['total' => 0.30, 'startsAt' => date('c', $t)], ['total' => 0.20, 'startsAt' => date('c', $t + 3600)], ['total' => 0.25, 'startsAt' => date('c', $t + 7200)],
        ]]));
    }

    private function make(array $props = []): Energiekachel
    {
        $m = new Energiekachel(4200);
        $m->Create();
        foreach ($props as $k => $v) {
            $m->setProperty($k, $v);
        }
        $m->ApplyChanges();
        return $m;
    }

    public function testFindsSourcesAndBuildsPayload(): void
    {
        $m = $this->make(['ScheduleVars' => [['Name' => 'Wärmepumpe', 'VariableID' => 601]], 'PriceVar' => 602, 'CheapHours' => 1]);
        $this->assertSame(1, $m->visuType, 'HTML-SDK eingeschaltet');
        $this->assertSame(102, $m->status);
        $p = $m->GetPayload();
        $this->assertCount(3, $p['days']);
        $s = $p['days'][1]['series'];
        foreach (['cons', 'pv', 'wind', 'bhkw'] as $k) {
            $this->assertArrayHasKey($k, $s);
        }
        $this->assertCount(96, $s['pv']['mean']);
        $this->assertTrue($s['cons']['hasHeat']);
        $this->assertFalse($s['cons']['hasWallbox']);
        $this->assertSame(1600.0, (float)$s['cons']['base'][40], '10:00 Uhr: 2100 − 500 W Wärmepumpe');
        $this->assertCount(1, $p['schedules']);
        $this->assertSame('Wärmepumpe', $p['schedules'][0]['name']);
        $this->assertCount(1, $p['schedules'][0]['entries'], 'Eintrag von gestern abgeschnitten');
        $this->assertSame('lock', $p['schedules'][0]['entries'][0]['type']);
        $this->assertCount(3, $p['prices']);
        $this->assertEqualsWithDelta(20.0, $p['prices'][1]['price'], 1e-9);
        $this->assertSame([strtotime('today') + 3600], $p['cheap']);
        $this->assertSame('#F1C40F', $p['colors']['pv']);
        $this->assertArrayHasKey(601, $m->msgs, 'Fahrplan-Variable wird beobachtet');
        $this->assertArrayHasKey(602, $m->msgs);
    }

    public function testPushedSchedulesPricesAndPurchase(): void
    {
        $m = $this->make();
        $t = strtotime('today');
        $this->assertSame(2, $m->SetSchedule('wb', 'Wallbox', json_encode([
            ['from' => $t + 3600, 'to' => $t + 7200, 'type' => 'request', 'kw' => 11], ['from' => $t + 7200, 'to' => $t + 9000, 'type' => 'normal']])));
        $this->assertSame(1, $m->SetPrices(json_encode([['from' => $t, 'to' => $t + 3600, 'price' => 0.31]])));
        $this->assertSame(1, $m->SetPurchasePlan(json_encode([['from' => $t + 3600, 'to' => $t + 7200, 'kwh' => 5]])));
        $p = $m->GetPayload();
        $this->assertSame('wb', $p['schedules'][0]['id']);
        $this->assertEqualsWithDelta(31.0, $p['prices'][0]['price'], 1e-9);
        $this->assertSame('purchase', $p['purchase'][0]['type']);
        $this->assertGreaterThan(0, count($m->visuMessages), 'Kachel bekommt die Daten');
        $msg = json_decode(end($m->visuMessages), true);
        $this->assertSame('Wallbox', $msg['schedules'][0]['name']);
        $this->assertSame(0, $m->SetSchedule('wb', 'Wallbox', '[]'), 'leere Liste entfernt den Fahrplan');
        $this->assertCount(0, $m->GetPayload()['schedules']);
        $this->assertSame(-1, @$m->SetSchedule('x', 'X', 'kaputt'));
        $m->ClearPushed();
        $this->assertCount(0, $m->GetPayload()['prices']);
    }

    public function testDemoAndTileHtml(): void
    {
        $m = $this->make(['ShowBand' => true, 'CheapHours' => 3]);
        $this->assertStringContainsString('Beispieldaten', $m->LoadDemo());
        $p = $m->GetPayload();
        $this->assertCount(2, $p['schedules']);
        $this->assertCount(48, $p['prices']);
        $this->assertGreaterThan(0, count($p['purchase']));
        $html = $m->GetVisualizationTile();
        $this->assertStringContainsString('function handleMessage', $html);
        $this->assertStringNotContainsString('/*EPK_DATA*/null', $html);
        $this->assertStringContainsString('"band":true', $html);
        $this->assertCount(6, $m->GetPayload()['cheap'], '3 günstigste Stunden je Tag, heute und morgen');
        $this->assertSame(0, preg_match('#</script>.*</script>#s', substr($html, strpos($html, 'EPK_INIT'))), 'Daten brechen das Script nicht auf');
        file_put_contents(sys_get_temp_dir() . '/epk_tile.html', $html);
    }

    public function testWorksWithoutSources(): void
    {
        FakeSymcon::$instances = [];
        $m = $this->make();
        $this->assertSame(201, $m->status);
        $p = $m->GetPayload();
        $this->assertSame([], $p['days'][0]['series']);
        $m->RequestAction('Refresh', true);
        $this->assertGreaterThan(0, count($m->visuMessages));
    }
}
