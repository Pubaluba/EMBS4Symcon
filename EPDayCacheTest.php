<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../libs/EPDayCache.php';

final class EPDayCacheTest extends TestCase
{
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/epdc_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach ((array)glob($this->dir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testRoundTripAndReload(): void
    {
        $file = $this->dir . '/v1.dat';
        $c = new EPDayCache($file);
        $p = [];
        for ($i = 0; $i < 96; $i++) {
            $p[] = $i * 10.4;
        }
        $c->put(20260610, $p);
        $c->put(20260611, null);
        $c2 = new EPDayCache($file);
        $this->assertSame(1, $c2->status(20260610));
        $this->assertSame(0, $c2->status(20260611));
        $this->assertNull($c2->status(20260612));
        $this->assertNull($c2->get(20260611));
        $g = $c2->get(20260610);
        $this->assertCount(96, $g);
        $this->assertEqualsWithDelta(988.0, $g[95], 1e-9);
        $this->assertEqualsWithDelta(array_sum($g) / 96, $c2->dayMeanW(20260610), 1e-9);
    }

    public function testLaterRecordWinsAndCompaction(): void
    {
        $file = $this->dir . '/v2.dat';
        $c = new EPDayCache($file);
        for ($i = 0; $i < 60; $i++) {
            $c->put(20260101 + $i, null);
        }
        for ($i = 0; $i < 60; $i++) {
            $c->put(20260101 + $i, array_fill(0, 96, 5.0));
        }
        $size = filesize($file);
        $c->compactIfNeeded();
        clearstatcache();
        $this->assertLessThan($size, filesize($file));
        $c2 = new EPDayCache($file);
        $this->assertSame(60, $c2->count());
        $this->assertSame(1, $c2->status(20260101));
        $this->assertCount(60, $c2->validDays());
    }

    public function testNegativeValuesSurvive(): void
    {
        $c = new EPDayCache($this->dir . '/v3.dat');
        $c->put(20260610, array_fill(0, 96, -1500.0));
        $this->assertEqualsWithDelta(-1500.0, (new EPDayCache($this->dir . '/v3.dat'))->get(20260610)[0], 1e-9);
    }
}
