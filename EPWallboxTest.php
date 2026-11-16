<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../libs/EPWallbox.php';

final class EPWallboxTest extends TestCase
{
    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Berlin');
    }

    public function testNeedKwh(): void
    {
        $this->assertEqualsWithDelta(53.333, EPWallbox::needKwh(60, 20, 100, 0.9), 0.001);
        $this->assertSame(0.0, EPWallbox::needKwh(60, 90, 80, 0.9));
        $this->assertSame(0.0, EPWallbox::needKwh(0, 20, 100, 0.9));
    }

    public function testSocNormalisation(): void
    {
        $this->assertSame(45.0, EPWallbox::normalizeSoc(0.45, true));
        $this->assertSame(100.0, EPWallbox::normalizeSoc(130, false));
    }

    public function testNextTime(): void
    {
        $now = mktime(6, 0, 0, 11, 16, 2026);
        $this->assertSame(mktime(7, 0, 0, 11, 16, 2026), EPWallbox::nextTime($now, '07:00'));
        $now = mktime(8, 0, 0, 11, 16, 2026);
        $this->assertSame(mktime(7, 0, 0, 11, 17, 2026), EPWallbox::nextTime($now, '07:00'));
        $this->assertNull(EPWallbox::nextTime($now, '25:00'));
    }

    public function testBlockAndOverlayEnergy(): void
    {
        $now = mktime(18, 0, 0, 6, 10, 2026);
        [$from, $to] = EPWallbox::block($now - 1800, $now, 11.0, 11.0);
        $this->assertSame($now - 1800, $from);
        $this->assertSame($now + 3600, $to);
        $day = mktime(0, 0, 0, 6, 10, 2026);
        $ov = EPWallbox::dayOverlay($day, strtotime('tomorrow', $day), $from, $to, 11000.0);
        $this->assertEqualsWithDelta(16.5, array_sum($ov) / 4 / 1000, 1e-6);
        $this->assertEqualsWithDelta(0.0, $ov[0], 1e-9);
        $this->assertEqualsWithDelta(11000.0, $ov[71], 1e-6);
    }

    public function testOverlayAcrossMidnight(): void
    {
        $day = mktime(0, 0, 0, 6, 10, 2026);
        $next = strtotime('tomorrow', $day);
        $ov1 = EPWallbox::dayOverlay($day, $next, $next - 3600, $next + 3600, 4000.0);
        $ov2 = EPWallbox::dayOverlay($next, strtotime('tomorrow', $next), $next - 3600, $next + 3600, 4000.0);
        $this->assertEqualsWithDelta(4.0, array_sum($ov1) / 4000, 1e-6);
        $this->assertEqualsWithDelta(4.0, array_sum($ov2) / 4000, 1e-6);
    }
}
