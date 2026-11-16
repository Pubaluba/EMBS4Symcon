<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../libs/EPCalendar.php';

final class EPCalendarTest extends TestCase
{
    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Berlin');
    }

    public function testEaster(): void
    {
        $this->assertSame([3, 31], EPCalendar::easter(2024));
        $this->assertSame([4, 20], EPCalendar::easter(2025));
        $this->assertSame([4, 5], EPCalendar::easter(2026));
        $this->assertSame([4, 23], EPCalendar::easter(2000));
        $this->assertSame([4, 25], EPCalendar::easter(2038));
    }

    public function testRegionalHolidays(): void
    {
        $this->assertSame('Buß- und Bettag', EPCalendar::holidayName(strtotime('2026-11-18'), 'SN'));
        $this->assertNull(EPCalendar::holidayName(strtotime('2026-11-18'), 'BY'));
        $this->assertSame('Reformationstag', EPCalendar::holidayName(strtotime('2026-10-31'), 'NI'));
        $this->assertNull(EPCalendar::holidayName(strtotime('2016-10-31'), 'NI'));
        $this->assertSame('Fronleichnam', EPCalendar::holidayName(strtotime('2026-06-04'), 'BW'));
        $this->assertSame('Pfingstmontag', EPCalendar::holidayName(strtotime('2026-05-25'), ''));
        $this->assertSame('Internationaler Frauentag', EPCalendar::holidayName(strtotime('2026-03-08'), 'MV'));
        $this->assertNull(EPCalendar::holidayName(strtotime('2022-03-08'), 'MV'));
    }

    public function testDayType(): void
    {
        $this->assertSame(EPCalendar::WORKDAY, EPCalendar::dayType(strtotime('2026-11-16'), ''));
        $this->assertSame(EPCalendar::SATURDAY, EPCalendar::dayType(strtotime('2026-11-21'), ''));
        $this->assertSame(EPCalendar::SUNDAY, EPCalendar::dayType(strtotime('2026-11-22'), ''));
        $this->assertSame(EPCalendar::SUNDAY, EPCalendar::dayType(strtotime('2026-12-25'), ''));
    }

    public function testDayLength(): void
    {
        $this->assertEqualsWithDelta(16.3, EPCalendar::dayLengthHours(strtotime('2026-06-21'), 51.0), 0.4);
        $this->assertEqualsWithDelta(7.7, EPCalendar::dayLengthHours(strtotime('2026-12-21'), 51.0), 0.4);
        $this->assertEqualsWithDelta(12.0, EPCalendar::dayLengthHours(strtotime('2026-03-20'), 51.0), 0.4);
    }
}
