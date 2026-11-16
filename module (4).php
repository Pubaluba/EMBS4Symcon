<?php

// Bewusst KEIN declare(strict_types=1) (docs/RULES.md, P001).

require_once __DIR__ . '/../libs/EPModuleCommon.php';
require_once __DIR__ . '/../libs/EPPV.php';

/**
 * PV-Prognose für heute, morgen und übermorgen aus der Strahlungsvorhersage von Open-Meteo.
 *
 * Je Generator (kWp, Neigung, Ausrichtung): Einstrahlung auf die Modulebene, Temperaturverlust, Systemwirkungsgrad;
 * Summe auf die Wechselrichtergrenze begrenzt. Mit einer Messung der Erzeugung lernt das Modul einen Korrekturfaktor
 * (Verschattung, Verschmutzung, Abweichungen der Wetterdaten) und das Unsicherheitsband aus den letzten Tagen.
 */
class Solarprognose extends IPSModuleStrict
{
    use EPModuleCommon;

    private const VARS = ['shortwave_radiation', 'direct_normal_irradiance', 'diffuse_radiation', 'temperature_2m', 'cloud_cover'];

    public function Create(): void
    {
        // Diese Zeile nicht löschen
        parent::Create();

        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyInteger('IntervalHours', 3);
        $this->RegisterPropertyInteger('Resolution', 15);
        $this->RegisterPropertyFloat('Latitude', 0.0);
        $this->RegisterPropertyFloat('Longitude', 0.0);
        $this->RegisterPropertyString('Generators', '[]');
        $this->RegisterPropertyFloat('InverterKw', 0.0);
        $this->RegisterPropertyFloat('Efficiency', 90.0);
        $this->RegisterPropertyFloat('TempCoeff', -0.37);
        $this->RegisterPropertyInteger('MeasVar', 0);
        $this->RegisterPropertyInteger('MeasType', EPSeries::TYPE_POWER);
        $this->RegisterPropertyInteger('MeasUnit', 0);
        $this->RegisterPropertyInteger('EnergyInterval', 15);
        $this->RegisterPropertyBoolean('StampIsEnd', true);
        $this->RegisterPropertyBoolean('Calibrate', true);
        $this->RegisterPropertyInteger('CalibDays', 30);

        $this->RegisterAttributeString('WeatherMeta', '{}');
        $this->RegisterAttributeString('LastInfo', '{}');

        $this->RegisterTimer('RebuildTimer', 0, 'EPV_Rebuild($_IPS[\'TARGET\']);');
        $this->RegisterTimer('MidnightTimer', 0, 'EPV_Rebuild($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();
        if (!$this->kernelReady()) {
            return;
        }
        $this->registerForecastVariables('PV-Prognose');
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
        $kwp = array_sum(array_column($this->generators(), 'kwp'));
        $lines = [
            'InfoLocation' => $this->locationInfo(),
            'InfoGenerators' => count($this->generators()) > 0
                ? '✅ ' . count($this->generators()) . ' Generator(en), zusammen ' . number_format($kwp, 2, ',', '') . ' kWp'
                : '⚠️ Mindestens einen Generator eintragen.',
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
            $gens = $this->generators();
            $kwp = array_sum(array_column($gens, 'kwp'));
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
                    $m = $this->modelDay($ds, $hourly, $gens);
                    if ($ist !== null && $m !== null) {
                        $pairs[] = [$ist, EPSeries::kwh(EPForecast::cap($m['q'], $this->inverterW()))];
                    }
                }
                $cal = EPForecast::calibration($pairs, 0.3 * $kwp, 7, 0.5, 1.5);
                $this->SendDebug('Kalibrierung', count($pairs) . ' Tage, Ergebnis ' . json_encode($cal), 0);
                if ($cal === null) {
                    $this->notes[] = 'ℹ️ Noch zu wenig Tage für die Kalibrierung (' . count($pairs) . ' mit Messung und Wetter, nötig 7 mit nennenswerter Sonne)';
                }
            }
            $factor = $cal['factor'] ?? 1.0;

            $out = [];
            for ($o = 0; $o < 3; $o++) {
                $ds = strtotime('today +' . $o . ' days');
                $m = $this->modelDay($ds, $hourly, $gens);
                if ($m === null) {
                    $out[$o] = null;
                    $this->notes[] = '⚠️ ' . date('d.m.', $ds) . ': keine Wetterdaten';
                    continue;
                }
                $raw = array_map(function ($v) use ($factor) {
                    return $v * $factor;
                }, $m['q']);
                $p50 = EPForecast::cap($raw, $this->inverterW());
                $p10 = [];
                $p90 = [];
                $useCal = $cal !== null && $cal['days'] >= 14;
                foreach ($raw as $q => $v) {
                    if ($useCal) {
                        $lo = $cal['low'];
                        $hi = $cal['high'];
                    } else {
                        $rel = 0.1 + 0.4 * max(0.0, min(100.0, $m['cloud'][$q])) / 100.0;   // Schätzung: je bewölkter, desto unsicherer
                        $lo = 1.0 - $rel;
                        $hi = 1.0 + $rel;
                    }
                    $p10[$q] = $p50[$q] * $lo;
                    $p90[$q] = $v * $hi;
                }
                $p90 = EPForecast::cap($p90, $this->inverterW());
                $genOut = [];
                foreach ($gens as $i => $g) {
                    $genOut[] = ['name' => $g['name'], 'kwp' => $g['kwp'], 'kwh' => round($m['gens'][$i] * $factor, 2)];
                }
                $out[$o] = EPForecast::build(date('Y-m-d', $ds), ['p10' => $p10, 'p50' => $p50, 'p90' => $p90], $this->resolution(), [
                    'totalKwp' => round($kwp, 3), 'generators' => $genOut, 'factor' => round($factor, 3),
                    'calibrationDays' => $cal['days'] ?? 0, 'bandSource' => $useCal ? 'Messung' : 'Bewölkung (Schätzung)',
                    'source' => 'Open-Meteo',
                ]);
            }
            $this->publishForecasts($out);
            $kwh = [];
            foreach ($out as $fc) {
                $kwh[] = $fc === null ? '–' : number_format($fc['kwh'], 1, ',', '');
            }
            $status = '✅ PV heute / morgen / übermorgen: ' . implode(' / ', $kwh) . ' kWh'
                . ($cal !== null ? sprintf(' (Faktor %s aus %d Tagen)', number_format($factor, 2, ',', ''), $cal['days']) : '');
            $this->SetStatus(102);
            return $this->finishRun($status, $t0);
        } catch (\Throwable $e) {
            $msg = '⛔ Fehler: ' . $e->getMessage();
            $this->SetValue('Status', $msg);
            $this->fail('Solarprognose: ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine());
            return $msg;
        }
    }

    /** Prognose eines Tages (0 = heute, 1 = morgen, 2 = übermorgen); Felder siehe README. */
    public function GetForecast(int $Offset): array
    {
        return $this->forecastFor($Offset);
    }

    /** Erwartete Erzeugung (kWh) im Zeitfenster [$From, $To). */
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
        if (count($this->generators()) === 0) {
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
        $t = [201 => 'Keine PV-Generatoren eingetragen.', 203 => 'Die Messvariable fehlt oder ist nicht archiviert.',
              204 => 'Standort (Breiten-/Längengrad) fehlt.'];
        return $t[$code] ?? ('Problem ' . $code);
    }

    /** @return array<int, array{name: string, kwp: float, tilt: float, azimuth: float}> */
    private function generators(): array
    {
        $out = [];
        foreach ($this->listProperty('Generators') as $i => $row) {
            $kwp = (float)($row['Kwp'] ?? 0);
            if ($kwp <= 0) {
                continue;
            }
            $name = trim((string)($row['Name'] ?? ''));
            $out[] = ['name' => $name !== '' ? $name : 'Generator ' . ($i + 1), 'kwp' => $kwp,
                      'tilt' => max(0.0, min(90.0, (float)($row['Tilt'] ?? 30))),
                      'azimuth' => fmod(fmod((float)($row['Azimuth'] ?? 180), 360.0) + 360.0, 360.0)];
        }
        return $out;
    }

    private function inverterW(): float
    {
        return max(0.0, $this->ReadPropertyFloat('InverterKw')) * 1000.0;
    }

    private function resolution(): int
    {
        return $this->ReadPropertyInteger('Resolution');
    }

    /**
     * Modellertrag eines Tages ohne Kalibrierung und ohne Wechselrichtergrenze.
     *
     * @return array{q: float[], cloud: float[], gens: float[]}|null null, wenn für den Tag zu wenige Wetterdaten vorliegen
     */
    private function modelDay(int $dayStart, array $hourly, array $gens): ?array
    {
        $dayEnd = strtotime('tomorrow', $dayStart);
        $lat = $this->ReadPropertyFloat('Latitude');
        $lon = $this->ReadPropertyFloat('Longitude');
        $eff = max(0.3, min(1.0, $this->ReadPropertyFloat('Efficiency') / 100.0));
        $tk = $this->ReadPropertyFloat('TempCoeff');
        $byStart = [];
        $cloud = [];
        $genWh = array_fill(0, count($gens), 0.0);
        foreach (EPSeries::quarterStarts($dayStart, $dayEnd) as $a) {
            $mid = $a + 450;
            $dni = EPOpenMeteo::hourMean($hourly, 'direct_normal_irradiance', $mid);
            $dhi = EPOpenMeteo::hourMean($hourly, 'diffuse_radiation', $mid);
            $ghi = EPOpenMeteo::hourMean($hourly, 'shortwave_radiation', $mid);
            $cloud[$a] = EPOpenMeteo::interpolate($hourly, 'cloud_cover', $mid) ?? 50.0;
            if ($dni === null || $dhi === null || $ghi === null) {
                $byStart[$a] = null;
                continue;
            }
            $tAir = EPOpenMeteo::interpolate($hourly, 'temperature_2m', $mid) ?? 15.0;
            [$zen, $az] = EPSun::position($mid, $lat, $lon);
            $sum = 0.0;
            foreach ($gens as $i => $g) {
                $poa = EPPV::poa($dni, $dhi, $ghi, $zen, $az, $g['tilt'], $g['azimuth']);
                $w = EPPV::dcPower($g['kwp'], $poa, $tAir, $tk) * $eff;
                $genWh[$i] += $w * 0.25;
                $sum += $w;
            }
            $byStart[$a] = $sum;
        }
        $q = EPSeries::toWallProfile($byStart, true);
        if ($q === null) {
            return null;
        }
        return ['q' => $q, 'cloud' => EPSeries::toWallProfile($cloud, false),
                'gens' => array_map(function ($wh) {
                    return $wh / 1000.0;
                }, $genWh)];
    }
}
