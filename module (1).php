<?php

// Bewusst KEIN declare(strict_types=1) (docs/RULES.md, P001).

require_once __DIR__ . '/../libs/EPModuleCommon.php';
require_once __DIR__ . '/../libs/EPDegreeDay.php';
require_once __DIR__ . '/../libs/EPCalendar.php';
require_once __DIR__ . '/../libs/EPMath.php';

/**
 * Stromerzeugung eines wärmegeführten BHKW für heute, morgen und übermorgen.
 *
 * Wärmegeführt heißt: Das BHKW läuft, wenn Wärme gebraucht wird. Die elektrische Tagesenergie folgt daher der
 * Außentemperatur. Gelernt wird Tagesenergie = a + b · Heizgrad aus der gemessenen Erzeugung (a = Sockel für
 * Warmwasser/Prozesswärme), begrenzt auf Nennleistung × 24 h. Der Tagesverlauf kommt aus den Tagen mit ähnlichster
 * Temperatur (und gleichem Tagtyp), keine Viertelstunde über der Nennleistung.
 * Strom- oder fahrplangeführte BHKW folgen dem EMS bzw. Fahrplan und werden so nicht richtig vorhergesagt.
 */
class BHKWPrognose extends IPSModuleStrict
{
    use EPModuleCommon;

    public function Create(): void
    {
        // Diese Zeile nicht löschen
        parent::Create();

        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyInteger('IntervalHours', 6);
        $this->RegisterPropertyInteger('Resolution', 15);
        $this->RegisterPropertyFloat('Latitude', 0.0);
        $this->RegisterPropertyFloat('Longitude', 0.0);
        $this->RegisterPropertyString('State', '');
        $this->RegisterPropertyFloat('RatedKw', 20.0);
        $this->RegisterPropertyInteger('LookbackDays', 365);
        $this->RegisterPropertyInteger('Neighbors', 10);
        $this->RegisterPropertyInteger('MeasVar', 0);
        $this->RegisterPropertyInteger('MeasType', EPSeries::TYPE_POWER);
        $this->RegisterPropertyInteger('MeasUnit', 0);
        $this->RegisterPropertyInteger('EnergyInterval', 15);
        $this->RegisterPropertyBoolean('StampIsEnd', true);
        $this->RegisterPropertyInteger('TempVar', 0);
        $this->RegisterPropertyInteger('TempSource', 0);
        $this->RegisterPropertyInteger('TempFc0', 0);
        $this->RegisterPropertyInteger('TempFc1', 0);
        $this->RegisterPropertyInteger('TempFc2', 0);
        $this->RegisterPropertyFloat('HeatLimit', 15.0);
        $this->RegisterPropertyString('Closures', '[]');

        $this->RegisterAttributeString('WeatherMeta', '{}');
        $this->RegisterAttributeString('LastInfo', '{}');

        $this->RegisterTimer('RebuildTimer', 0, 'EPB_Rebuild($_IPS[\'TARGET\']);');
        $this->RegisterTimer('MidnightTimer', 0, 'EPB_Rebuild($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();
        if (!$this->kernelReady()) {
            return;
        }
        $this->registerForecastVariables('BHKW-Prognose');
        $this->startTimers($this->configProblem());
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string)file_get_contents(__DIR__ . '/form.json'), true);
        $tv = $this->ReadPropertyInteger('TempVar');
        $lines = [
            'InfoLocation' => $this->ReadPropertyInteger('TempSource') === 0 ? $this->locationInfo() : 'ℹ️ Standort nur für Open-Meteo nötig.',
            'InfoMeas' => $this->measInfo(),
            'InfoTemp' => $tv <= 0 ? '⚠️ Archivierte Außentemperatur wählen.' : ($this->isLogged($tv) ? '✅ Außentemperatur #' . $tv . ' ist archiviert.' : '⛔ Außentemperatur #' . $tv . ' fehlt oder ist nicht archiviert.'),
            'InfoLast' => $this->lastInfo(),
        ];
        foreach ($lines as $name => $caption) {
            $this->patchElement($form['elements'], $name, ['caption' => $caption]);
            $this->patchElement($form['actions'], $name, ['caption' => $caption]);
        }
        return (string)json_encode($form);
    }

    // ---------------------------------------------------------------- öffentlich

    public function Rebuild(): string
    {
        $this->resetTimersForRun();
        $problem = $this->configProblem();
        if ($problem !== 0) {
            $this->SetStatus($problem);
            $msg = '⛔ ' . $this->problemText($problem);
            $this->SetValue('Status', $msg);
            return $msg;
        }
        $this->notes = [];
        $t0 = microtime(true);
        try {
            $meas = $this->measSeries();
            $lookback = max(30, min(1830, $this->ReadPropertyInteger('LookbackDays')));
            $ratedKw = max(0.1, $this->ReadPropertyFloat('RatedKw'));
            $heatLimit = $this->ReadPropertyFloat('HeatLimit');
            $state = $this->ReadPropertyString('State');

            $fill = $this->fillCache($meas, $lookback);
            if (!$fill['complete']) {
                $this->SetTimerInterval('RebuildTimer', 60000);
                $this->notes[] = sprintf('⏳ Messung wird noch eingelesen (%d von %d Tagen)', $fill['done'], $fill['total']);
            }
            $temps = $this->dailyMeans($this->ReadPropertyInteger('TempVar'), $lookback);
            $closures = $this->closureDays();

            $days = [];
            for ($d = 1; $d <= $lookback; $d++) {
                $ds = strtotime('today -' . $d . ' days');
                $key = (int)date('Ymd', $ds);
                if (!isset($temps[$key]) || isset($closures[$key])) {
                    continue;
                }
                $kwh = $this->measuredKwh($meas, $ds);
                if ($kwh !== null) {
                    $days[] = ['t' => $temps[$key], 'kwh' => min($kwh, $ratedKw * 24.0), 'key' => $key, 'type' => EPCalendar::dayType($ds, $state)];
                }
            }
            $model = EPDegreeDay::fit($days, EPDegreeDay::HEAT, $heatLimit, 99.0, 14);
            if ($model === null) {
                $this->SetStatus(102);
                return $this->finishRun('⚠️ Zu wenig Lerntage mit Erzeugung und Außentemperatur (' . count($days) . ', nötig 14)', $t0);
            }
            $this->SendDebug('Modell', sprintf('a = %.2f kWh, b = %.3f kWh/K, %d Tage, Band %s', $model['coef'][0], $model['coef'][1], $model['n'], json_encode($model['band'])), 0);

            $fcTemps = $this->forecastTemps($temps);
            $ratedW = $ratedKw * 1000.0;
            $out = [];
            for ($o = 0; $o < 3; $o++) {
                $ds = strtotime('today +' . $o . ' days');
                $key = (int)date('Ymd', $ds);
                [$t, $tSrc] = $fcTemps[$o];
                if ($t === null) {
                    $out[$o] = null;
                    $this->notes[] = '⚠️ ' . date('d.m.', $ds) . ': keine Temperatur für die Vorhersage';
                    continue;
                }
                $closed = isset($closures[$key]);
                $kwh = $closed ? 0.0 : min(EPDegreeDay::predict($model, $t), $ratedKw * 24.0);
                $shape = $this->shape($days, $meas, $t, EPCalendar::dayType($ds, $state));
                $prof = function (float $dayKwh) use ($shape, $ratedW, $ratedKw) {
                    $dayKwh = min($dayKwh, $ratedKw * 24.0);
                    return EPForecast::capRedistribute(array_map(function ($s) use ($dayKwh) {
                        return $s * $dayKwh * 4000.0;   // Anteil × Tagesenergie → mittlere W der Viertelstunde
                    }, $shape), $ratedW);
                };
                $p50 = $prof($kwh * $model['band'][1]);
                $fc = EPForecast::build(date('Y-m-d', $ds), ['p10' => $prof($kwh * $model['band'][0]), 'p50' => $p50, 'p90' => $prof($kwh * $model['band'][2]), 'mean' => $prof($kwh)],
                    $this->ReadPropertyInteger('Resolution'), [
                        'ratedKw' => $ratedKw, 'temperature' => round($t, 1), 'temperatureSource' => $tSrc, 'closed' => $closed,
                        'modelDays' => $model['n'], 'coef' => array_map(function ($c) {
                            return round($c, 3);
                        }, $model['coef']),
                    ]);
                $fc['runHours'] = round($fc['kwh'] / $ratedKw, 1);
                $out[$o] = $fc;
            }
            $this->publishForecasts($out);
            $kwh = [];
            foreach ($out as $fc) {
                $kwh[] = $fc === null ? '–' : number_format($fc['kwh'], 1, ',', '');
            }
            $this->SetStatus(102);
            return $this->finishRun('✅ BHKW heute / morgen / übermorgen: ' . implode(' / ', $kwh) . ' kWh (Modell aus ' . $model['n'] . ' Tagen)', $t0);
        } catch (\Throwable $e) {
            $msg = '⛔ Fehler: ' . $e->getMessage();
            $this->SetValue('Status', $msg);
            $this->fail('BHKWPrognose: ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine());
            return $msg;
        }
    }

    public function GetForecast(int $Offset): array
    {
        return $this->forecastFor($Offset);
    }

    public function GetEnergyWindow(int $From, int $To): array
    {
        return $this->energyWindowFor($From, $To);
    }

    public function ClearCache(): string
    {
        $n = $this->clearCaches();
        return '✅ ' . $n . ' Speicherdatei(en) gelöscht; die Messung wird bei der nächsten Berechnung neu eingelesen.';
    }

    // ---------------------------------------------------------------- intern

    private function configProblem(): int
    {
        $meas = $this->measSeries();
        if ($meas === null || $this->ReadPropertyFloat('RatedKw') <= 0) {
            return 201;
        }
        if ($this->archiveID() === 0) {
            return 202;
        }
        if (!$this->isLogged($meas['var'])) {
            return 203;
        }
        if (!$this->isLogged($this->ReadPropertyInteger('TempVar'))) {
            return 205;
        }
        return 0;
    }

    private function problemText(int $code): string
    {
        $t = [201 => 'Gemessene BHKW-Erzeugung und Nennleistung eintragen.', 202 => 'Kein Archiv gefunden.',
              203 => 'Die Messvariable fehlt oder ist nicht archiviert.', 205 => 'Archivierte Außentemperatur fehlt.'];
        return $t[$code] ?? ('Problem ' . $code);
    }

    /** Ymd-Schlüssel der Stillstandstage (Wartung, Betriebsruhe). */
    private function closureDays(): array
    {
        $out = [];
        foreach ($this->listProperty('Closures') as $row) {
            $from = $this->parseDate($row['From'] ?? '');
            if ($from === null) {
                continue;
            }
            $to = $this->parseDate($row['To'] ?? '') ?? $from;
            for ($t = $from, $i = 0; $t <= $to && $i < 400; $t = strtotime('+1 day', $t), $i++) {
                $out[(int)date('Ymd', $t)] = true;
            }
        }
        return $out;
    }

    private function parseDate($value): ?int
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $t = strtotime($value);
            return $t === false ? null : $t;
        }
        $d = is_string($value) ? json_decode($value, true) : $value;
        if (is_array($d) && (int)($d['year'] ?? 0) > 0 && (int)($d['month'] ?? 0) > 0 && (int)($d['day'] ?? 0) > 0) {
            return mktime(0, 0, 0, (int)$d['month'], (int)$d['day'], (int)$d['year']);
        }
        return null;
    }

    /** Vorhersage-Tagesmittel je Offset: [°C|null, Quelle]. */
    private function forecastTemps(array $tempHist): array
    {
        $src = $this->ReadPropertyInteger('TempSource');
        $om = [];
        if ($src === 0) {
            if ($this->hasLocation()) {
                $hourly = $this->openMeteoHourly(['temperature_2m'], 0, 10800, 86400);
                $om = $hourly === null ? [] : EPOpenMeteo::dailyMeans($hourly, 'temperature_2m');
            } else {
                $this->notes[] = '⚠️ Standort fehlt: Mittel der Vorjahre statt Wettervorhersage';
            }
        }
        $vars = ['TempFc0', 'TempFc1', 'TempFc2'];
        $out = [];
        for ($o = 0; $o < 3; $o++) {
            $ts = strtotime('today +' . $o . ' days');
            $t = null;
            $from = '';
            if ($src === 0 && isset($om[date('Y-m-d', $ts)])) {
                $t = $om[date('Y-m-d', $ts)];
                $from = 'Open-Meteo';
            } elseif ($src === 1) {
                $vid = $this->ReadPropertyInteger($vars[$o]);
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $t = (float)GetValue($vid);
                    $from = 'Variable';
                }
            }
            if ($t === null) {
                $t = $this->climatology($ts, $tempHist);
                $from = $t === null ? 'keine' : 'Mittel der Vorjahre';
            }
            $out[$o] = [$t, $from];
        }
        return $out;
    }

    /** Mittel der Tagesmitteltemperatur um denselben Kalendertag (±7 Tage) in früheren Jahren. */
    private function climatology(int $ts, array $tempHist): ?float
    {
        $sum = 0.0;
        $n = 0;
        $doy = (int)date('z', $ts);
        foreach ($tempHist as $key => $t) {
            $k = (string)$key;
            $kts = mktime(0, 0, 0, (int)substr($k, 4, 2), (int)substr($k, 6, 2), (int)substr($k, 0, 4));
            if ($ts - $kts < 300 * 86400) {
                continue;
            }
            $diff = abs((int)date('z', $kts) - $doy);
            if (min($diff, 365 - $diff) <= 7) {
                $sum += $t;
                $n++;
            }
        }
        return $n >= 3 ? $sum / $n : null;
    }

    /**
     * Normierter Tagesverlauf (Summe 1): gewichtetes Mittel der Tage mit ähnlichster Temperatur und gleichem Tagtyp.
     *
     * @return float[] 96 Anteile
     */
    private function shape(array $days, array $meas, float $temp, int $type): array
    {
        $cands = [];
        foreach ($days as $i => $d) {
            if ($d['kwh'] >= 0.5) {
                $cands[$i] = abs($d['t'] - $temp) / 3.0 + ($d['type'] === $type ? 0.0 : 1.5);
            }
        }
        asort($cands);
        $k = max(3, min(40, $this->ReadPropertyInteger('Neighbors')));
        $profiles = [];
        $dists = [];
        $cache = $this->cacheFor($meas);
        foreach ($cands as $i => $dist) {
            $p = $cache->get($days[$i]['key']);
            if ($p === null) {
                continue;
            }
            $p = array_map('abs', $p);
            $sum = array_sum($p);
            if ($sum <= 0) {
                continue;
            }
            $profiles[] = array_map(function ($v) use ($sum) {
                return $v / $sum;
            }, $p);
            $dists[] = $dist;
            if (count($profiles) >= $k) {
                break;
            }
        }
        if (count($profiles) === 0) {
            return array_fill(0, EPSeries::QUARTERS, 1.0 / EPSeries::QUARTERS);
        }
        $w = EPMath::kernelWeights($dists);
        $ws = array_sum($w);
        $shape = array_fill(0, EPSeries::QUARTERS, 0.0);
        foreach ($profiles as $i => $p) {
            foreach ($p as $q => $v) {
                $shape[$q] += $v * $w[$i] / $ws;
            }
        }
        return $shape;
    }
}
