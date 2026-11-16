<?php

require_once __DIR__ . '/EPHelper.php';
require_once __DIR__ . '/EPSeries.php';
require_once __DIR__ . '/EPDayCache.php';
require_once __DIR__ . '/EPForecast.php';
require_once __DIR__ . '/EPOpenMeteo.php';

/**
 * Gemeinsame Teile der Erzeugungsprognosen (Solar, Wind, BHKW) für Klassen, die von IPSModuleStrict erben.
 *
 * Erwartete Properties der nutzenden Klasse: Active, IntervalHours, Resolution, Latitude, Longitude,
 * MeasVar, MeasType, MeasUnit, EnergyInterval, StampIsEnd.
 * Erwartete Attribute: WeatherMeta, LastInfo. Timer: RebuildTimer, MidnightTimer.
 * Variablen: FcToday/FcTomorrow/FcDayAfter (JSON), KwhToday/KwhTomorrow/KwhDayAfter, Status, LastRun.
 */
trait EPModuleCommon
{
    use EPHelper;

    /** @var string[] Hinweise des laufenden Laufs */
    private $notes = [];
    /** @var array<string, EPDayCache> */
    private $caches = [];

    private static function fcIdents(): array
    {
        return ['FcToday', 'FcTomorrow', 'FcDayAfter'];
    }

    private static function kwhIdents(): array
    {
        return ['KwhToday', 'KwhTomorrow', 'KwhDayAfter'];
    }

    // ---------------------------------------------------------------- Lebenszyklus

    /** Prognose-Variablen (Positionen 10–22) und Status anlegen. */
    private function registerForecastVariables(string $what): void
    {
        $names = ['heute', 'morgen', 'übermorgen'];
        foreach ($names as $i => $n) {
            $this->RegisterVariableString(self::fcIdents()[$i], $what . ' ' . $n . ' (JSON)', '', 10 + $i);
            $this->RegisterVariableFloat(self::kwhIdents()[$i], $what . ' ' . $n . ' (kWh)', '~Electricity', 20 + $i);
        }
        $this->RegisterVariableString('Status', 'Status', '', 50);
        $this->RegisterVariableInteger('LastRun', 'Letzte Berechnung', '~UnixTimestamp', 51);
    }

    /** true, wenn der Kernel bereit ist; sonst auf IPS_KERNELSTARTED warten (MessageSink ruft ApplyChanges erneut). */
    private function kernelReady(): bool
    {
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        return IPS_GetKernelRunlevel() === KR_READY;
    }

    /** Timer nach ApplyChanges setzen und Status melden (Timer laufen auch bei Problemen weiter, Rebuild prüft erneut). */
    private function startTimers(int $problem): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('RebuildTimer', 0);
            $this->SetTimerInterval('MidnightTimer', 0);
            $this->SetStatus(104);
            return;
        }
        $this->SetStatus($problem !== 0 ? $problem : 102);
        $this->SetTimerInterval('RebuildTimer', 5000);
        $this->SetTimerInterval('MidnightTimer', $this->msToMidnight());
    }

    private function resetTimersForRun(): void
    {
        $this->SetTimerInterval('RebuildTimer', max(1, $this->ReadPropertyInteger('IntervalHours')) * 3600000);
        $this->SetTimerInterval('MidnightTimer', $this->ReadPropertyBoolean('Active') ? $this->msToMidnight() : 0);
    }

    private function msToMidnight(): int
    {
        return max(60000, (strtotime('tomorrow') + 900 - time()) * 1000);
    }

    // ---------------------------------------------------------------- Archiv und Messwerte

    private function archiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return count($ids) > 0 ? (int)$ids[0] : 0;
    }

    private function isLogged(int $vid): bool
    {
        $aid = $this->archiveID();
        return $vid > 0 && $aid > 0 && IPS_VariableExists($vid) && (bool)@AC_GetLoggingStatus($aid, $vid);
    }

    /** Messvariable der Erzeugung aus den Properties oder null. */
    private function measSeries(): ?array
    {
        $vid = $this->ReadPropertyInteger('MeasVar');
        if ($vid <= 0) {
            return null;
        }
        $type = $this->ReadPropertyInteger('MeasType');
        return ['var' => $vid, 'type' => in_array($type, [0, 1, 2], true) ? $type : 0,
                'unit' => max(0, min(3, $this->ReadPropertyInteger('MeasUnit'))), 'name' => 'Messung'];
    }

    private function profileSuffix(int $vid): string
    {
        if (!IPS_VariableExists($vid)) {
            return '';
        }
        $v = IPS_GetVariable($vid);
        $p = (string)($v['VariableCustomProfile'] ?? '') !== '' ? (string)$v['VariableCustomProfile'] : (string)($v['VariableProfile'] ?? '');
        if ($p === '' || !IPS_VariableProfileExists($p)) {
            return '';
        }
        return trim((string)(IPS_GetVariableProfile($p)['Suffix'] ?? ''));
    }

    /** @return array{factor: float, label: string, source: string} Leistung → W bzw. Energie → Wh je Einheit */
    private function unitInfo(array $s): array
    {
        $power = $s['type'] === EPSeries::TYPE_POWER;
        $labels = $power ? [1 => 'W', 2 => 'kW', 3 => 'MW'] : [1 => 'Wh', 2 => 'kWh', 3 => 'MWh'];
        $factors = [1 => 1.0, 2 => 1000.0, 3 => 1000000.0];
        if ($s['unit'] > 0) {
            return ['factor' => $factors[$s['unit']], 'label' => $labels[$s['unit']], 'source' => 'manuell'];
        }
        $suffix = strtolower($this->profileSuffix($s['var']));
        foreach ($labels as $k => $l) {
            if ($suffix === strtolower($l)) {
                return ['factor' => $factors[$k], 'label' => $l, 'source' => 'Profil'];
            }
        }
        return $power ? ['factor' => 1.0, 'label' => 'W', 'source' => 'angenommen'] : ['factor' => 1000.0, 'label' => 'kWh', 'source' => 'angenommen'];
    }

    /** Infozeile zur Messvariable fürs Formular. */
    private function measInfo(): string
    {
        $s = $this->measSeries();
        if ($s === null) {
            return 'ℹ️ Keine Messung gewählt: ohne Messung keine Kalibrierung.';
        }
        if (!IPS_VariableExists($s['var'])) {
            return '⛔ Messvariable #' . $s['var'] . ' existiert nicht.';
        }
        $types = ['Leistung', 'Energie je Intervall', 'Zählerstand'];
        $u = $this->unitInfo($s);
        $line = ($this->isLogged($s['var']) ? '✅ ' : '⛔ ') . 'Messung #' . $s['var'] . ' „' . IPS_GetName($s['var']) . '“: '
            . $types[$s['type']] . ' in ' . $u['label'] . ' (' . $u['source'] . ')';
        if (!$this->isLogged($s['var'])) {
            $line .= ' – nicht archiviert';
        }
        $suffix = strtolower($this->profileSuffix($s['var']));
        $energy = in_array($suffix, ['wh', 'kwh', 'mwh'], true);
        $powerSuf = in_array($suffix, ['w', 'kw', 'mw'], true);
        if (($s['type'] === EPSeries::TYPE_POWER && $energy) || ($s['type'] !== EPSeries::TYPE_POWER && $powerSuf)) {
            $line .= ' – ⚠️ Profil-Einheit ' . $suffix . ' passt nicht zur Messart';
        } elseif ($u['source'] === 'angenommen') {
            $line .= ' – ⚠️ Einheit bitte prüfen bzw. selbst wählen';
        }
        return $line;
    }

    private function cacheFor(array $s): EPDayCache
    {
        $u = $this->unitInfo($s);
        $key = $s['var'] . '|' . $s['type'] . '|' . $u['factor'];
        if ($s['type'] === EPSeries::TYPE_INTERVAL) {
            $key .= '|' . $this->ReadPropertyInteger('EnergyInterval') . '|' . ($this->ReadPropertyBoolean('StampIsEnd') ? 'e' : 'b');
        }
        if (!isset($this->caches[$key])) {
            $dir = $this->runtimeDir('EnergiePrognose') . DIRECTORY_SEPARATOR . 'i' . $this->InstanceID;
            $this->caches[$key] = new EPDayCache($dir . DIRECTORY_SEPARATOR . 'v' . $s['var'] . '_' . substr(md5($key), 0, 10) . '.dat');
        }
        return $this->caches[$key];
    }

    /** Tagesspeicher der Instanz löschen. */
    private function clearCaches(): int
    {
        $n = 0;
        foreach ((array)glob($this->runtimeDir('EnergiePrognose') . DIRECTORY_SEPARATOR . 'i' . $this->InstanceID . DIRECTORY_SEPARATOR . '*.dat') as $f) {
            if (is_string($f) && @unlink($f)) {
                $n++;
            }
        }
        $this->caches = [];
        return $n;
    }

    /**
     * Fehlende Tage (jüngste zuerst) aus dem Archiv in den Tagesspeicher lesen, mit Zeitbudget.
     *
     * @return array{complete: bool, done: int, total: int}
     */
    private function fillCache(array $s, int $days, float $budgetSec = 15.0): array
    {
        $deadline = microtime(true) + $budgetSec;
        $cache = $this->cacheFor($s);
        $complete = true;
        for ($d = 1; $d <= $days; $d++) {
            $dayStart = strtotime('today -' . $d . ' days');
            $key = (int)date('Ymd', $dayStart);
            $st = $cache->status($key);
            if ($st === 1 || ($st === 0 && $d > 7)) {
                continue;
            }
            if (microtime(true) > $deadline) {
                $complete = false;
                break;
            }
            $cache->put($key, $this->readDay($s, $dayStart, strtotime('tomorrow', $dayStart)));
        }
        $cache->compactIfNeeded();
        $done = 0;
        for ($d = 1; $d <= $days; $d++) {
            $done += $cache->status((int)date('Ymd', strtotime('today -' . $d . ' days'))) !== null ? 1 : 0;
        }
        return ['complete' => $complete, 'done' => $done, 'total' => $days];
    }

    /** Gemessene Tagesenergie (kWh, Betrag) eines vergangenen Tages aus dem Tagesspeicher. */
    private function measuredKwh(array $s, int $dayStart): ?float
    {
        $p = $this->cacheFor($s)->get((int)date('Ymd', $dayStart));
        if ($p === null) {
            return null;
        }
        return abs(array_sum($p)) * 0.25 / 1000.0;
    }

    /** Ein Tag als 96 Viertelstunden in W (Messart beachtet), null bei zu wenig Daten. */
    private function readDay(array $s, int $dayStart, int $dayEnd): ?array
    {
        $aid = $this->archiveID();
        $u = $this->unitInfo($s);
        $vid = $s['var'];
        try {
            if ($s['type'] === EPSeries::TYPE_INTERVAL) {
                $iv = max(1, $this->ReadPropertyInteger('EnergyInterval')) * 60;
                $rows = $this->logged($aid, $vid, $dayStart, $dayEnd + $iv);
                $prev = @AC_GetLoggedValues($aid, $vid, 0, $dayStart - 1, 1);
                $q = EPSeries::fromIntervalEnergy($rows, is_array($prev) && count($prev) > 0 ? $prev[0] : null,
                    $dayStart, $dayEnd, intdiv($iv, 60), $this->ReadPropertyBoolean('StampIsEnd'), $u['factor']);
            } elseif ($s['type'] === EPSeries::TYPE_COUNTER) {
                if ((int)@AC_GetAggregationType($aid, $vid) === 1) {
                    $rows = @AC_GetAggregatedValues($aid, $vid, 8, $dayStart, $dayEnd - 1, 0);
                    $q = EPSeries::fromAggregatedCounter(is_array($rows) ? $rows : [], $dayStart, $dayEnd, $u['factor']);
                } else {
                    $rows = $this->logged($aid, $vid, $dayStart, $dayEnd);
                    $prev = @AC_GetLoggedValues($aid, $vid, 0, $dayStart - 1, 1);
                    $p = is_array($prev) && count($prev) > 0 && is_numeric($prev[0]['Value'] ?? null) ? (float)$prev[0]['Value'] : null;
                    $q = EPSeries::fromCounterReadings($rows, $p, $dayStart, $dayEnd, $u['factor']);
                }
            } else {
                $rows = @AC_GetAggregatedValues($aid, $vid, 8, $dayStart, $dayEnd - 1, 0);
                $q = EPSeries::fromAggregatedPower(is_array($rows) ? $rows : [], $dayStart, $dayEnd, $u['factor']);
            }
        } catch (\Throwable $e) {
            $this->SendDebug('Archiv', '#' . $vid . ' ' . date('Y-m-d', $dayStart) . ': ' . $e->getMessage(), 0);
            return null;
        }
        return EPSeries::toWallProfile($q, true);
    }

    /** Rohwerte; bei über 10 000 Zeilen nur die Werte an den Viertelstundengrenzen. */
    private function logged(int $aid, int $vid, int $from, int $to): array
    {
        $rows = @AC_GetLoggedValues($aid, $vid, $from, $to, 0);
        if (!is_array($rows)) {
            return [];
        }
        if (count($rows) < 10000) {
            return $rows;
        }
        $out = [];
        for ($t = $from; $t <= $to; $t += EPSeries::QSEC) {
            $r = @AC_GetLoggedValues($aid, $vid, 0, $t, 1);
            if (is_array($r) && count($r) > 0) {
                $out[(int)$r[0]['TimeStamp']] = $r[0];
            }
        }
        return array_values($out);
    }

    /** Tagesmittel einer archivierten Variablen der letzten $days Tage: Ymd => Mittel. */
    private function dailyMeans(int $vid, int $days): array
    {
        if (!$this->isLogged($vid)) {
            return [];
        }
        $rows = @AC_GetAggregatedValues($this->archiveID(), $vid, 1, strtotime('today -' . $days . ' days'), strtotime('today') - 1, 0);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (isset($r['TimeStamp'], $r['Avg'])) {
                $out[(int)date('Ymd', (int)$r['TimeStamp'])] = (float)$r['Avg'];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- Wetter

    private function hasLocation(): bool
    {
        return !($this->ReadPropertyFloat('Latitude') == 0.0 && $this->ReadPropertyFloat('Longitude') == 0.0);
    }

    private function locationInfo(): string
    {
        if (!$this->hasLocation()) {
            return '⚠️ Standort der Anlage eintragen: ohne Breiten-/Längengrad keine Wettervorhersage.';
        }
        return sprintf('✅ Standort %.4F / %.4F', $this->ReadPropertyFloat('Latitude'), $this->ReadPropertyFloat('Longitude'));
    }

    /**
     * Stundenwerte von Open-Meteo, zwischengespeichert (Datei im Laufzeitverzeichnis, Zeitpunkt im Attribut).
     * Bei Abrufausfall wird eine höchstens $maxAge alte Antwort weiterverwendet.
     *
     * @return array<int, array<string, float|null>>|null
     */
    private function openMeteoHourly(array $vars, int $pastDays, int $ttl = 3600, int $maxAge = 21600): ?array
    {
        $url = EPOpenMeteo::url($this->ReadPropertyFloat('Latitude'), $this->ReadPropertyFloat('Longitude'), $vars, $pastDays, 3);
        $file = $this->runtimeDir('EnergiePrognose') . DIRECTORY_SEPARATOR . 'weather_' . $this->InstanceID . '.json';
        $meta = json_decode($this->ReadAttributeString('WeatherMeta'), true);
        $meta = is_array($meta) ? $meta : [];
        $same = ($meta['url'] ?? '') === $url && is_file($file);
        $age = time() - (int)($meta['fetched'] ?? 0);
        if (!$same || $age >= $ttl) {
            $tmp = $file . '.tmp';
            try {
                $this->downloadToFile($url, $tmp, 30);
                $data = json_decode((string)file_get_contents($tmp), true);
                EPOpenMeteo::parseHourly(is_array($data) ? $data : [], $vars);   // prüft die Antwort
                rename($tmp, $file);
                $this->WriteAttributeString('WeatherMeta', (string)json_encode(['url' => $url, 'fetched' => time()]));
                $same = true;
                $age = 0;
            } catch (\Throwable $e) {
                @unlink($tmp);
                $this->SendDebug('Wetter', $e->getMessage(), 0);
                if (!$same || $age >= $maxAge) {
                    $this->notes[] = '⛔ Wetterabruf fehlgeschlagen: ' . $e->getMessage();
                    return null;
                }
                $this->notes[] = '⚠️ Wetterabruf fehlgeschlagen, Vorhersage von vor ' . round($age / 3600, 1) . ' h genutzt';
            }
        }
        $data = json_decode((string)file_get_contents($file), true);
        return EPOpenMeteo::parseHourly(is_array($data) ? $data : [], $vars);
    }

    // ---------------------------------------------------------------- Veröffentlichen und Abfragen

    /** Tagesprognosen (Offset => Vertrags-Array oder null) in die Variablen schreiben. */
    private function publishForecasts(array $byOffset): void
    {
        for ($o = 0; $o < 3; $o++) {
            $fc = $byOffset[$o] ?? null;
            if (!is_array($fc)) {
                $fc = ['contractVersion' => EPForecast::CONTRACT, 'date' => date('Y-m-d', strtotime('today +' . $o . ' days')), 'generated' => 0];
            }
            $this->SetValue(self::fcIdents()[$o], (string)json_encode($fc));
            $this->SetValue(self::kwhIdents()[$o], (float)($fc['kwh'] ?? 0.0));
        }
    }

    /** Gespeicherte Prognose eines Tages; nach Mitternacht wird der passende Tag aus den Variablen gesucht. */
    private function forecastFor(int $offset): array
    {
        $want = date('Y-m-d', strtotime('today +' . $offset . ' days'));
        if ($offset < 0 || $offset > 2) {
            return ['contractVersion' => EPForecast::CONTRACT, 'error' => 'Offset 0 bis 2'];
        }
        foreach (self::fcIdents() as $ident) {
            $fc = json_decode((string)$this->GetValue($ident), true);
            if (is_array($fc) && ($fc['date'] ?? '') === $want && isset($fc['mean'])) {
                return $fc;
            }
        }
        return ['contractVersion' => EPForecast::CONTRACT, 'date' => $want, 'error' => 'keine Prognose vorhanden'];
    }

    private function energyWindowFor(int $from, int $to): array
    {
        return EPForecast::window(function (int $o) {
            $f = $this->forecastFor($o);
            return isset($f['mean']) ? $f : null;
        }, 3, $from, $to);
    }

    /** Abschluss eines Laufs: Status, Zeitstempel, Infozeile. */
    private function finishRun(string $status, float $t0, array $info = []): string
    {
        if (count($this->notes) > 0) {
            $status .= "\n" . implode("\n", array_unique($this->notes));
        }
        $this->SetValue('Status', $status);
        $this->SetValue('LastRun', time());
        $this->WriteAttributeString('LastInfo', (string)json_encode(array_merge($info, [
            'time' => time(), 'sec' => round(microtime(true) - $t0, 1), 'status' => $status])));
        $this->SendDebug('Rebuild', sprintf('%.1f s, %s', microtime(true) - $t0, $status), 0);
        return $status;
    }

    private function lastInfo(): string
    {
        $i = json_decode($this->ReadAttributeString('LastInfo'), true);
        if (!is_array($i) || empty($i['time'])) {
            return 'ℹ️ Noch keine Berechnung.';
        }
        return 'Letzte Berechnung ' . date('d.m.Y H:i', (int)$i['time']) . ' (' . $i['sec'] . ' s)' . "\n" . $i['status'];
    }

    /** Eigenschaften eines benannten Formularelements in beliebiger Tiefe setzen. */
    private function patchElement(array &$elements, string $name, array $patch): bool
    {
        foreach ($elements as &$el) {
            if (!is_array($el)) {
                continue;
            }
            if (($el['name'] ?? '') === $name) {
                $el = array_merge($el, $patch);
                return true;
            }
            if (isset($el['items']) && is_array($el['items']) && $this->patchElement($el['items'], $name, $patch)) {
                return true;
            }
        }
        return false;
    }

    private function listProperty(string $name): array
    {
        $v = json_decode((string)$this->ReadPropertyString($name), true);
        return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
    }
}
