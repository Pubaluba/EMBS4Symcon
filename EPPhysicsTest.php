<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../libs/EPSun.php';
require_once __DIR__ . '/../../libs/EPPV.php';
require_once __DIR__ . '/../../libs/EPWind.php';

final class EPPhysicsTest extends TestCase
{
    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Berlin');
    }

    public function testSunAtSummerSolsticeBerlin(): void
    {
        $best = [180.0, 0.0];
        for ($t = mktime(11, 0, 0, 6, 21, 2026); $t < mktime(15, 0, 0, 6, 21, 2026); $t += 60) {
            $p = EPSun::position($t, 52.52, 13.405);
            if ($p[0] < $best[0]) {
                $best = $p;
            }
        }
        $this->assertEqualsWithDelta(29.1, $best[0], 0.3, 'Zenit mittags');
        $this->assertEqualsWithDelta(180.0, $best[1], 1.5, 'Azimut mittags = Süd');
        [$zen, $az] = EPSun::position(mktime(7, 0, 0, 6, 21, 2026), 52.52, 13.405);
        $this->assertTrue($zen < 90 && $az > 45 && $az < 110, 'morgens im Osten');
        $this->assertGreaterThan(90.0, EPSun::position(mktime(0, 30, 0, 6, 21, 2026), 52.52, 13.405)[0]);
    }

    public function testIncidence(): void
    {
        $this->assertEqualsWithDelta(1.0, EPSun::cosIncidence(0, 180, 0, 180), 1e-9);
        $this->assertEqualsWithDelta(1.0, EPSun::cosIncidence(30, 180, 30, 180), 1e-9);
        $this->assertEqualsWithDelta(cos(deg2rad(60)), EPSun::cosIncidence(30, 180, 30, 0), 1e-9);
    }

    public function testPoaAndTemperatureLoss(): void
    {
        $poa = EPPV::poa(800, 100, 793, 30, 180, 30, 180);
        $exp = 800 + 100 * (1 + cos(deg2rad(30))) / 2 + 793 * 0.2 * (1 - cos(deg2rad(30))) / 2;
        $this->assertEqualsWithDelta($exp, $poa, 1e-6);
        $this->assertEqualsWithDelta(8843.75, EPPV::dcPower(10, 1000, 25, -0.37), 1e-6);
        $this->assertSame(0.0, EPPV::poa(800, 0, 0, 95, 180, 30, 180));
    }

    public function testHubSpeed(): void
    {
        $alpha = log(8 / 5) / log(8);
        $this->assertEqualsWithDelta(5 * 4 ** $alpha, EPWind::hubSpeed([10 => 5.0, 80 => 8.0], 40, 0.2), 1e-9);
        $this->assertEqualsWithDelta(5 * 0.5 ** 0.2, EPWind::hubSpeed([10 => 5.0, 80 => 8.0], 5, 0.2), 1e-9);
        $this->assertEqualsWithDelta(5 * 4 ** 0.14, EPWind::hubSpeed([10 => 5.0, 80 => 8.0], 40, 0.14, false), 1e-9);
        $this->assertEqualsWithDelta(5 * 4 ** 0.2, EPWind::hubSpeed([10 => 5.0, 80 => null], 40, 0.2), 1e-9);
        $this->assertNull(EPWind::hubSpeed([10 => null], 40, 0.2));
    }

    public function testAirDensityAndPower(): void
    {
        $this->assertEqualsWithDelta(1.225, EPWind::airDensity(1013.25, 15.0), 0.001);
        $cfg = ['ratedKw' => 5.0, 'area' => 20.0, 'cp' => 0.3, 'cutIn' => 3.0, 'cutOut' => 25.0, 'curve' => []];
        $this->assertEqualsWithDelta(1.8816, EPWind::power(8, $cfg, 1.225), 1e-4);
        $this->assertSame(5.0, EPWind::power(15, $cfg, 1.225));
        $this->assertSame(0.0, EPWind::power(2.5, $cfg, 1.225));
        $this->assertSame(0.0, EPWind::power(26, $cfg, 1.225));
        $this->assertGreaterThan(EPWind::power(6, $cfg, 1.225), EPWind::expectedPower(6, $cfg, 1.225, 0.16), 'Böigkeit erhöht den Ertrag im kubischen Bereich');
        $this->assertTrue(EPWind::expectedPower(20, $cfg, 1.225, 0.25) <= 5.0);
    }

    public function testPowerCurve(): void
    {
        $cfg = ['ratedKw' => 5.0, 'area' => 0.0, 'cp' => 0.3, 'cutIn' => 3.0, 'cutOut' => 25.0,
                'curve' => [[10, 4.0], [3, 0.1], [12, 5.0], [6, 1.0]]];
        $this->assertEqualsWithDelta(2.5, EPWind::power(8, $cfg, 1.225), 1e-9);
        $this->assertEqualsWithDelta(5.0, EPWind::power(14, $cfg, 1.225), 1e-9);
        $this->assertEqualsWithDelta(2.5 * 1.1 / 1.225, EPWind::power(8, $cfg, 1.1), 1e-9);
    }
}
