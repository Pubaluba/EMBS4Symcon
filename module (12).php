<?php

// Bewusst KEIN declare(strict_types=1) (docs/RULES.md, P001).

require_once __DIR__ . '/../libs/EPModuleCommon.php';
require_once __DIR__ . '/../libs/EPWind.php';

/**
 * Prognose einer Kleinwindanlage für heute, morgen und übermorgen aus der Windvorhersage von Open-Meteo.
 *
 * Wind in Nabenhöhe aus den Modellhöhen 10/80/120/180 m (oder 10 m + Geländeklasse), Luftdichte aus Druck und
 * Temperatur, Leistung aus Herstellerkennlinie oder ½·ρ·A·cp·v³, Böigkeit über die Turbulenzintensität der
 * Geländeklasse. Band: Windgeschwindigkeit ±15 % (+5 % je Tag Vorlauf). Mit Messung lernt das Modul einen Faktor.
 */
class Windprognose extends IPSModuleStrict
{
    use EPModuleCommon;

    private const VARS = ['wind_speed_10m', 'wind_speed_80m', 'wind_speed_120m', 'wind_speed_180m', 'temperature_2m', 'surface_pressure'];
    private const LEVELS = [10 => 'wind_speed_10m', 80 => 'wind_speed_80m', 120 => 'wind_speed_120m', 180 => 'wind_speed_180m'];

    public function Create(): void
    {
        // Diese Zeile nicht löschen
        parent::Create();

        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyInteger('IntervalHours', 3);
        $this->RegisterPropertyInteger('Resolution', 15);
        $this->RegisterPropertyFloat('Latitude', 0.0);
        $this->RegisterPropertyFloat('Longitude', 0.0);
        $this->RegisterPropertyFloat('RatedKw', 5.0);
        $this->RegisterPropertyFloat('RotorArea', 20.0);
        $this->RegisterPropertyFloat('HubHeight', 18.0);
        $this->RegisterPropertyFloat('CutIn', 3.0);
        $this->RegisterPropertyFloat('CutOut', 25.0);
        $this->RegisterPropertyFloat('Cp', 0.30);
        $this->RegisterPropertyString('Curve', '[]');
        $this->RegisterPropertyInteger('Terrain', 2);
        $this->RegisterPropertyInteger('ProfileSource', 0);
        $this->RegisterPropertyInteger('MeasVar', 0);
        $this->RegisterPropertyInteger('MeasType', EPSeries::TYPE_POWER);
        $this->RegisterPropertyInteger('MeasUnit', 0);
        $this->RegisterPropertyInteger('EnergyInterval', 15);
        $this->RegisterPropertyBoolean('StampIsEnd', true);
        $this->RegisterPropertyBoolean('Calibrate', true);
        $this->RegisterPropertyInteger('CalibDays', 30);

        $this->RegisterAttributeString('WeatherMeta', '{}');
        $this->RegisterAttributeString('LastInfo', '{}');

        $this->RegisterTimer('RebuildTimer', 0, 'EPW_Rebuild($_IPS[\'TARGET\']);');
        $this->RegisterTimer('MidnightTimer', 0, 'EPW_Rebuild($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();
        if (!$this->kernelReady()) {
            return;
        }
        $this->registerForecastVariables('Windprognose');
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
        $lines = [
            'InfoLocation' => $this->locationInfo(),
            'InfoTurbine' => $this->turbineInfo(),
            'InfoMeas' => $this->measInfo(),
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
            $cfg = $this->turbine();
            $meas = $this->measSeries();
            $calibrate = $this->ReadPropertyBoolean('Calibrate') && $meas !== null && $this->isLogged($meas['var']);
            $days = max(7, min(92, $this->ReadPropertyInteger('CalibDays')));
            $hourly = $this->openMeteoHourly(self::VARS, $calibrate ? $days : 0, 3600);
            if ($hourly === null) {
                $this->SetStatus(102);
                return $this->finishRun('⛔ Keine Wetterdaten, Prognose nicht aktualisiert', $t0);
            }

            $cal = null;
            if ($calibrate) {
                $fill = $this->fillCache($meas, $days);
                if (!$fill['complete']) {
                    $this->SetTimerInterval('RebuildTimer', 60000);
                    $this->notes[] = sprintf('⏳ Messung wird noch eingelesen (%d von %d Tagen)', $fill['done'], $fill['total']);
                }
                $pairs = [];
                for ($d = 1; $d <= $days; $d++) {
                    $ds = strtotime('today -' . $d . ' days');
                    $ist = $this->measuredKwh($meas, $ds);
                    $m = $this->modelDay($ds, $hourly, $cfg, 0.0);
                    if ($ist !== null && $m !== null) {
                        $pairs[] = [$ist, EPSeries::kwh($m['p50'])];
                    }
                }
                $cal = EPForecast::calibration($pairs, 0.2 * $cfg['ratedKw'], 7, 0.3, 1.5);
                $this->SendDebug('Kalibrierung', count($pairs) . ' Tage, Ergebnis ' . json_encode($cal), 0);
                if ($cal === null) {
                    $this->notes[] = 'ℹ️ Noch zu wenig Tage für die Kalibrierung (' . count($pairs) . ' mit Messung und Wetter, nötig 7 mit nennenswertem Wind)';
                }
            }
            $factor = $cal['factor'] ?? 1.0;
            $ratedW = $cfg['ratedKw'] * 1000.0;

            $out = [];
            for ($o = 0; $o < 3; $o++) {
                $ds = strtotime('today +' . $o . ' days');
                $m = $this->modelDay($ds, $hourly, $cfg, 0.15 + 0.05 * $o);
                if ($m === null) {
                    $out[$o] = null;
                    $this->notes[] = '⚠️ ' . date('d.m.', $ds) . ': keine Wetterdaten';
                    continue;
                }
                $scale = function (array $q) use ($factor, $ratedW) {
                    return EPForecast::cap(array_map(function ($v) use ($factor) {
                        return $v * $factor;
                    }, $q), $ratedW);
                };
                $p50 = $scale($m['p50']);
                $fc = EPForecast::build(date('Y-m-d', $ds), ['p10' => $scale($m['p10']), 'p50' => $p50, 'p90' => $scale($m['p90'])], $this->ReadPropertyInteger('Resolution'), [
                    'ratedKw' => $cfg['ratedKw'], 'hubHeight' => $this->ReadPropertyFloat('HubHeight'),
                    'windHub' => array_map(function ($v) {
                        return round($v, 1);
                    }, EPSeries::resample($m['wind'], $this->ReadPropertyInteger('Resolution'))),
                    'factor' => round($factor, 3), 'calibrationDays' => $cal['days'] ?? 0, 'source' => 'Open-Meteo',
                ]);
                $fc['fullLoadHours'] = $cfg['ratedKw'] > 0 ? round($fc['kwh'] / $cfg['ratedKw'], 2) : 0.0;
                $out[$o] = $fc;
            }
            $this->publishForecasts($out);
            $kwh = [];
            foreach ($out as $fc) {
                $kwh[] = $fc === null ? '–' : number_format($fc['kwh'], 1, ',', '');
            }
            $status = '✅ Wind heute / morgen / übermorgen: ' . implode(' / ', $kwh) . ' kWh'
                . ($cal !== null ? sprintf(' (Faktor %s aus %d Tagen)', number_format($factor, 2, ',', ''), $cal['days']) : '');
            $this->SetStatus(102);
            return $this->finishRun($status, $t0);
        } catch (\Throwable $e) {
            $msg = '⛔ Fehler: ' . $e->getMessage();
            $this->SetValue('Status', $msg);
            $this->fail('Windprognose: ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine());
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

    /** Leistung (kW) der Anlage bei einer Windgeschwindigkeit in Nabenhöhe, ohne Böigkeit – zum Prüfen der Einstellungen. */
    public function GetPowerAt(?float $Speed = 8.0): float
    {
        return round(EPWind::power((float)$Speed, $this->turbine(), EPWind::RHO_STD), 3);
    }

    public function ClearCache(): string
    {
        $n = $this->clearCaches();
        return '✅ ' . $n . ' Speicherdatei(en) gelöscht; die Messung wird bei der nächsten Berechnung neu eingelesen.';
    }

    // ---------------------------------------------------------------- intern

    private function turbine(): array
    {
        $curve = [];
        foreach ($this->listProperty('Curve') as $row) {
            if (is_numeric($row['Speed'] ?? null) && is_numeric($row['Power'] ?? null)) {
                $curve[] = [(float)$row['Speed'], max(0.0, (float)$row['Power'])];
            }
        }
        return ['ratedKw' => max(0.0, $this->ReadPropertyFloat('RatedKw')), 'area' => max(0.0, $this->ReadPropertyFloat('RotorArea')),
                'cp' => max(0.05, min(0.593, $this->ReadPropertyFloat('Cp'))), 'cutIn' => max(0.0, $this->ReadPropertyFloat('CutIn')),
                'cutOut' => max(1.0, $this->ReadPropertyFloat('CutOut')), 'curve' => $curve];
    }

    private function terrain(): array
    {
        return EPWind::TERRAIN[$this->ReadPropertyInteger('Terrain')] ?? EPWind::TERRAIN[2];
    }

    private function configProblem(): int
    {
        $c = $this->turbine();
        if ($c['ratedKw'] <= 0 || ($c['area'] <= 0 && count($c['curve']) < 2) || $this->ReadPropertyFloat('HubHeight') <= 0) {
            return 201;
        }
        if (!$this->hasLocation()) {
            return 204;
        }
        $meas = $this->measSeries();
        if ($meas !== null && $this->ReadPropertyBoolean('Calibrate') && !$this->isLogged($meas['var'])) {
            return 203;
        }
        return 0;
    }

    private function problemText(int $code): string
    {
        $t = [201 => 'Anlagendaten unvollständig (Nennleistung, Rotorfläche oder Kennlinie, Nabenhöhe).',
              203 => 'Die Messvariable fehlt oder ist nicht archiviert.', 204 => 'Standort (Breiten-/Längengrad) fehlt.'];
        return $t[$code] ?? ('Problem ' . $code);
    }

    private function turbineInfo(): string
    {
        $c = $this->turbine();
        if ($this->configProblem() === 201) {
            return '⚠️ Nennleistung, Nabenhöhe und Rotorfläche (oder eine Kennlinie mit mindestens 2 Punkten) eintragen.';
        }
        $src = count($c['curve']) >= 2 ? 'Herstellerkennlinie (' . count($c['curve']) . ' Punkte)' : sprintf('Formel mit A = %s m², cp = %s', number_format($c['area'], 1, ',', ''), number_format($c['cp'], 2, ',', ''));
        $pts = [];
        foreach ([4, 6, 8, 10, 12] as $v) {
            $pts[] = $v . ' m/s: ' . number_format(EPWind::power((float)$v, $c, EPWind::RHO_STD), 2, ',', '') . ' kW';
        }
        return '✅ Leistung aus ' . $src . ' – ' . implode(', ', $pts) . ' (ohne Böigkeit, ρ = 1,225)';
    }

    /**
     * Modellertrag eines Tages ohne Kalibrierung.
     *
     * @return array{p10: float[], p50: float[], p90: float[], wind: float[]}|null
     */
    private function modelDay(int $dayStart, array $hourly, array $cfg, float $speedUnc): ?array
    {
        $hub = $this->ReadPropertyFloat('HubHeight');
        [$alpha, $ti] = $this->terrain();
        $modelProfile = $this->ReadPropertyInteger('ProfileSource') === 0;
        $p10 = [];
        $p50 = [];
        $p90 = [];
        $wind = [];
        foreach (EPSeries::quarterStarts($dayStart, strtotime('tomorrow', $dayStart)) as $a) {
            $mid = $a + 450;
            $levels = [];
            foreach (self::LEVELS as $h => $var) {
                $levels[$h] = EPOpenMeteo::interpolate($hourly, $var, $mid);
            }
            $v = EPWind::hubSpeed($levels, $hub, $alpha, $modelProfile);
            if ($v === null) {
                $p10[$a] = $p50[$a] = $p90[$a] = $wind[$a] = null;
                continue;
            }
            $rho = EPWind::airDensity(EPOpenMeteo::interpolate($hourly, 'surface_pressure', $mid), EPOpenMeteo::interpolate($hourly, 'temperature_2m', $mid));
            $mean = EPWind::expectedPower($v, $cfg, $rho, $ti);
            $vals = [$mean, EPWind::expectedPower($v * (1 - $speedUnc), $cfg, $rho, $ti), EPWind::expectedPower($v * (1 + $speedUnc), $cfg, $rho, $ti)];
            $p50[$a] = $mean * 1000.0;
            $p10[$a] = min($vals) * 1000.0;
            $p90[$a] = max($vals) * 1000.0;
            $wind[$a] = $v;
        }
        $r50 = EPSeries::toWallProfile($p50, true);
        if ($r50 === null) {
            return null;
        }
        return ['p10' => EPSeries::toWallProfile($p10, false), 'p50' => $r50, 'p90' => EPSeries::toWallProfile($p90, false),
                'wind' => EPSeries::toWallProfile($wind, false)];
    }
}
