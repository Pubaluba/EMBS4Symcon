<?php

/**
 * Mini-Testläufer für Umgebungen ohne PHPUnit (z. B. ohne Netzzugriff für composer).
 * Führt die PHPUnit-Tests unter tests/php mit einer kleinen Nachbildung von PHPUnit\Framework\TestCase aus.
 * In der CI läuft weiterhin das echte PHPUnit (composer install && vendor/bin/phpunit).
 *
 *     php .dev/run_php_tests.php            alle Tests
 *     php -d memory_limit=32M .dev/run_php_tests.php
 *     TEST_FILTER=Solar php .dev/run_php_tests.php   nur Dateien mit „Solar“ im Namen
 */

namespace PHPUnit\Framework {
    if (!class_exists(TestCase::class)) {
        class AssertionFailed extends \Exception
        {
        }

        abstract class TestCase
        {
            private $expected = null;

            protected function setUp(): void
            {
            }

            protected function tearDown(): void
            {
            }

            public function runTest(string $method): void
            {
                $this->expected = null;
                $this->setUp();
                try {
                    $this->$method();
                    if ($this->expected !== null) {
                        throw new AssertionFailed('Ausnahme ' . $this->expected . ' erwartet');
                    }
                } catch (AssertionFailed $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    if ($this->expected === null || !($e instanceof $this->expected)) {
                        throw $e;
                    }
                } finally {
                    $this->tearDown();
                }
            }

            public function expectException(string $class): void
            {
                $this->expected = $class;
            }

            private function fail(string $msg): void
            {
                throw new AssertionFailed($msg);
            }

            public function assertSame($expected, $actual, string $msg = ''): void
            {
                if ($expected !== $actual) {
                    $this->fail(trim($msg . ' erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true)));
                }
            }

            public function assertEquals($expected, $actual, string $msg = ''): void
            {
                if ($expected != $actual) {
                    $this->fail(trim($msg . ' erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true)));
                }
            }

            public function assertEqualsWithDelta($expected, $actual, float $delta, string $msg = ''): void
            {
                if (abs($expected - $actual) > $delta) {
                    $this->fail(trim($msg . " erwartet $expected ± $delta, erhalten $actual"));
                }
            }

            public function assertTrue($v, string $msg = ''): void
            {
                if ($v !== true) {
                    $this->fail(trim($msg . ' erwartet true'));
                }
            }

            public function assertFalse($v, string $msg = ''): void
            {
                if ($v !== false) {
                    $this->fail(trim($msg . ' erwartet false'));
                }
            }

            public function assertNull($v, string $msg = ''): void
            {
                if ($v !== null) {
                    $this->fail(trim($msg . ' erwartet null, erhalten ' . var_export($v, true)));
                }
            }

            public function assertNotNull($v, string $msg = ''): void
            {
                if ($v === null) {
                    $this->fail(trim($msg . ' erwartet nicht null'));
                }
            }

            public function assertCount(int $n, $v, string $msg = ''): void
            {
                if (count($v) !== $n) {
                    $this->fail(trim($msg . " erwartet $n Elemente, erhalten " . count($v)));
                }
            }

            public function assertGreaterThan($limit, $v, string $msg = ''): void
            {
                if (!($v > $limit)) {
                    $this->fail(trim($msg . " erwartet > $limit, erhalten $v"));
                }
            }

            public function assertLessThan($limit, $v, string $msg = ''): void
            {
                if (!($v < $limit)) {
                    $this->fail(trim($msg . " erwartet < $limit, erhalten $v"));
                }
            }

            public function assertStringContainsString(string $needle, string $hay, string $msg = ''): void
            {
                if (strpos($hay, $needle) === false) {
                    $this->fail(trim($msg . " erwartet Text mit '$needle', erhalten '$hay'"));
                }
            }

            public function assertArrayHasKey($key, array $arr, string $msg = ''): void
            {
                if (!array_key_exists($key, $arr)) {
                    $this->fail(trim($msg . " Schlüssel '$key' fehlt"));
                }
            }
        }
    }
}

namespace {
    $root = dirname(__DIR__);
    $files = glob($root . '/tests/php/*Test.php');
    $filter = getenv('TEST_FILTER');   // z. B. TEST_FILTER=Solar
    if (is_string($filter) && $filter !== '') {
        $files = array_values(array_filter($files, function ($f) use ($filter) {
            return stripos(basename($f), $filter) !== false;
        }));
    }
    $pass = 0;
    $failed = [];
    foreach ($files as $f) {
        $before = get_declared_classes();
        require_once $f;
        foreach (array_diff(get_declared_classes(), $before) as $cls) {
            if (!is_subclass_of($cls, \PHPUnit\Framework\TestCase::class)) {
                continue;
            }
            foreach (get_class_methods($cls) as $m) {
                if (strpos($m, 'test') !== 0) {
                    continue;
                }
                try {
                    (new $cls())->runTest($m);
                    $pass++;
                    echo '.';
                } catch (\Throwable $e) {
                    $failed[] = "$cls::$m – " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
                    echo 'F';
                }
            }
        }
    }
    echo "\n\n" . $pass . ' bestanden, ' . count($failed) . " fehlgeschlagen, Speicher max. " . round(memory_get_peak_usage(true) / 1048576, 1) . " MB\n";
    foreach ($failed as $l) {
        echo '  ✗ ' . $l . "\n";
    }
    exit(count($failed) > 0 ? 1 : 0);
}
