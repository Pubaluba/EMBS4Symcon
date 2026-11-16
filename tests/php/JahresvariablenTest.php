<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/symcon_stub.php';
require_once __DIR__ . '/../../Jahresvariablen/module.php';
require_once __DIR__ . '/../../Jahresvariablen_Float/module.php';

final class JahresvariablenTest extends TestCase
{
    protected function setUp(): void
    {
        FakeSymcon::reset(sys_get_temp_dir());
    }

    private function quiet(callable $fn)
    {
        set_error_handler(function () {
            return true;
        });
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    public function testDefaultsOnlyOnFirstCreate(): void
    {
        foreach ([new Jahresvariablen(3001), new Jahresvariablen_Float(3002)] as $m) {
            $m->Create();
            $this->assertEquals(85, $m->values['Value_1']);
            $this->assertEquals(10, $m->values['Value_6']);
            $this->assertCount(12, $m->actions);
            $m->values['Value_1'] = 42;
            $m->Create();   // Neustart von Symcon
            $this->assertEquals(42, $m->values['Value_1'], get_class($m) . ': eigener Wert bleibt beim Neustart erhalten');
        }
    }

    public function testGetSetAndValidation(): void
    {
        $m = new Jahresvariablen(3003);
        $m->Create();
        $this->assertTrue($m->Set(3, 55));
        $this->assertSame(55, $m->Get(3));
        $this->assertNull($this->quiet(function () use ($m) {
            return $m->Get(13);
        }));
        $this->assertFalse($this->quiet(function () use ($m) {
            return $m->Set(0, 1);
        }));
        $this->assertTrue($m->Setbyident('Value_12', 99));
        $this->assertSame(99, $m->Get(12));
        $this->assertFalse($this->quiet(function () use ($m) {
            return $m->Setbyident('Status', 1);
        }), 'nur Value_1 bis Value_12');

        $f = new Jahresvariablen_Float(3004);
        $f->Create();
        $this->assertTrue($f->SET(2, 12.5));
        $this->assertSame(12.5, $f->GET(2));
        $this->assertNull($this->quiet(function () use ($f) {
            return $f->GET(0);
        }));
    }

    public function testRequestAction(): void
    {
        $m = new Jahresvariablen(3005);
        $m->Create();
        $m->RequestAction('Value_4', '33');
        $this->assertSame(33, $m->Get(4));
        $f = new Jahresvariablen_Float(3006);
        $f->Create();
        $f->RequestAction('Value_7', 7.25);
        $this->assertSame(7.25, $f->GET(7));
        $this->expectException(Exception::class);
        $m->RequestAction('Value_13', 1);
    }
}
