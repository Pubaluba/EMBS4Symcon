<?php

// Bewusst KEIN declare(strict_types=1): Formularwerte kommen teils als anderer Typ an (docs/RULES.md, P001).

require_once __DIR__ . '/../libs/EPHelper.php';
require_once __DIR__ . '/../libs/EPSeries.php';
require_once __DIR__ . '/../libs/EPCalendar.php';
require_once __DIR__ . '/../libs/EPMath.php';
require_once __DIR__ . '/../libs/EPDayCache.php';
require_once __DIR__ . '/../libs/EPWallbox.php';

/**
 * Verbrauchsprognose für heute, morgen und übermorgen (P10/P50/P90) aus dem Archiv.
 *
 * Aufbau der Prognose:
 *  Grundlast   – ähnliche Tage aus der Historie (Tagtyp, Tageslänge, Außentemperatur, Anwesenheit/Betrieb)
 *  Wärme/Kälte – je Wärmepumpe/Klimagerät eine Regression Tagesenergie ~ Heiz-/Kühlgradzahl
 *  Wallbox     – Sofort-Ladevorgänge als feste Last; steuerbare Wallboxen nur als Bedarf fürs EMS
 *
 * Eingänge je Variable: Leistung, Energie je Intervall (z. B. 15-min-kWh) oder Zählerstand.
 */
class Verbrauchsprognose extends IPSModuleStrict
{
    use EPHelper;

    private const CONTRACT     = '1.0';
    private const HORIZON      = 3;            // heute, morgen, übermorgen
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const BUDGET_SEC   = 15.0;         // Zeit je Lauf für das Einlesen der Historie
    private const RECHECK_DAYS = 7;            // Tage ohne Daten so lange erneut prüfen (späte Archivwerte)
    private const EFFICIENCY   = 0.9;          // Ladewirkungsgrad Wallbox
    private const WEATHER_TTL  = 10800;        // Wettervorhersage 3 h zwischenspeichern
    private const WEATHER_MAX_AGE = 86400;     // bei Abrufausfall höchstens 24 h alte Vorhersage nutzen
    private const SESSION_TIMER_MS = 300000;   // Ladevorgänge alle 5 min nachführen

    private const IDENT_FC   = ['FcToday', 'FcTomorrow', 'FcDayAfter'];
    private const IDENT_KWH  = ['KwhToday', 'KwhTomorrow', 'KwhDayAfter'];
    private const IDENT_HEAT = ['HeatToday', 'HeatTomorrow', 'HeatDayAfter'];

    /** @var array<string, EPDayCache> request-lokal */
    private $caches = [];
    /** @var array request-lokal: Index Wärmegerät => Modell oder null */
    private $heatModelCache = null;
    /** @var string[] Hinweise des laufenden Rebuilds */
    private $notes = [];
    /** @var array|null request-lokal: Merkmale und Temperaturen eines Rebuilds */
    private $ctx = null;
    /** @var array<string, array> request-lokal: ausgewertete Variablenlisten */
    private $seriesMemo = [];

    // ------------------------------------------------------------------
    //  Lebenszyklus
    // ------------------------------------------------------------------

    public function Create(): void
    {
        // Diese Zeile nicht löschen
        parent::Create();

        // Allgemein
        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyInteger('IntervalHours', 6);
        $this->RegisterPropertyInteger('Resolution', 15);
        $this->RegisterPropertyFloat('Latitude', 0.0);
        $this->RegisterPropertyFloat('Longitude', 0.0);
        $this->RegisterPropertyString('State', '');
        $this->RegisterPropertyInteger('LookbackDays', 365);
        $this->RegisterPropertyInteger('Neighbors', 12);

        // Verbrauch
        $this->RegisterPropertyInteger('Source', 0);            // 0 = Hauptzähler minus gesteuerte, 1 = Summe Verbraucherliste
        $this->RegisterPropertyInteger('MainVar', 0);
        $this->RegisterPropertyInteger('MainType', EPSeries::TYPE_POWER);
        $this->RegisterPropertyInteger('MainUnit', 0);
        $this->RegisterPropertyString('Consumers', '[]');
        $this->RegisterPropertyString('Deductions', '[]');
        $this->RegisterPropertyInteger('EnergyInterval', 15);
        $this->RegisterPropertyBoolean('StampIsEnd', true);

        // Temperatur und Betrieb
        $this->RegisterPropertyInteger('TempVar', 0);
        $this->RegisterPropertyInteger('TempSource', 0);        // 0 = Open-Meteo, 1 = Variablen, 2 = keine Vorhersage
        $this->RegisterPropertyInteger('TempFc0', 0);
        $this->RegisterPropertyInteger('TempFc1', 0);
        $this->RegisterPropertyInteger('TempFc2', 0);
        $this->RegisterPropertyFloat('HeatLimit', 15.0);
        $this->RegisterPropertyFloat('CoolLimit', 22.0);
        $this->RegisterPropertyInteger('PresenceVar', 0);
        $this->RegisterPropertyBoolean('PresenceInvert', false);
        $this->RegisterPropertyString('Closures', '[]');

        // Wärmepumpe / Klima
        $this->RegisterPropertyString('HeatDevices', '[]');
        $this->RegisterPropertyBoolean('HeatInForecast', true);

        // Wallboxen
        $this->RegisterPropertyString('Wallboxes', '[]');

        $this->RegisterAttributeString('BaseForecasts', '{}');
        $this->RegisterAttributeString('Sessions', '{}');
        $this->RegisterAttributeString('Weather', '{}');
        $this->RegisterAttributeString('LastInfo', '{}');
        $this->RegisterAttributeString('PlugState', '{}');

        $this->RegisterTimer('RebuildTimer', 0, 'EPL_Rebuild($_IPS[\'TARGET\']);');
        $this->RegisterTimer('MidnightTimer', 0, 'EPL_Rebuild($_IPS[\'TARGET\']);');
        $this->RegisterTimer('WallboxTimer', 0, 'EPL_UpdateWallboxes($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        // Beim Symcon-Start erst weitermachen, wenn der Kernel (und damit das Archiv) bereit ist
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $pos = 10;
        $names = ['heute', 'morgen', 'übermorgen'];
        foreach ($names as $i => $n) {
            $this->RegisterVariableString(self::IDENT_FC[$i], 'Prognose ' . $n . ' (JSON)', '', $pos + $i);
            $this->RegisterVariableFloat(self::IDENT_KWH[$i], 'Erwartung ' . $n . ' (kWh)', '~Electricity', $pos + 10 + $i);
            $this->RegisterVariableFloat(self::IDENT_HEAT[$i], 'Wärmepumpe/Klima ' . $n . ' (kWh)', '~Electricity', $pos + 20 + $i);
        }
        $this->RegisterVariableFloat('WallboxDemand', 'Wallbox-Bedarf steuerbar (kWh)', '~Electricity', 40);
        $this->RegisterVariableString('Status', 'Status', '', 50);
        $this->RegisterVariableInteger('LastRun', 'Letzte Berechnung', '~UnixTimestamp', 51);

        // Anstecken einer Sofort-Wallbox startet den Ladevorgang: Statusvariablen beobachten
        foreach ($this->GetMessageList() as $sender => $messages) {
            if ((int)$sender !== 0 && in_array(VM_UPDATE, (array)$messages, true)) {
                $this->UnregisterMessage((int)$sender, VM_UPDATE);
            }
        }
        $plugState = [];
        foreach ($this->wallboxes() as $wb) {
            if ($wb['mode'] === EPWallbox::MODE_IMMEDIATE && $wb['plugVar'] > 0 && IPS_VariableExists($wb['plugVar'])) {
                $this->RegisterMessage($wb['plugVar'], VM_UPDATE);
                $plugState[$wb['plugVar']] = (bool)GetValue($wb['plugVar']);
            }
        }
        $this->WriteAttributeString('PlugState', (string)json_encode((object)$plugState));

        $this->SetTimerInterval('WallboxTimer', count($this->sessions()) > 0 ? self::SESSION_TIMER_MS : 0);

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('RebuildTimer', 0);
            $this->SetTimerInterval('MidnightTimer', 0);
            $this->SetStatus(104);
            return;
        }
        // Timer laufen auch bei einem Konfigurationsproblem weiter: Rebuild() prüft erneut und meldet es,
        // so erholt sich das Modul selbst, wenn z. B. die Archivierung später eingeschaltet wird.
        $problem = $this->configProblem();
        $this->SetStatus($problem !== 0 ? $problem : 102);
        // Erste Berechnung kurz nach dem Übernehmen (nicht im ApplyChanges selbst: kann dauern)
        $this->SetTimerInterval('RebuildTimer', 5000);
        $this->SetTimerInterval('MidnightTimer', $this->msToMidnight());
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message !== VM_UPDATE || !IPS_VariableExists($SenderID)) {
            return;
        }
        // Den Inhalt von $Data nicht auswerten (Belegung in der Doku nicht beschrieben): aktuellen Wert lesen und mit
        // dem zuletzt gemerkten vergleichen. Nur ein Wechsel startet bzw. beendet den Ladevorgang.
        $state = json_decode($this->ReadAttributeString('PlugState'), true);
        $state = is_array($state) ? $state : [];
        $now = (bool)GetValue($SenderID);
        $before = $state[$SenderID] ?? null;
        $state[$SenderID] = $now;
        $this->WriteAttributeString('PlugState', (string)json_encode((object)$state));
        if ($before === $now) {
            return;
        }
        foreach ($this->wallboxes() as $nr => $wb) {
            if ($wb['plugVar'] !== $SenderID || $wb['mode'] !== EPWallbox::MODE_IMMEDIATE) {
                continue;
            }
            if ($now) {
                $this->startSession($nr, 'angesteckt');
            } else {
                $this->endSession($nr);
            }
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string)file_get_contents(__DIR__ . '/form.json'), true);
        $lines = [
            'InfoSources' => $this->sourcesInfo(),
            'InfoLocation' => $this->locationInfo(),
            'InfoLast' => $this->lastInfo(),
        ];
        foreach ($lines as $name => $caption) {
            $this->patchElement($form['elements'], $name, ['caption' => $caption]);
            $this->patchElement($form['actions'], $name, ['caption' => $caption]);
        }
        $source = $this->ReadPropertyInteger('Source');
        $this->patchElement($form['elements'], 'MainRow', ['visible' => $source === 0]);
        $this->patchElement($form['elements'], 'Deductions', ['visible' => $source === 0]);
        $this->patchElement($form['elements'], 'Consumers', ['visible' => $source === 1]);
        return (string)json_encode($form);
    }

    /** onChange der Verbrauchsquelle: passende Felder einblenden. */
    public function SelectSource(?int $Source = 0): void
    {
        $s = (int)$Source;
        $this->UpdateFormField('MainRow', 'visible', $s === 0);
        $this->UpdateFormField('Deductions', 'visible', $s === 0);
        $this->UpdateFormField('Consumers', 'visible', $s === 1);
    }

    // ------------------------------------------------------------------
    //  Öffentliche Funktionen
    // ------------------------------------------------------------------

    /** Neuberechnung. Liefert eine lesbare Rückmeldung (für den Button und das Debug). */
    public function Rebuild(): string
    {
        $this->SetTimerInterval('RebuildTimer', max(1, $this->ReadPropertyInteger('IntervalHours')) * 3600000);
        $this->SetTimerInterval('MidnightTimer', $this->ReadPropertyBoolean('Active') ? $this->msToMidnight() : 0);
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
            $fill = $this->fillCaches();
            if (!$fill['complete']) {
                // Historie weiter einlesen, ohne auf das nächste Intervall zu warten
                $this->SetTimerInterval('RebuildTimer', 60000);
                $this->notes[] = sprintf('⏳ Historie wird noch eingelesen (%d von %d Tagen), nächster Schritt in 1 min',
                    $fill['done'], $fill['total']);
            }
            $base = [];
            for ($o = 0; $o < self::HORIZON; $o++) {
                $base[$o] = $this->computeBase($o);
            }
            $this->WriteAttributeString('BaseForecasts', (string)json_encode($base));
            $this->publish();

            $kwh = [];
            for ($o = 0; $o < self::HORIZON; $o++) {
                $kwh[] = number_format((float)$this->GetValue(self::IDENT_KWH[$o]), 1, ',', '');
            }
            $status = '✅ Erwartung heute / morgen / übermorgen: ' . implode(' / ', $kwh) . ' kWh';
            if (count($this->notes) > 0) {
                $status .= "\n" . implode("\n", array_unique($this->notes));
            }
            $this->SetValue('Status', $status);
            $this->SetValue('LastRun', time());
            $this->WriteAttributeString('LastInfo', (string)json_encode([
                'time' => time(), 'sec' => round(microtime(true) - $t0, 1), 'status' => $status,
                'neighbors' => $base[0]['neighbors'] ?? 0, 'history' => $fill,
            ]));
            $this->SetStatus(102);
            $this->SendDebug('Rebuild', sprintf('%.1f s, %s', microtime(true) - $t0, $status), 0);
            return $status;
        } catch (\Throwable $e) {
            $msg = '⛔ Fehler: ' . $e->getMessage();
            $this->SetValue('Status', $msg);
            $this->fail('Verbrauchsprognose: ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
            return $msg;
        }
    }

    /**
     * Prognose eines Tages (0 = heute, 1 = morgen, 2 = übermorgen) als Array.
     * Felder: contractVersion, date, slots, resolution, unit (W), p10, p50, p90, mean, kwh, kwhP10, kwhP90,
     * kwhBase, kwhHeat, kwhWallbox, base, heat, wallbox, neighbors, temperature, generated.
     */
    public function GetForecast(int $Offset): array
    {
        if ($Offset < 0 || $Offset >= self::HORIZON) {
            return ['contractVersion' => self::CONTRACT, 'error' => 'Offset 0 bis ' . (self::HORIZON - 1)];
        }
        $want = date('Y-m-d', strtotime('today +' . $Offset . ' days'));
        $fc = json_decode((string)$this->GetValue(self::IDENT_FC[$Offset]), true);
        if (!is_array($fc) || ($fc['date'] ?? '') !== $want) {
            $this->publish();   // nach Mitternacht: gespeicherte Tage neu zuordnen
            $fc = json_decode((string)$this->GetValue(self::IDENT_FC[$Offset]), true);
        }
        if (!is_array($fc) || ($fc['date'] ?? '') !== $want) {
            return ['contractVersion' => self::CONTRACT, 'date' => $want, 'error' => 'keine Prognose vorhanden'];
        }
        return $fc;
    }

    /**
     * Erwarteter Verbrauch (kWh) im Zeitfenster [$From, $To) aus den Tagesprognosen.
     * coverage = Anteil des Fensters, für den eine Prognose vorliegt.
     */
    public function GetEnergyWindow(int $From, int $To): array
    {
        $out = ['contractVersion' => self::CONTRACT, 'from' => $From, 'to' => $To,
                'kwh' => 0.0, 'kwhP10' => 0.0, 'kwhP90' => 0.0, 'kwhWallbox' => 0.0, 'coverage' => 0.0];
        if ($To <= $From) {
            return $out;
        }
        $fcs = [];
        $covered = 0;
        $t = $From;
        while ($t < $To) {
            $day = strtotime('today', $t);
            $off = (int)round(($day - strtotime('today')) / 86400);
            $next = min($To, $t + EPSeries::QSEC - ($t - $day) % EPSeries::QSEC);
            if ($next <= $t) {
                $next = $t + 1;
            }
            if ($off >= 0 && $off < self::HORIZON) {
                if (!array_key_exists($off, $fcs)) {
                    $f = $this->GetForecast($off);
                    $fcs[$off] = isset($f['mean']) ? $f : null;
                }
                $f = $fcs[$off];
                if ($f !== null) {
                    $per = intdiv(1440, count($f['mean']));
                    $slot = intdiv(EPSeries::wallQuarter($t) * 15, $per);
                    $h = ($next - $t) / 3600.0 / 1000.0;
                    $out['kwh'] += $f['mean'][$slot] * $h;
                    $out['kwhP10'] += $f['p10'][$slot] * $h;
                    $out['kwhP90'] += $f['p90'][$slot] * $h;
                    $out['kwhWallbox'] += ($f['wallbox'][$slot] ?? 0) * $h;
                    $covered += $next - $t;
                }
            }
            $t = $next;
        }
        foreach (['kwh', 'kwhP10', 'kwhP90', 'kwhWallbox'] as $k) {
            $out[$k] = round($out[$k], 3);
        }
        $out['coverage'] = round($covered / ($To - $From), 3);
        return $out;
    }

    /**
     * Bedarf aller Wallboxen fürs EMS: je Wallbox Name, Betriebsart, angesteckt, SoC (Quelle), Bedarf in kWh,
     * fertig bis, spätester Start, Ladeleistung und laufender Sofort-Ladevorgang.
     */
    public function GetWallboxDemand(): array
    {
        $now = time();
        $out = [];
        $sessions = $this->sessions();
        foreach ($this->wallboxes() as $nr => $wb) {
            [$soc, $src] = $this->socNow($wb);
            $plugged = $this->plugged($wb);
            $need = EPWallbox::needKwh($wb['capacity'], $soc, $wb['target'], self::EFFICIENCY);
            $ready = $wb['mode'] === EPWallbox::MODE_CONTROLLED ? EPWallbox::nextTime($now, $wb['readyAt']) : null;
            $dur = EPWallbox::durationSec($need, $wb['chargeKw']);
            $out[] = [
                'number' => $nr, 'name' => $wb['name'], 'mode' => $wb['mode'] === EPWallbox::MODE_CONTROLLED ? 'steuerbar' : 'sofort',
                'plugged' => $plugged, 'soc' => round($soc, 1), 'socSource' => $src, 'targetSoc' => $wb['target'],
                'needKwh' => round($plugged === false ? 0.0 : $need, 2), 'needKwhIfPlugged' => round($need, 2),
                'chargeKw' => $wb['chargeKw'], 'durationMin' => (int)round($dur / 60),
                'readyAt' => $ready, 'latestStart' => $ready !== null ? $ready - $dur : null,
                'sessionActive' => isset($sessions[$nr]),
            ];
        }
        return $out;
    }

    /** Sofort voll laden: plant für Wallbox Nr. $Number (1 = erste Zeile) einen Ladeblock ab jetzt ein. */
    public function ChargeNow(?int $Number = 1): string
    {
        $nr = (int)$Number;
        $list = $this->wallboxes();
        if (!isset($list[$nr])) {
            return '⚠️ Wallbox Nr. ' . $nr . ' gibt es nicht (' . count($list) . ' konfiguriert).';
        }
        return $this->startSession($nr, 'Knopf');
    }

    /** Laufenden Sofort-Ladevorgang der Wallbox Nr. $Number beenden. */
    public function StopCharge(?int $Number = 1): string
    {
        $nr = (int)$Number;
        if (!isset($this->sessions()[$nr])) {
            return 'ℹ️ Für Wallbox Nr. ' . $nr . ' läuft kein Sofort-Ladevorgang.';
        }
        $this->endSession($nr);
        return '✅ Ladevorgang von Wallbox Nr. ' . $nr . ' beendet, Prognose aktualisiert.';
    }

    /** Timer: Ladevorgänge nachführen (Ende erkennen) und die Prognose mit dem Ladeblock neu veröffentlichen. */
    public function UpdateWallboxes(): void
    {
        $sessions = $this->sessions();
        $list = $this->wallboxes();
        $now = time();
        foreach ($sessions as $nr => $s) {
            $wb = $list[$nr] ?? null;
            if ($wb === null || $this->remainingKwh($wb, $s, $now) <= 0.05 || $this->plugged($wb) === false) {
                unset($sessions[$nr]);
                $this->SendDebug('Wallbox', 'Ladevorgang Nr. ' . $nr . ' beendet', 0);
            }
        }
        $this->WriteAttributeString('Sessions', (string)json_encode((object)$sessions));
        $this->SetTimerInterval('WallboxTimer', count($sessions) > 0 ? self::SESSION_TIMER_MS : 0);
        $this->publish();
    }

    /** Tagesspeicher dieser Instanz löschen; die Historie wird danach schrittweise neu eingelesen. */
    public function ClearCache(): string
    {
        $dir = $this->cacheDir();
        $n = 0;
        foreach ((array)glob($dir . DIRECTORY_SEPARATOR . '*.dat') as $f) {
            if (is_string($f) && @unlink($f)) {
                $n++;
            }
        }
        $this->caches = [];
        if ($this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('RebuildTimer', 5000);
        }
        return '✅ ' . $n . ' Speicherdatei(en) gelöscht. Die Historie wird bei der nächsten Berechnung neu eingelesen.';
    }

    // ------------------------------------------------------------------
    //  Konfiguration
    // ------------------------------------------------------------------

    /** 0 = in Ordnung, sonst Statuscode (201 keine Quelle, 202 kein Archiv, 203 Quelle nicht archiviert). */
    private function configProblem(): int
    {
        $aid = $this->archiveID();
        if ($aid === 0) {
            return 202;
        }
        $base = $this->baseSeries();
        if (count($base) === 0) {
            return 201;
        }
        foreach ($base as $s) {
            if (!IPS_VariableExists($s['var']) || !@AC_GetLoggingStatus($aid, $s['var'])) {
                return 203;
            }
        }
        return 0;
    }

    private function problemText(int $code): string
    {
        switch ($code) {
            case 201:
                return 'Keine Verbrauchsquelle: Hauptzähler bzw. Verbraucherliste ausfüllen.';
            case 202:
                return 'Kein Archiv (Archive Control) gefunden.';
            case 203:
                return 'Eine Verbrauchsvariable fehlt oder ist nicht archiviert.';
        }
        return 'Unbekanntes Problem (' . $code . ')';
    }

    private function archiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return count($ids) > 0 ? (int)$ids[0] : 0;
    }

    /** Zeile einer Variablenliste normieren. */
    private function seriesRow(array $row, string $role, string $varKey = 'VariableID'): ?array
    {
        $vid = (int)($row[$varKey] ?? 0);
        if ($vid <= 0) {
            return null;
        }
        $type = (int)($row['Type'] ?? EPSeries::TYPE_POWER);
        if (!in_array($type, [EPSeries::TYPE_POWER, EPSeries::TYPE_INTERVAL, EPSeries::TYPE_COUNTER], true)) {
            $type = EPSeries::TYPE_POWER;
        }
        $name = trim((string)($row['Name'] ?? ''));
        if ($name === '') {
            $name = IPS_VariableExists($vid) ? IPS_GetName($vid) : '#' . $vid;
        }
        return ['var' => $vid, 'type' => $type, 'unit' => max(0, min(3, (int)($row['Unit'] ?? 0))), 'name' => $name, 'role' => $role];
    }

    private function listProperty(string $name): array
    {
        $v = json_decode((string)$this->ReadPropertyString($name), true);
        return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
    }

    /** Variablen, aus denen die Grundlast entsteht (Hauptzähler bzw. ungesteuerte Verbraucher). */
    private function baseSeries(): array
    {
        if (isset($this->seriesMemo['baseSeries'])) {
            return $this->seriesMemo['baseSeries'];
        }
        return $this->seriesMemo['baseSeries'] = $this->baseSeriesBuild();
    }

    private function baseSeriesBuild(): array
    {
        if ($this->ReadPropertyInteger('Source') === 0) {
            $s = $this->seriesRow(['VariableID' => $this->ReadPropertyInteger('MainVar'), 'Type' => $this->ReadPropertyInteger('MainType'),
                'Unit' => $this->ReadPropertyInteger('MainUnit'), 'Name' => 'Hauptzähler'], 'main');
            return $s === null ? [] : [$s];
        }
        $out = [];
        $controlled = array_merge(array_column($this->heatSeries(), 'var'), array_column($this->wallboxSeries(), 'var'));
        foreach ($this->listProperty('Consumers') as $row) {
            $s = $this->seriesRow($row, 'consumer');
            if ($s === null) {
                continue;
            }
            if (in_array($s['var'], $controlled, true)) {
                $this->notes[] = 'ℹ️ „' . $s['name'] . '“ steht auch bei Wärmepumpe/Wallbox und wird in der Verbraucherliste übersprungen';
                continue;
            }
            $out[] = $s;
        }
        return $out;
    }

    private function deductionSeries(): array
    {
        if (isset($this->seriesMemo['deductionSeries'])) {
            return $this->seriesMemo['deductionSeries'];
        }
        return $this->seriesMemo['deductionSeries'] = $this->deductionSeriesBuild();
    }

    private function deductionSeriesBuild(): array
    {
        if ($this->ReadPropertyInteger('Source') !== 0) {
            return [];
        }
        $out = [];
        foreach ($this->listProperty('Deductions') as $row) {
            $s = $this->seriesRow($row, 'deduction');
            if ($s !== null) {
                $out[] = $s;
            }
        }
        return $out;
    }

    private function heatSeries(): array
    {
        if (isset($this->seriesMemo['heatSeries'])) {
            return $this->seriesMemo['heatSeries'];
        }
        return $this->seriesMemo['heatSeries'] = $this->heatSeriesBuild();
    }

    private function heatSeriesBuild(): array
    {
        $out = [];
        foreach ($this->listProperty('HeatDevices') as $row) {
            $s = $this->seriesRow($row, 'heat');
            if ($s !== null) {
                $s['mode'] = max(0, min(2, (int)($row['Mode'] ?? 0)));   // 0 Heizen, 1 Kühlen, 2 beides
                $out[] = $s;
            }
        }
        return $out;
    }

    private function wallboxSeries(): array
    {
        if (isset($this->seriesMemo['wallboxSeries'])) {
            return $this->seriesMemo['wallboxSeries'];
        }
        return $this->seriesMemo['wallboxSeries'] = $this->wallboxSeriesBuild();
    }

    private function wallboxSeriesBuild(): array
    {
        $out = [];
        foreach ($this->listProperty('Wallboxes') as $row) {
            $s = $this->seriesRow($row, 'wallbox', 'PowerVar');
            if ($s !== null) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** Alle Variablen mit Archivhistorie (für den Tagesspeicher), ohne Doppelte. */
    private function allSeries(): array
    {
        $out = [];
        foreach (array_merge($this->baseSeries(), $this->deductionSeries(), $this->heatSeries(), $this->wallboxSeries()) as $s) {
            $out[$s['var'] . '|' . $s['type'] . '|' . $s['unit']] = $s;
        }
        return array_values($out);
    }

    /** Wallboxen aus der Liste, Schlüssel = Nummer ab 1. */
    private function wallboxes(): array
    {
        $out = [];
        $nr = 0;
        foreach ($this->listProperty('Wallboxes') as $row) {
            $nr++;
            $out[$nr] = [
                'name'     => trim((string)($row['Name'] ?? '')) !== '' ? trim((string)$row['Name']) : 'Wallbox ' . $nr,
                'mode'     => (int)($row['Mode'] ?? 0) === 1 ? EPWallbox::MODE_IMMEDIATE : EPWallbox::MODE_CONTROLLED,
                'socVar'   => (int)($row['SocVar'] ?? 0),
                'plugVar'  => (int)($row['PlugVar'] ?? 0),
                'capacity' => max(0.0, (float)($row['CapacityKwh'] ?? 0)),
                'startSoc' => max(0.0, min(100.0, (float)($row['StartSoc'] ?? 20))),
                'target'   => max(0.0, min(100.0, (float)($row['TargetSoc'] ?? 100))),
                'chargeKw' => max(0.0, (float)($row['ChargeKw'] ?? 11)),
                'readyAt'  => (string)($row['ReadyAt'] ?? '07:00'),
            ];
        }
        return $out;
    }

    /**
     * Umrechnungsfaktor einer Variablen: Leistung → W, Energie → Wh je Werteinheit.
     * Einheit 0 = automatisch aus dem Suffix des Variablenprofils, sonst W/kW/MW bzw. Wh/kWh/MWh.
     *
     * @return array{factor: float, label: string, source: string}
     */
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
        return $power ? ['factor' => 1.0, 'label' => 'W', 'source' => 'angenommen']
                      : ['factor' => 1000.0, 'label' => 'kWh', 'source' => 'angenommen'];
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

    /** Warnung, wenn Messart und Profil-Einheit nicht zusammenpassen (z. B. Zählerstand als Leistung). */
    private function unitMismatch(array $s): string
    {
        $suffix = strtolower($this->profileSuffix($s['var']));
        if ($suffix === '') {
            return '';
        }
        $energy = in_array($suffix, ['wh', 'kwh', 'mwh'], true);
        $power = in_array($suffix, ['w', 'kw', 'mw'], true);
        if ($s['type'] === EPSeries::TYPE_POWER && $energy) {
            return 'Profil-Einheit ' . $suffix . ' passt nicht zur Messart „Leistung“ – Messart prüfen';
        }
        if ($s['type'] !== EPSeries::TYPE_POWER && $power) {
            return 'Profil-Einheit ' . $suffix . ' passt nicht zur Messart „Energie“ – Messart prüfen';
        }
        return '';
    }

    // ------------------------------------------------------------------
    //  Historie: Tagesspeicher füllen
    // ------------------------------------------------------------------

    private function cacheDir(): string
    {
        return $this->runtimeDir('EnergiePrognose') . DIRECTORY_SEPARATOR . 'i' . $this->InstanceID;
    }

    private function cacheFor(array $s): EPDayCache
    {
        $u = $this->unitInfo($s);
        $key = $s['var'] . '|' . $s['type'] . '|' . $u['factor'];
        if ($s['type'] === EPSeries::TYPE_INTERVAL) {
            $key .= '|' . $this->ReadPropertyInteger('EnergyInterval') . '|' . ($this->ReadPropertyBoolean('StampIsEnd') ? 'e' : 'b');
        }
        if (!isset($this->caches[$key])) {
            $file = $this->cacheDir() . DIRECTORY_SEPARATOR . 'v' . $s['var'] . '_' . substr(md5($key), 0, 10) . '.dat';
            $this->caches[$key] = new EPDayCache($file);
        }
        return $this->caches[$key];
    }

    private function lookback(): int
    {
        return max(14, min(1830, $this->ReadPropertyInteger('LookbackDays')));
    }

    /**
     * Fehlende Tage aller Variablen aus dem Archiv lesen, jüngste zuerst, mit Zeitbudget je Lauf.
     *
     * @return array{complete: bool, done: int, total: int}
     */
    private function fillCaches(): array
    {
        $deadline = microtime(true) + self::BUDGET_SEC;
        $series = $this->allSeries();
        $lookback = $this->lookback();
        $complete = true;
        $done = 0;
        for ($d = 1; $d <= $lookback; $d++) {
            $dayStart = strtotime('today -' . $d . ' days');
            $key = (int)date('Ymd', $dayStart);
            $dayOk = true;
            foreach ($series as $s) {
                $cache = $this->cacheFor($s);
                $st = $cache->status($key);
                if ($st === 1 || ($st === 0 && $d > self::RECHECK_DAYS)) {
                    continue;
                }
                if (microtime(true) > $deadline) {
                    $complete = false;
                    $dayOk = false;
                    break;
                }
                $cache->put($key, $this->readDay($s, $dayStart, strtotime('tomorrow', $dayStart)));
            }
            if (!$complete) {
                break;
            }
            if ($dayOk) {
                $done++;
            }
        }
        foreach ($this->caches as $c) {
            $c->compactIfNeeded();
        }
        if (!$complete) {
            // Fortschritt: Tage, für die jede Variable schon im Speicher steht (mit oder ohne Daten)
            $done = 0;
            for ($d = 1; $d <= $lookback; $d++) {
                $key = (int)date('Ymd', strtotime('today -' . $d . ' days'));
                $all = true;
                foreach ($series as $s) {
                    if ($this->cacheFor($s)->status($key) === null) {
                        $all = false;
                        break;
                    }
                }
                $done += $all ? 1 : 0;
            }
        }
        return ['complete' => $complete, 'done' => $complete ? $lookback : $done, 'total' => $lookback];
    }

    /** Ein Tag einer Variablen als 96 Viertelstunden in W, null bei zu wenig Daten. */
    private function readDay(array $s, int $dayStart, int $dayEnd): ?array
    {
        $aid = $this->archiveID();
        $u = $this->unitInfo($s);
        $vid = $s['var'];
        try {
            switch ($s['type']) {
                case EPSeries::TYPE_INTERVAL:
                    $iv = max(1, $this->ReadPropertyInteger('EnergyInterval')) * 60;
                    $rows = $this->logged($aid, $vid, $dayStart, $dayEnd + $iv);
                    $prev = @AC_GetLoggedValues($aid, $vid, 0, $dayStart - 1, 1);
                    $q = EPSeries::fromIntervalEnergy($rows, is_array($prev) && count($prev) > 0 ? $prev[0] : null,
                        $dayStart, $dayEnd, intdiv($iv, 60), $this->ReadPropertyBoolean('StampIsEnd'), $u['factor']);
                    break;
                case EPSeries::TYPE_COUNTER:
                    if ((int)@AC_GetAggregationType($aid, $vid) === 1) {
                        // Aggregationstyp „Zähler“: Avg des 15-Minuten-Aggregats = Zuwachs der Viertelstunde
                        $rows = @AC_GetAggregatedValues($aid, $vid, 8, $dayStart, $dayEnd - 1, 0);
                        $q = EPSeries::fromAggregatedCounter(is_array($rows) ? $rows : [], $dayStart, $dayEnd, $u['factor']);
                    } else {
                        $rows = $this->logged($aid, $vid, $dayStart, $dayEnd);
                        $prev = @AC_GetLoggedValues($aid, $vid, 0, $dayStart - 1, 1);
                        $p = is_array($prev) && count($prev) > 0 && is_numeric($prev[0]['Value'] ?? null) ? (float)$prev[0]['Value'] : null;
                        $q = EPSeries::fromCounterReadings($rows, $p, $dayStart, $dayEnd, $u['factor']);
                    }
                    break;
                default:
                    $rows = @AC_GetAggregatedValues($aid, $vid, 8, $dayStart, $dayEnd - 1, 0);
                    $q = EPSeries::fromAggregatedPower(is_array($rows) ? $rows : [], $dayStart, $dayEnd, $u['factor']);
            }
        } catch (\Throwable $e) {
            $this->SendDebug('Archiv', '#' . $vid . ' ' . date('Y-m-d', $dayStart) . ': ' . $e->getMessage(), 0);
            return null;
        }
        return EPSeries::toWallProfile($q, true);
    }

    /**
     * Rohwerte eines Zeitraums. Das Archiv liefert höchstens 10 000 Zeilen je Abfrage (neueste zuerst); bei sehr
     * dicht geloggten Variablen wird dann je Viertelstunde nur der zuletzt gültige Wert geholt (reicht für Zähler
     * und Intervallwerte, weil dort ohnehin nur der Wert an den Grenzen zählt).
     */
    private function logged(int $aid, int $vid, int $from, int $to): array
    {
        $rows = @AC_GetLoggedValues($aid, $vid, $from, $to, 0);
        if (!is_array($rows)) {
            return [];
        }
        if (count($rows) < 10000) {
            return $rows;
        }
        $this->notes[] = 'ℹ️ #' . $vid . ' ist sehr dicht archiviert (über 10 000 Werte am Tag), es werden nur die Werte an den Viertelstundengrenzen gelesen';
        $out = [];
        for ($t = $from; $t <= $to; $t += EPSeries::QSEC) {
            $r = @AC_GetLoggedValues($aid, $vid, 0, $t, 1);
            if (is_array($r) && count($r) > 0) {
                $out[(int)$r[0]['TimeStamp']] = $r[0];
            }
        }
        return array_values($out);
    }

    /** Tagesmittel einer Variablen über den ganzen Rückblick: Ymd => Mittelwert (ein Archivaufruf). */
    private function dailyMeans(int $vid): array
    {
        $aid = $this->archiveID();
        if ($vid <= 0 || $aid === 0 || !IPS_VariableExists($vid) || !@AC_GetLoggingStatus($aid, $vid)) {
            return [];
        }
        $from = strtotime('today -' . $this->lookback() . ' days');
        $rows = @AC_GetAggregatedValues($aid, $vid, 1, $from, strtotime('today') - 1, 0);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (isset($r['TimeStamp'], $r['Avg'])) {
                $out[(int)date('Ymd', (int)$r['TimeStamp'])] = (float)$r['Avg'];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    //  Merkmale der Tage
    // ------------------------------------------------------------------

    /** Ymd-Schlüssel der Abwesenheiten/Betriebsferien. */
    private function closureDays(): array
    {
        $out = [];
        foreach ($this->listProperty('Closures') as $row) {
            $from = $this->parseDate($row['From'] ?? '');
            $to = $this->parseDate($row['To'] ?? '');
            if ($from === null) {
                continue;
            }
            $to = $to ?? $from;
            for ($t = $from, $i = 0; $t <= $to && $i < 400; $t = strtotime('+1 day', $t), $i++) {
                $out[(int)date('Ymd', $t)] = true;
            }
        }
        return $out;
    }

    /** SelectDate-Wert ({"year":…,"month":…,"day":…}) oder „Y-m-d“ → Mitternacht, sonst null. */
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

    /**
     * Merkmale aller Rückblick-Tage: Ymd => [ts, type, len, temp|null, pres].
     */
    private function historyFeatures(array $temps, array $presence, array $closures): array
    {
        $state = $this->ReadPropertyString('State');
        $lat = $this->latitude();
        $invert = $this->ReadPropertyBoolean('PresenceInvert');
        $out = [];
        for ($d = 1; $d <= $this->lookback(); $d++) {
            $ts = strtotime('today -' . $d . ' days');
            $key = (int)date('Ymd', $ts);
            if (isset($presence[$key])) {
                $p = max(0.0, min(1.0, $presence[$key]));
                $p = $invert ? 1.0 - $p : $p;
            } else {
                $p = isset($closures[$key]) ? 0.0 : 1.0;
            }
            $out[$key] = ['ts' => $ts, 'type' => EPCalendar::dayType($ts, $state), 'len' => EPCalendar::dayLengthHours($ts, $lat),
                          'temp' => $temps[$key] ?? null, 'pres' => $p];
        }
        return $out;
    }

    private function targetFeatures(int $offset, ?float $temp, array $closures): array
    {
        $ts = strtotime('today +' . $offset . ' days');
        $key = (int)date('Ymd', $ts);
        $p = isset($closures[$key]) ? 0.0 : 1.0;
        $pv = $this->ReadPropertyInteger('PresenceVar');
        if ($offset === 0 && !isset($closures[$key]) && $pv > 0 && IPS_VariableExists($pv)) {
            $v = max(0.0, min(1.0, (float)GetValue($pv)));
            $p = $this->ReadPropertyBoolean('PresenceInvert') ? 1.0 - $v : $v;
        }
        return ['ts' => $ts, 'type' => EPCalendar::dayType($ts, $this->ReadPropertyString('State')),
                'len' => EPCalendar::dayLengthHours($ts, $this->latitude()), 'temp' => $temp, 'pres' => $p];
    }

    /**
     * Abstand zweier Tage. Gewichte: Der Tagtyp dominiert (Werktag/Samstag/Sonntag); 1,5 h Unterschied in der
     * Tageslänge, 5 K in der Tagesmitteltemperatur und ein Viertel Anwesenheit zählen je etwa eine Einheit.
     */
    private function distance(array $a, array $b): float
    {
        $typeCost = [0 => [0 => 0.0, 1 => 3.0, 2 => 4.0], 1 => [0 => 3.0, 1 => 0.0, 2 => 2.0], 2 => [0 => 4.0, 1 => 2.0, 2 => 0.0]];
        $d = $typeCost[$a['type']][$b['type']];
        $d += abs($a['len'] - $b['len']) / 1.5;
        $d += ($a['temp'] !== null && $b['temp'] !== null) ? abs($a['temp'] - $b['temp']) / 5.0 : 0.5;
        $d += 4.0 * abs($a['pres'] - $b['pres']);
        return $d;
    }

    private function latitude(): float
    {
        $lat = $this->ReadPropertyFloat('Latitude');
        return ($lat == 0.0 && $this->ReadPropertyFloat('Longitude') == 0.0) ? 51.0 : $lat;
    }

    // ------------------------------------------------------------------
    //  Temperatur
    // ------------------------------------------------------------------

    /** Vorhersage-Tagesmittel je Offset: [temp|null, Quelle]. */
    private function forecastTemps(array $tempHist): array
    {
        $out = [];
        $src = $this->ReadPropertyInteger('TempSource');
        $weather = $src === 0 ? $this->weather() : [];
        for ($o = 0; $o < self::HORIZON; $o++) {
            $date = date('Y-m-d', strtotime('today +' . $o . ' days'));
            $t = null;
            $from = '';
            if ($src === 0 && isset($weather[$date])) {
                $t = (float)$weather[$date];
                $from = 'Open-Meteo';
            } elseif ($src === 1) {
                $vid = $this->ReadPropertyInteger(['TempFc0', 'TempFc1', 'TempFc2'][$o]);
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $t = (float)GetValue($vid);
                    $from = 'Variable';
                }
            }
            if ($t === null) {
                $t = $this->climatology(strtotime('today +' . $o . ' days'), $tempHist);
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
                continue;   // nur frühere Jahre
            }
            $diff = abs((int)date('z', $kts) - $doy);
            if (min($diff, 365 - $diff) <= 7) {
                $sum += $t;
                $n++;
            }
        }
        return $n >= 3 ? $sum / $n : null;
    }

    /** Open-Meteo: Tagesmittel der Temperatur für heute bis übermorgen, zwischengespeichert. Y-m-d => °C */
    private function weather(): array
    {
        $lat = $this->ReadPropertyFloat('Latitude');
        $lon = $this->ReadPropertyFloat('Longitude');
        if ($lat == 0.0 && $lon == 0.0) {
            $this->notes[] = '⚠️ Standort fehlt: ohne Breiten-/Längengrad keine Wettervorhersage (Mittel der Vorjahre wird genutzt)';
            return [];
        }
        $cache = json_decode($this->ReadAttributeString('Weather'), true);
        $cache = is_array($cache) ? $cache : [];
        $same = ($cache['lat'] ?? null) == $lat && ($cache['lon'] ?? null) == $lon;
        $age = time() - (int)($cache['fetched'] ?? 0);
        if ($same && $age < self::WEATHER_TTL && !empty($cache['days'])) {
            return $cache['days'];
        }
        $url = sprintf('https://api.open-meteo.com/v1/forecast?latitude=%.4F&longitude=%.4F&daily=temperature_2m_mean,temperature_2m_max,temperature_2m_min&timezone=auto&forecast_days=%d',
            $lat, $lon, self::HORIZON);
        $file = $this->runtimeDir('EnergiePrognose') . DIRECTORY_SEPARATOR . 'weather_' . $this->InstanceID . '.json';
        try {
            $this->downloadToFile($url, $file, 20);
            $data = json_decode((string)file_get_contents($file), true);
            @unlink($file);
            $days = [];
            $daily = is_array($data) ? ($data['daily'] ?? []) : [];
            foreach ((array)($daily['time'] ?? []) as $i => $date) {
                $mean = $daily['temperature_2m_mean'][$i] ?? null;
                if ($mean === null && isset($daily['temperature_2m_max'][$i], $daily['temperature_2m_min'][$i])) {
                    $mean = ($daily['temperature_2m_max'][$i] + $daily['temperature_2m_min'][$i]) / 2.0;
                }
                if ($mean !== null) {
                    $days[(string)$date] = round((float)$mean, 1);
                }
            }
            if (count($days) === 0) {
                throw new RuntimeException('Antwort ohne Tageswerte');
            }
            $this->WriteAttributeString('Weather', (string)json_encode(['lat' => $lat, 'lon' => $lon, 'fetched' => time(), 'days' => $days]));
            return $days;
        } catch (\Throwable $e) {
            $this->SendDebug('Wetter', $e->getMessage(), 0);
            if ($same && $age < self::WEATHER_MAX_AGE && !empty($cache['days'])) {
                $this->notes[] = '⚠️ Wetterabruf fehlgeschlagen, Vorhersage von vor ' . round($age / 3600) . ' h genutzt';
                return $cache['days'];
            }
            $this->notes[] = '⚠️ Wetterabruf fehlgeschlagen (' . $e->getMessage() . '), Mittel der Vorjahre genutzt';
            return [];
        }
    }

    // ------------------------------------------------------------------
    //  Prognose
    // ------------------------------------------------------------------

    /** Grundlast eines vergangenen Tages (96 W) oder null, wenn Daten fehlen. */
    private function baseProfile(int $key, array $heatSubtract): ?array
    {
        $base = $this->baseSeries();
        if ($this->ReadPropertyInteger('Source') === 1) {
            $sum = array_fill(0, EPSeries::QUARTERS, 0.0);
            foreach ($base as $s) {
                $p = $this->cacheFor($s)->get($key);
                if ($p === null) {
                    return null;
                }
                foreach ($p as $i => $w) {
                    $sum[$i] += max(0.0, $w);
                }
            }
            return $sum;
        }
        $main = $this->cacheFor($base[0])->get($key);
        if ($main === null) {
            return null;
        }
        foreach (array_merge($this->deductionSeries(), $heatSubtract, $this->wallboxSeries()) as $s) {
            $p = $this->cacheFor($s)->get($key);
            if ($p === null) {
                continue;   // Variable hatte an dem Tag keine Daten (z. B. noch nicht vorhanden)
            }
            foreach ($p as $i => $w) {
                $main[$i] -= $w;
            }
        }
        return array_map(function ($w) {
            return max(0.0, $w);
        }, $main);
    }

    /** Kann der Tag eine Grundlast liefern? (ohne die Profile zu entpacken) */
    private function baseAvailable(int $key): bool
    {
        foreach ($this->baseSeries() as $s) {
            if ($this->cacheFor($s)->status($key) !== 1) {
                return false;
            }
        }
        return true;
    }

    /** Grund- und Geräteprognose eines Tages in 96 Viertelstunden (ohne Wallbox, die kommt beim Veröffentlichen dazu). */
    private function computeBase(int $offset): array
    {
        if ($offset === 0 || $this->ctx === null) {
            $temps = $this->dailyMeans($this->ReadPropertyInteger('TempVar'));
            $presence = $this->dailyMeans($this->ReadPropertyInteger('PresenceVar'));
            $closures = $this->closureDays();
            $this->ctx = [
                'temps' => $temps, 'closures' => $closures,
                'hist' => $this->historyFeatures($temps, $presence, $closures),
                'fcTemps' => $this->forecastTemps($temps),
            ];
            $this->heatModelCache = null;
        }
        $ctx = $this->ctx;
        [$temp, $tempSrc] = $ctx['fcTemps'][$offset];
        $target = $this->targetFeatures($offset, $temp, $ctx['closures']);

        $models = $this->heatModels($ctx['temps']);
        $heatSubtract = [];
        if ($this->ReadPropertyBoolean('HeatInForecast')) {
            foreach ($this->heatSeries() as $i => $s) {
                if ($models[$i] !== null) {
                    $heatSubtract[] = $s;
                }
            }
        }

        // Kandidaten nach Abstand ordnen, Profile nur für die nächsten k gültigen Tage laden
        $cands = [];
        foreach ($ctx['hist'] as $key => $f) {
            if ($this->baseAvailable($key)) {
                $cands[$key] = $this->distance($target, $f);
            }
        }
        asort($cands);
        $k = max(1, min(60, $this->ReadPropertyInteger('Neighbors')));
        $profiles = [];
        $dists = [];
        foreach ($cands as $key => $dist) {
            $p = $this->baseProfile($key, $heatSubtract);
            if ($p === null) {
                continue;
            }
            $profiles[] = $p;
            $dists[] = $dist;
            if (count($profiles) >= $k) {
                break;
            }
        }

        $zero = array_fill(0, EPSeries::QUARTERS, 0.0);
        $base = ['p10' => $zero, 'p50' => $zero, 'p90' => $zero, 'mean' => $zero];
        if (count($profiles) > 0) {
            $w = EPMath::kernelWeights($dists);
            $wsum = array_sum($w);
            for ($q = 0; $q < EPSeries::QUARTERS; $q++) {
                $vals = array_column($profiles, $q);
                $base['p10'][$q] = EPMath::weightedQuantile($vals, $w, 0.1);
                $base['p50'][$q] = EPMath::weightedQuantile($vals, $w, 0.5);
                $base['p90'][$q] = EPMath::weightedQuantile($vals, $w, 0.9);
                $m = 0.0;
                foreach ($vals as $i => $v) {
                    $m += $v * $w[$i];
                }
                $base['mean'][$q] = $wsum > 0 ? $m / $wsum : 0.0;
            }
        } else {
            $this->notes[] = '⚠️ ' . date('d.m.', $target['ts']) . ': keine Vergleichstage mit vollständigen Daten gefunden';
        }

        $heat = null;
        if ($this->ReadPropertyBoolean('HeatInForecast') && count($heatSubtract) > 0) {
            $heat = $this->heatForecast($models, $temp, $ctx['temps']);
        }

        return [
            'date' => date('Y-m-d', $target['ts']), 'neighbors' => count($profiles), 'temperature' => $temp,
            'temperatureSource' => $tempSrc, 'base' => $this->roundProfiles($base), 'heat' => $heat, 'generated' => time(),
        ];
    }

    private function roundProfiles(array $p): array
    {
        foreach ($p as $k => $arr) {
            if (is_array($arr)) {
                $p[$k] = array_map(function ($v) {
                    return round((float)$v, 1);
                }, $arr);
            }
        }
        return $p;
    }

    /**
     * Regression je Wärmegerät: Tagesenergie (kWh) ~ a + b·Heizgrad (+ c·Kühlgrad). Dazu die Streuung Ist/Modell
     * (Quantile 10/50/90) für das Unsicherheitsband. null bei zu wenig Daten.
     *
     * @return array<int, array|null>
     */
    private function heatModels(array $temps): array
    {
        if ($this->heatModelCache !== null) {
            return $this->heatModelCache;
        }
        $hl = $this->ReadPropertyFloat('HeatLimit');
        $cl = $this->ReadPropertyFloat('CoolLimit');
        $out = [];
        foreach ($this->heatSeries() as $i => $s) {
            $cache = $this->cacheFor($s);
            $X = [];
            $y = [];
            $days = [];
            foreach ($temps as $key => $t) {
                $mean = $cache->dayMeanW((int)$key);
                if ($mean === null) {
                    continue;
                }
                $kwh = $mean * 24.0 / 1000.0;
                $X[] = $this->heatRow($s['mode'], $t, $hl, $cl);
                $y[] = $kwh;
                $days[] = ['key' => (int)$key, 't' => $t, 'kwh' => $kwh];
            }
            $need = $s['mode'] === 2 ? 20 : 10;
            $coef = count($X) >= $need ? EPMath::leastSquares($X, $y) : null;
            if ($coef === null) {
                $out[$i] = null;
                $this->notes[] = '⚠️ ' . $s['name'] . ': zu wenig Tage mit Verbrauch und Außentemperatur (' . count($X) . ', nötig ' . $need . ') – nicht in der Prognose';
                continue;
            }
            $ratios = [];
            foreach ($X as $r => $row) {
                $pred = $this->dot($coef, $row);
                if ($pred >= 1.0) {
                    $ratios[] = $y[$r] / $pred;
                }
            }
            $clamp = function (float $v): float {
                return max(0.3, min(3.0, $v));
            };
            $band = count($ratios) >= 15
                ? [$clamp(EPMath::quantile($ratios, 0.1)), $clamp(EPMath::quantile($ratios, 0.5)), $clamp(EPMath::quantile($ratios, 0.9))]
                : [0.75, 1.0, 1.3];
            $out[$i] = ['coef' => $coef, 'band' => $band, 'days' => $days, 'mode' => $s['mode'], 'series' => $s];
        }
        return $this->heatModelCache = $out;
    }

    private function heatRow(int $mode, float $t, float $heatLimit, float $coolLimit): array
    {
        $hdd = max(0.0, $heatLimit - $t);
        $cdd = max(0.0, $t - $coolLimit);
        if ($mode === 1) {
            return [1.0, $cdd];
        }
        return $mode === 2 ? [1.0, $hdd, $cdd] : [1.0, $hdd];
    }

    private function dot(array $a, array $b): float
    {
        $s = 0.0;
        foreach ($a as $i => $v) {
            $s += $v * ($b[$i] ?? 0.0);
        }
        return $s;
    }

    /** Geräteanteil eines Tages: Tagesenergie aus der Regression, Verlauf aus den 7 Tagen mit ähnlichster Temperatur. */
    private function heatForecast(array $models, ?float $temp, array $temps): ?array
    {
        if ($temp === null) {
            $this->notes[] = '⚠️ Wärmepumpe/Klima: keine Temperatur für die Vorhersage – Geräteanteil fehlt';
            return null;
        }
        $hl = $this->ReadPropertyFloat('HeatLimit');
        $cl = $this->ReadPropertyFloat('CoolLimit');
        $zero = array_fill(0, EPSeries::QUARTERS, 0.0);
        $out = ['p10' => $zero, 'p50' => $zero, 'p90' => $zero, 'mean' => $zero, 'kwh' => 0.0];
        foreach ($models as $m) {
            if ($m === null) {
                continue;
            }
            $kwh = max(0.0, $this->dot($m['coef'], $this->heatRow($m['mode'], $temp, $hl, $cl)));
            $shape = $this->heatShape($m, $temp);
            $sumW = $kwh * 1000.0 * 4.0;   // Summe der Viertelstunden-Leistungen, die genau $kwh ergibt
            for ($q = 0; $q < EPSeries::QUARTERS; $q++) {
                $v = $sumW * $shape[$q];
                $out['mean'][$q] += $v;
                $out['p10'][$q] += $v * $m['band'][0];
                $out['p50'][$q] += $v * $m['band'][1];
                $out['p90'][$q] += $v * $m['band'][2];
            }
            $out['kwh'] += $kwh;
        }
        $out = $this->roundProfiles($out);
        $out['kwh'] = round($out['kwh'], 2);
        return $out;
    }

    /** Normierter Tagesverlauf (Summe 1) eines Geräts bei einer Temperatur. */
    private function heatShape(array $model, float $temp): array
    {
        $days = array_values(array_filter($model['days'], function ($d) {
            return $d['kwh'] >= 0.2;
        }));
        usort($days, function ($a, $b) use ($temp) {
            return abs($a['t'] - $temp) <=> abs($b['t'] - $temp);
        });
        $shape = array_fill(0, EPSeries::QUARTERS, 0.0);
        $cache = $this->cacheFor($model['series']);
        $used = 0;
        foreach (array_slice($days, 0, 7) as $d) {
            $p = $cache->get($d['key']);
            $sum = $p === null ? 0.0 : array_sum(array_map(function ($v) {
                return max(0.0, $v);
            }, $p));
            if ($sum <= 0) {
                continue;
            }
            foreach ($p as $q => $v) {
                $shape[$q] += max(0.0, $v) / $sum;
            }
            $used++;
        }
        $tot = array_sum($shape);
        if ($used === 0 || $tot <= 0) {
            return array_fill(0, EPSeries::QUARTERS, 1.0 / EPSeries::QUARTERS);
        }
        return array_map(function ($v) use ($tot) {
            return $v / $tot;
        }, $shape);
    }

    // ------------------------------------------------------------------
    //  Veröffentlichen (Grundlast + Geräte + Wallbox)
    // ------------------------------------------------------------------

    /** Gespeicherte Tagesprognosen + aktuelle Sofort-Ladevorgänge in die Variablen schreiben. */
    private function publish(): void
    {
        $stored = json_decode($this->ReadAttributeString('BaseForecasts'), true);
        $byDate = [];
        foreach (is_array($stored) ? $stored : [] as $b) {
            if (is_array($b) && isset($b['date'])) {
                $byDate[$b['date']] = $b;
            }
        }
        $res = $this->ReadPropertyInteger('Resolution');
        $res = in_array($res, [15, 30, 60], true) ? $res : 15;
        $now = time();
        $demand = 0.0;
        foreach ($this->GetWallboxDemand() as $wd) {
            if ($wd['mode'] === 'steuerbar') {
                $demand += $wd['needKwh'];
            }
        }
        $this->SetValue('WallboxDemand', round($demand, 2));

        for ($o = 0; $o < self::HORIZON; $o++) {
            $dayStart = strtotime('today +' . $o . ' days');
            $date = date('Y-m-d', $dayStart);
            $b = $byDate[$date] ?? null;
            if ($b === null) {
                $this->SetValue(self::IDENT_FC[$o], (string)json_encode(['contractVersion' => self::CONTRACT, 'date' => $date, 'generated' => 0]));
                $this->SetValue(self::IDENT_KWH[$o], 0.0);
                $this->SetValue(self::IDENT_HEAT[$o], 0.0);
                continue;
            }
            $wb = $this->wallboxOverlay($dayStart, strtotime('tomorrow', $dayStart), $now);
            $parts = ['p10' => [], 'p50' => [], 'p90' => [], 'mean' => []];
            $heat = $b['heat'];
            foreach (array_keys($parts) as $k) {
                for ($q = 0; $q < EPSeries::QUARTERS; $q++) {
                    $parts[$k][$q] = $b['base'][$k][$q] + ($heat !== null ? $heat[$k][$q] : 0.0) + $wb[$q];
                }
            }
            $r = function (array $q96) use ($res) {
                return array_map(function ($v) {
                    return round($v);
                }, EPSeries::resample($q96, $res));
            };
            $fc = [
                'contractVersion' => self::CONTRACT, 'date' => $date, 'slots' => intdiv(1440, $res), 'resolution' => $res . 'min', 'unit' => 'W',
                'p10' => $r($parts['p10']), 'p50' => $r($parts['p50']), 'p90' => $r($parts['p90']), 'mean' => $r($parts['mean']),
                'kwh' => round(EPSeries::kwh($parts['mean']), 2),
                'kwhP10' => round(EPSeries::kwh($parts['p10']), 2), 'kwhP90' => round(EPSeries::kwh($parts['p90']), 2),
                'kwhBase' => round(EPSeries::kwh($b['base']['mean']), 2),
                'kwhHeat' => $heat !== null ? round((float)$heat['kwh'], 2) : 0.0,
                'kwhWallbox' => round(EPSeries::kwh($wb), 2),
                'base' => $r($b['base']['mean']), 'heat' => $heat !== null ? $r($heat['mean']) : $r(array_fill(0, 96, 0.0)), 'wallbox' => $r($wb),
                'neighbors' => (int)$b['neighbors'], 'temperature' => $b['temperature'], 'temperatureSource' => $b['temperatureSource'],
                'generated' => (int)$b['generated'],
            ];
            $this->SetValue(self::IDENT_FC[$o], (string)json_encode($fc));
            $this->SetValue(self::IDENT_KWH[$o], $fc['kwh']);
            $this->SetValue(self::IDENT_HEAT[$o], $fc['kwhHeat']);
        }
    }

    /** Leistung der laufenden Sofort-Ladevorgänge je Viertelstunde eines Tages (W). */
    private function wallboxOverlay(int $dayStart, int $dayEnd, int $now): array
    {
        $sum = array_fill(0, EPSeries::QUARTERS, 0.0);
        $list = $this->wallboxes();
        foreach ($this->sessions() as $nr => $s) {
            $wb = $list[$nr] ?? null;
            if ($wb === null || $wb['chargeKw'] <= 0) {
                continue;
            }
            [$from, $to] = EPWallbox::block((int)$s['start'], $now, $this->remainingKwh($wb, $s, $now), $wb['chargeKw']);
            $ov = EPWallbox::dayOverlay($dayStart, $dayEnd, $from, $to, $wb['chargeKw'] * 1000.0);
            foreach ($ov as $q => $v) {
                $sum[$q] += $v;
            }
        }
        return $sum;
    }

    // ------------------------------------------------------------------
    //  Wallbox-Zustand
    // ------------------------------------------------------------------

    /** @return array<int, array> Nummer => [start, needKwh, soc, socSource, trigger] */
    private function sessions(): array
    {
        $s = json_decode($this->ReadAttributeString('Sessions'), true);
        $out = [];
        foreach (is_array($s) ? $s : [] as $nr => $v) {
            if (is_array($v) && isset($v['start'])) {
                $out[(int)$nr] = $v;
            }
        }
        return $out;
    }

    /** [SoC in %, Quelle 'Variable'|'angenommen'] */
    private function socNow(array $wb): array
    {
        if ($wb['socVar'] > 0 && IPS_VariableExists($wb['socVar'])) {
            $v = (float)GetValue($wb['socVar']);
            $fraction = $this->profileSuffix($wb['socVar']) !== '%' && $v <= 1.0;
            return [EPWallbox::normalizeSoc($v, $fraction), 'Variable'];
        }
        return [$wb['startSoc'], 'angenommen'];
    }

    /** true/false aus der Statusvariable „angesteckt“, null ohne Variable. */
    private function plugged(array $wb): ?bool
    {
        if ($wb['plugVar'] > 0 && IPS_VariableExists($wb['plugVar'])) {
            return (bool)GetValue($wb['plugVar']);
        }
        return null;
    }

    /** Restbedarf (kWh) eines laufenden Ladevorgangs: aus dem SoC, sonst Startbedarf minus geladene Zeit × Leistung. */
    private function remainingKwh(array $wb, array $session, int $now): float
    {
        if ($wb['socVar'] > 0 && IPS_VariableExists($wb['socVar'])) {
            [$soc] = $this->socNow($wb);
            return EPWallbox::needKwh($wb['capacity'], $soc, $wb['target'], self::EFFICIENCY);
        }
        $elapsedH = max(0, $now - (int)$session['start']) / 3600.0;
        return max(0.0, (float)$session['needKwh'] - $elapsedH * $wb['chargeKw']);
    }

    private function startSession(int $nr, string $trigger): string
    {
        $wb = $this->wallboxes()[$nr] ?? null;
        if ($wb === null) {
            return '⚠️ Wallbox Nr. ' . $nr . ' gibt es nicht.';
        }
        if ($wb['capacity'] <= 0 || $wb['chargeKw'] <= 0) {
            return '⚠️ ' . $wb['name'] . ': Akkugröße und Ladeleistung eintragen.';
        }
        [$soc, $src] = $this->socNow($wb);
        $need = EPWallbox::needKwh($wb['capacity'], $soc, $wb['target'], self::EFFICIENCY);
        if ($need <= 0.05) {
            return 'ℹ️ ' . $wb['name'] . ': SoC ' . round($soc) . ' % hat das Ziel ' . round($wb['target']) . ' % schon erreicht.';
        }
        $sessions = $this->sessions();
        $sessions[$nr] = ['start' => time(), 'needKwh' => round($need, 2), 'soc' => $soc, 'socSource' => $src, 'trigger' => $trigger];
        $this->WriteAttributeString('Sessions', (string)json_encode((object)$sessions));
        $this->SetTimerInterval('WallboxTimer', self::SESSION_TIMER_MS);
        $this->publish();
        $end = time() + EPWallbox::durationSec($need, $wb['chargeKw']);
        $this->SendDebug('Wallbox', $wb['name'] . ': Sofort-Laden (' . $trigger . '), ' . round($need, 1) . ' kWh', 0);
        return sprintf('✅ %s: %.1f kWh ab jetzt eingeplant (SoC %d %% %s → %d %%), voraussichtlich fertig um %s.',
            $wb['name'], $need, round($soc), $src === 'Variable' ? 'gemessen' : 'angenommen', round($wb['target']), date('H:i', $end));
    }

    private function endSession(int $nr): void
    {
        $sessions = $this->sessions();
        unset($sessions[$nr]);
        $this->WriteAttributeString('Sessions', (string)json_encode((object)$sessions));
        $this->SetTimerInterval('WallboxTimer', count($sessions) > 0 ? self::SESSION_TIMER_MS : 0);
        $this->publish();
    }

    // ------------------------------------------------------------------
    //  Formular-Infozeilen
    // ------------------------------------------------------------------

    private function sourcesInfo(): string
    {
        $aid = $this->archiveID();
        if ($aid === 0) {
            return '⛔ Kein Archiv (Archive Control) gefunden.';
        }
        $types = ['Leistung', 'Energie je Intervall', 'Zählerstand'];
        $roles = ['main' => 'Hauptzähler', 'consumer' => 'Verbraucher', 'deduction' => 'Abzug', 'heat' => 'Wärmepumpe/Klima', 'wallbox' => 'Wallbox'];
        $lines = [];
        foreach ($this->allSeries() as $s) {
            $vid = $s['var'];
            if (!IPS_VariableExists($vid)) {
                $lines[] = '⛔ ' . $roles[$s['role']] . ' „' . $s['name'] . '“: Variable #' . $vid . ' existiert nicht';
                continue;
            }
            $u = $this->unitInfo($s);
            $logged = (bool)@AC_GetLoggingStatus($aid, $vid);
            $line = ($logged ? '✅ ' : '⛔ ') . $roles[$s['role']] . ' „' . $s['name'] . '“ (#' . $vid . '): ' . $types[$s['type']]
                . ' in ' . $u['label'] . ' (' . $u['source'] . ')';
            if ($s['type'] === EPSeries::TYPE_COUNTER) {
                $line .= (int)@AC_GetAggregationType($aid, $vid) === 1 ? ', Aggregation „Zähler“' : ', Aggregation „Standard“ (Rohwerte werden gelesen; „Zähler“ wäre schneller)';
            }
            if (!$logged) {
                $line .= ' – nicht archiviert';
            }
            $mm = $this->unitMismatch($s);
            if ($mm !== '') {
                $line .= ' – ⚠️ ' . $mm;
            } elseif ($u['source'] === 'angenommen') {
                $line .= ' – ⚠️ Einheit bitte prüfen bzw. selbst wählen';
            }
            $lines[] = $line;
        }
        return count($lines) > 0 ? implode("\n", $lines) : 'ℹ️ Noch keine Variable gewählt.';
    }

    private function locationInfo(): string
    {
        if ($this->ReadPropertyFloat('Latitude') == 0.0 && $this->ReadPropertyFloat('Longitude') == 0.0) {
            return '⚠️ Standort der Anlage eintragen: wird für die Wettervorhersage (Open-Meteo) und die Tageslänge gebraucht.';
        }
        return sprintf('✅ Standort %.4F / %.4F', $this->ReadPropertyFloat('Latitude'), $this->ReadPropertyFloat('Longitude'));
    }

    private function lastInfo(): string
    {
        $i = json_decode($this->ReadAttributeString('LastInfo'), true);
        if (!is_array($i) || empty($i['time'])) {
            return 'ℹ️ Noch keine Berechnung.';
        }
        return 'Letzte Berechnung ' . date('d.m.Y H:i', (int)$i['time']) . ' (' . $i['sec'] . ' s, ' . (int)$i['neighbors'] . ' Vergleichstage für heute)' . "\n" . $i['status'];
    }

    // ------------------------------------------------------------------
    //  Hilfen
    // ------------------------------------------------------------------

    private function msToMidnight(): int
    {
        // 00:15 Uhr: dann steht auch die letzte Viertelstunde des Vortags sicher im Archiv
        return max(60000, (strtotime('tomorrow') + 900 - time()) * 1000);
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
}
