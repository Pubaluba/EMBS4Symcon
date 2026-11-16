<?php

// Bewusst KEIN declare(strict_types=1) (docs/RULES.md, P001).

require_once __DIR__ . '/../libs/EPHelper.php';
require_once __DIR__ . '/../libs/EPSchedule.php';

/**
 * Kachel für die Kachel-Visualisierung: Prognosen (Verbrauch, PV, Wind, BHKW) farbig für heute bis übermorgen,
 * darunter Strompreise, Zukaufplan und beliebig viele Fahrpläne (Anforderung, Normalbetrieb, Sperre).
 *
 * Datenquellen der Fahrpläne/Preise (vorbereitet für ein EMS):
 *  - String-Variablen mit JSON (im Formular wählbar; Änderungen werden sofort angezeigt), oder
 *  - Funktionen EPK_SetSchedule / EPK_SetPrices / EPK_SetPurchasePlan (z. B. aus einem EMS-Skript).
 * Formate siehe libs/EPSchedule.php und README.
 */
class Energiekachel extends IPSModuleStrict
{
    use EPHelper;

    /** Schlüssel => [Modulordner, Property] */
    private const SOURCES = [
        'cons' => ['Verbrauchsprognose', 'ConsumptionID'],
        'pv'   => ['Solarprognose', 'SolarID'],
        'wind' => ['Windprognose', 'WindID'],
        'bhkw' => ['BHKWPrognose', 'BHKWID'],
    ];

    /** Farb-Property => [Schlüssel in der Kachel, Standard] */
    private const COLORS = [
        'ColorPV' => ['pv', 0xF1C40F], 'ColorWind' => ['wind', 0x3498DB], 'ColorBHKW' => ['bhkw', 0xE67E22],
        'ColorBase' => ['base', 0xC0392B], 'ColorHeat' => ['heat', 0x8E44AD], 'ColorWallbox' => ['wallbox', 0x16A085],
        'ColorNet' => ['net', 0x7F8C8D], 'ColorBandGen' => ['bandGen', 0xB7950B], 'ColorBandCons' => ['bandCons', 0x922B21],
        'ColorRequest' => ['request', 0x2E86DE], 'ColorNormal' => ['normal', 0x95A5A6], 'ColorLock' => ['lock', 0xC0392B],
        'ColorPurchase' => ['purchase', 0x27AE60], 'ColorCheap' => ['cheap', 0x27AE60], 'ColorExpensive' => ['expensive', 0xE74C3C],
    ];

    public function Create(): void
    {
        // Diese Zeile nicht löschen
        parent::Create();

        $this->RegisterPropertyInteger('ConsumptionID', 0);
        $this->RegisterPropertyInteger('SolarID', 0);
        $this->RegisterPropertyInteger('WindID', 0);
        $this->RegisterPropertyInteger('BHKWID', 0);
        $this->RegisterPropertyBoolean('ShowBand', true);
        $this->RegisterPropertyBoolean('ShowNet', true);
        $this->RegisterPropertyInteger('DefaultDay', 0);
        $this->RegisterPropertyInteger('ChartHeight', 260);
        $this->RegisterPropertyInteger('UpdateMinutes', 5);
        $this->RegisterPropertyBoolean('HtmlBox', false);
        // Farben (Standardwerte wie in self::COLORS)
        $this->RegisterPropertyInteger('ColorPV', 0xF1C40F);
        $this->RegisterPropertyInteger('ColorWind', 0x3498DB);
        $this->RegisterPropertyInteger('ColorBHKW', 0xE67E22);
        $this->RegisterPropertyInteger('ColorBase', 0xC0392B);
        $this->RegisterPropertyInteger('ColorHeat', 0x8E44AD);
        $this->RegisterPropertyInteger('ColorWallbox', 0x16A085);
        $this->RegisterPropertyInteger('ColorNet', 0x7F8C8D);
        $this->RegisterPropertyInteger('ColorBandGen', 0xB7950B);
        $this->RegisterPropertyInteger('ColorBandCons', 0x922B21);
        $this->RegisterPropertyInteger('ColorRequest', 0x2E86DE);
        $this->RegisterPropertyInteger('ColorNormal', 0x95A5A6);
        $this->RegisterPropertyInteger('ColorLock', 0xC0392B);
        $this->RegisterPropertyInteger('ColorPurchase', 0x27AE60);
        $this->RegisterPropertyInteger('ColorCheap', 0x27AE60);
        $this->RegisterPropertyInteger('ColorExpensive', 0xE74C3C);
        $this->RegisterPropertyString('ScheduleVars', '[]');
        $this->RegisterPropertyInteger('PriceVar', 0);
        $this->RegisterPropertyInteger('PriceUnit', EPSchedule::UNIT_AUTO);
        $this->RegisterPropertyInteger('CheapHours', 0);
        $this->RegisterPropertyInteger('PurchaseVar', 0);

        $this->RegisterAttributeString('Schedules', '{}');
        $this->RegisterAttributeString('Prices', '[]');
        $this->RegisterAttributeString('Purchase', '[]');

        $this->RegisterTimer('UpdateTimer', 0, 'EPK_Update($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);   // INSTANCE_VISUALIZATION_TYPE_HTML (HTML-SDK)
    }

    public function ApplyChanges(): void
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        if ($this->ReadPropertyBoolean('HtmlBox')) {
            $this->RegisterVariableString('HTML', 'Energie-Kachel (HTML-Box)', '~HTMLBox', 1);
        }

        // Bei Änderungen der Quellen sofort neu zeichnen
        foreach ($this->GetMessageList() as $sender => $messages) {
            if ((int)$sender !== 0 && in_array(VM_UPDATE, (array)$messages, true)) {
                $this->UnregisterMessage((int)$sender, VM_UPDATE);
            }
        }
        foreach ($this->watchedVariables() as $vid) {
            $this->RegisterMessage($vid, VM_UPDATE);
        }
        $this->SetStatus(count($this->foundSources()) > 0 || count($this->scheduleVars()) > 0 ? 102 : 201);
        $this->SetTimerInterval('UpdateTimer', 3000);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        } elseif ($Message === VM_UPDATE) {
            // mehrere Änderungen kurz hintereinander zusammenfassen
            $this->SetTimerInterval('UpdateTimer', 2000);
        }
    }

    public function GetVisualizationTile(): string
    {
        return $this->html($this->payload());
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'Refresh') {
            $this->Update();
            return;
        }
        throw new Exception('Unbekannter Ident: ' . $Ident);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string)file_get_contents(__DIR__ . '/form.json'), true);
        $lines = [];
        foreach (self::SOURCES as $key => [$folder]) {
            $id = $this->instanceFor($key);
            $how = $this->ReadPropertyInteger(self::SOURCES[$key][1]) > 0 ? 'gewählt' : 'automatisch';
            $lines[] = $id > 0 ? '✅ ' . $folder . ': #' . $id . ' „' . IPS_GetName($id) . '“ (' . $how . ')' : 'ℹ️ ' . $folder . ': keine Instanz';
        }
        $this->patch($form['elements'], 'InfoSources', implode("\n", $lines));
        return (string)json_encode($form);
    }

    // ---------------------------------------------------------------- öffentlich

    /** Kachel (und ggf. HTML-Box) mit den aktuellen Daten versorgen. */
    public function Update(): void
    {
        $this->SetTimerInterval('UpdateTimer', max(1, $this->ReadPropertyInteger('UpdateMinutes')) * 60000);
        $payload = $this->payload();
        $json = (string)json_encode($payload);
        $this->UpdateVisualizationValue($json);
        if ($this->ReadPropertyBoolean('HtmlBox') && @$this->GetIDForIdent('HTML')) {
            $this->SetValue('HTML', $this->html($payload));
        }
        $this->SendDebug('Update', strlen($json) . ' Byte, ' . count($payload['schedules']) . ' Fahrpläne, ' . count($payload['prices']) . ' Preise', 0);
    }

    /**
     * Fahrplan setzen oder ersetzen. $Entries = JSON-Liste der Einträge (oder ganzes Fahrplan-Objekt).
     * Leere Liste entfernt den Fahrplan. Rückgabe: Anzahl gültiger Einträge.
     */
    public function SetSchedule(string $Id, string $Name, string $Entries): int
    {
        $id = trim($Id) !== '' ? trim($Id) : 'fahrplan';
        $s = EPSchedule::normalizeSchedule($Entries, $id, trim($Name) !== '' ? trim($Name) : $id);
        if ($s === null) {
            trigger_error('EPK_SetSchedule: kein gültiges JSON', E_USER_WARNING);
            return -1;
        }
        $all = $this->pushedSchedules();
        if (count($s['entries']) === 0) {
            unset($all[$id]);
        } else {
            $s['id'] = $id;
            $all[$id] = $s;
        }
        $this->WriteAttributeString('Schedules', (string)json_encode((object)$all));
        $this->Update();
        return count($s['entries']);
    }

    public function RemoveSchedule(string $Id): bool
    {
        $all = $this->pushedSchedules();
        if (!isset($all[$Id])) {
            return false;
        }
        unset($all[$Id]);
        $this->WriteAttributeString('Schedules', (string)json_encode((object)$all));
        $this->Update();
        return true;
    }

    /** Strompreise setzen (JSON, Formate siehe README). Rückgabe: Anzahl Preisabschnitte. */
    public function SetPrices(string $Json): int
    {
        $p = EPSchedule::normalizePrices($Json, $this->ReadPropertyInteger('PriceUnit'));
        $this->WriteAttributeString('Prices', (string)json_encode($p));
        $this->Update();
        return count($p);
    }

    /** Zukaufplan setzen (JSON-Liste mit from, to, kw/kwh, label). Rückgabe: Anzahl Einträge. */
    public function SetPurchasePlan(string $Json): int
    {
        $data = json_decode($Json, true);
        $e = EPSchedule::normalizeEntries(is_array($data) ? $data : [], 'purchase');
        $this->WriteAttributeString('Purchase', (string)json_encode($e));
        $this->Update();
        return count($e);
    }

    /** Alle per Funktion gesetzten Fahrpläne, Preise und Zukaufpläne löschen. */
    public function ClearPushed(): string
    {
        $this->WriteAttributeString('Schedules', '{}');
        $this->WriteAttributeString('Prices', '[]');
        $this->WriteAttributeString('Purchase', '[]');
        $this->Update();
        return '✅ Per Funktion gesetzte Fahrpläne, Preise und Zukaufpläne gelöscht.';
    }

    /** Beispieldaten einspielen, um Fahrplan-, Preis- und Zukaufzeilen ohne EMS anzusehen. */
    public function LoadDemo(): string
    {
        $t = strtotime('today');
        $wp = [];
        $wb = [];
        for ($d = 0; $d < 3; $d++) {
            $b = $t + $d * 86400;
            $wp[] = ['from' => $b + 6 * 3600, 'to' => $b + 8 * 3600, 'type' => 'request', 'label' => 'Vorheizen', 'kw' => 3.0];
            $wp[] = ['from' => $b + 8 * 3600, 'to' => $b + 11 * 3600, 'type' => 'normal'];
            $wp[] = ['from' => $b + 11 * 3600, 'to' => $b + 13 * 3600, 'type' => 'lock', 'label' => 'EVU-Sperre'];
            $wp[] = ['from' => $b + 13 * 3600, 'to' => $b + 22 * 3600, 'type' => 'normal'];
            $wb[] = ['from' => $b + 12 * 3600, 'to' => $b + 15 * 3600, 'type' => 'request', 'label' => 'PV-Überschuss laden', 'kw' => 7.4];
            $wb[] = ['from' => $b + 17 * 3600, 'to' => $b + 20 * 3600, 'type' => 'lock', 'label' => '§14a-Dimmung', 'kw' => 4.2];
        }
        $all = $this->pushedSchedules();
        $all['demo_wp'] = EPSchedule::normalizeSchedule($wp, 'demo_wp', 'Wärmepumpe (Beispiel)');
        $all['demo_wb'] = EPSchedule::normalizeSchedule($wb, 'demo_wb', 'Wallbox (Beispiel)');
        $this->WriteAttributeString('Schedules', (string)json_encode((object)$all));
        $prices = [];
        for ($h = 0; $h < 48; $h++) {
            $hour = $h % 24;
            $ct = 26 + 8 * sin(($hour - 13) / 24 * 2 * M_PI + M_PI / 2) - ($hour >= 11 && $hour <= 15 ? 7 : 0) + ($h >= 24 ? 1.5 : 0);
            $prices[] = ['from' => $t + $h * 3600, 'to' => $t + ($h + 1) * 3600, 'price' => round($ct, 2)];
        }
        $this->WriteAttributeString('Prices', (string)json_encode($prices));
        $cheap = EPSchedule::cheapest($prices, $t, $t + 2 * 86400, 3);
        $purchase = [];
        foreach ($cheap as $from) {
            $purchase[] = ['from' => $from, 'to' => $from + 3600, 'type' => 'purchase', 'label' => 'Speicher laden', 'kw' => 5.0, 'kwh' => 5.0];
        }
        $this->WriteAttributeString('Purchase', (string)json_encode(EPSchedule::normalizeEntries($purchase, 'purchase')));
        $this->Update();
        return '✅ Beispieldaten eingespielt (2 Fahrpläne, Preise für heute/morgen, Zukauf in den 3 günstigsten Stunden). Entfernen mit „Gesetzte Daten löschen“.';
    }

    /** Die Daten, die die Kachel bekommt (z. B. zum Prüfen per Skript). */
    public function GetPayload(): array
    {
        return $this->payload();
    }

    // ---------------------------------------------------------------- intern

    private function moduleInfo(string $folder): ?array
    {
        $file = __DIR__ . '/../' . $folder . '/module.json';
        $d = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        return is_array($d) && isset($d['id'], $d['prefix']) ? $d : null;
    }

    /** Gewählte Instanz oder automatisch die erste Instanz des Moduls. */
    private function instanceFor(string $key): int
    {
        [$folder, $prop] = self::SOURCES[$key];
        $id = $this->ReadPropertyInteger($prop);
        if ($id > 0) {
            return IPS_InstanceExists($id) ? $id : 0;
        }
        $info = $this->moduleInfo($folder);
        if ($info === null) {
            return 0;
        }
        $ids = IPS_GetInstanceListByModuleID($info['id']);
        return count($ids) > 0 ? (int)$ids[0] : 0;
    }

    /** @return array<string, int> Schlüssel => Instanz */
    private function foundSources(): array
    {
        $out = [];
        foreach (array_keys(self::SOURCES) as $key) {
            $id = $this->instanceFor($key);
            if ($id > 0) {
                $out[$key] = $id;
            }
        }
        return $out;
    }

    private function scheduleVars(): array
    {
        $rows = json_decode($this->ReadPropertyString('ScheduleVars'), true);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $i => $row) {
            $vid = (int)($row['VariableID'] ?? 0);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $name = trim((string)($row['Name'] ?? ''));
                $out[] = ['var' => $vid, 'name' => $name !== '' ? $name : IPS_GetName($vid), 'id' => 'var' . $vid];
            }
        }
        return $out;
    }

    /** Variablen, deren Änderung ein Neuzeichnen auslöst. */
    private function watchedVariables(): array
    {
        $out = array_column($this->scheduleVars(), 'var');
        foreach (['PriceVar', 'PurchaseVar'] as $p) {
            $vid = $this->ReadPropertyInteger($p);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $out[] = $vid;
            }
        }
        foreach ($this->foundSources() as $iid) {
            $vid = @IPS_GetObjectIDByIdent('FcToday', $iid);
            if (is_int($vid) && $vid > 0) {
                $out[] = $vid;
            }
        }
        return array_values(array_unique($out));
    }

    private function pushedSchedules(): array
    {
        $a = json_decode($this->ReadAttributeString('Schedules'), true);
        return is_array($a) ? array_filter($a, 'is_array') : [];
    }

    private function color(string $prop): string
    {
        $c = $this->ReadPropertyInteger($prop);
        if ($c < 0) {
            $c = self::COLORS[$prop][1];
        }
        return sprintf('#%06X', $c & 0xFFFFFF);
    }

    /** Tagesprognose einer Quelle über die öffentliche Funktion des Moduls (<Präfix>_GetForecast). */
    private function forecast(string $key, int $iid, int $offset): ?array
    {
        $info = $this->moduleInfo(self::SOURCES[$key][0]);
        if ($info === null) {
            return null;
        }
        $fn = $info['prefix'] . '_GetForecast';
        if (!function_exists($fn)) {
            return null;
        }
        try {
            $fc = $fn($iid, $offset);
        } catch (\Throwable $e) {
            $this->SendDebug('Quelle', $fn . ': ' . $e->getMessage(), 0);
            return null;
        }
        return is_array($fc) && isset($fc['mean'], $fc['p10'], $fc['p90']) ? $fc : null;
    }

    private function payload(): array
    {
        $today = strtotime('today');
        $winEnd = strtotime('+3 days', $today);
        $labels = ['Heute', 'Morgen', 'Übermorgen'];
        $sources = $this->foundSources();
        $days = [];
        for ($o = 0; $o < 3; $o++) {
            $start = strtotime('+' . $o . ' days', $today);
            $series = [];
            foreach ($sources as $key => $iid) {
                $fc = $this->forecast($key, $iid, $o);
                if ($fc === null) {
                    continue;
                }
                $q = function (string $field) use ($fc) {
                    return EPSchedule::toQuarters((array)($fc[$field] ?? []));
                };
                if ($key === 'cons') {
                    $heat = $q('heat');
                    $wb = $q('wallbox');
                    $base = isset($fc['base']) ? $q('base') : array_map(function ($m, $h, $w) {
                        return max(0.0, $m - $h - $w);
                    }, $q('mean'), $heat, $wb);
                    $series['cons'] = ['base' => $base, 'heat' => $heat, 'wallbox' => $wb, 'p10' => $q('p10'), 'p90' => $q('p90'),
                                       'hasHeat' => array_sum($heat) > 0, 'hasWallbox' => array_sum($wb) > 0, 'kwh' => (float)($fc['kwh'] ?? 0)];
                } else {
                    $series[$key] = ['mean' => $q('mean'), 'p10' => $q('p10'), 'p90' => $q('p90'), 'kwh' => (float)($fc['kwh'] ?? 0)];
                }
            }
            $days[] = ['label' => $labels[$o], 'date' => date('Y-m-d', $start), 'start' => $start, 'end' => strtotime('+1 day', $start), 'series' => $series];
        }

        // Fahrpläne: Variablen + per Funktion gesetzte
        $schedules = [];
        foreach ($this->scheduleVars() as $sv) {
            $s = EPSchedule::normalizeSchedule((string)GetValue($sv['var']), $sv['id'], $sv['name']);
            if ($s !== null) {
                $s['name'] = $sv['name'];
                $s['entries'] = EPSchedule::clip($s['entries'], $today, $winEnd);
                $schedules[] = $s;
            }
        }
        foreach ($this->pushedSchedules() as $s) {
            $s['entries'] = EPSchedule::clip((array)($s['entries'] ?? []), $today, $winEnd);
            $schedules[] = $s;
        }

        $pv = $this->ReadPropertyInteger('PriceVar');
        $prices = ($pv > 0 && IPS_VariableExists($pv))
            ? EPSchedule::normalizePrices((string)GetValue($pv), $this->ReadPropertyInteger('PriceUnit'))
            : (array)json_decode($this->ReadAttributeString('Prices'), true);
        $prices = EPSchedule::clip($prices, $today, $winEnd);

        $pu = $this->ReadPropertyInteger('PurchaseVar');
        if ($pu > 0 && IPS_VariableExists($pu)) {
            $raw = json_decode((string)GetValue($pu), true);
            $purchase = EPSchedule::normalizeEntries(is_array($raw) ? $raw : [], 'purchase');
        } else {
            $purchase = (array)json_decode($this->ReadAttributeString('Purchase'), true);
        }
        $purchase = EPSchedule::clip($purchase, $today, $winEnd);

        $colors = [];
        foreach (self::COLORS as $prop => [$key]) {
            $colors[$key] = $this->color($prop);
        }
        return [
            'v' => 1, 'generated' => time(), 'now' => time(), 'colors' => $colors,
            'opts' => ['band' => $this->ReadPropertyBoolean('ShowBand'), 'net' => $this->ReadPropertyBoolean('ShowNet'),
                       'day' => max(0, min(2, $this->ReadPropertyInteger('DefaultDay'))), 'height' => max(120, min(800, $this->ReadPropertyInteger('ChartHeight')))],
            'days' => $days, 'schedules' => array_values($schedules), 'prices' => array_values($prices), 'priceUnit' => 'ct/kWh',
            'cheap' => EPSchedule::cheapest($prices, $today, $winEnd, max(0, min(24, $this->ReadPropertyInteger('CheapHours')))),
            'purchase' => array_values($purchase),
        ];
    }

    /** HTML der Kachel mit eingebetteten Startdaten. */
    private function html(array $payload): string
    {
        $tpl = (string)file_get_contents(__DIR__ . '/module.html');
        $json = (string)json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('/*EPK_DATA*/null', $json, $tpl);
    }

    private function patch(array &$elements, string $name, string $caption): bool
    {
        foreach ($elements as &$el) {
            if (!is_array($el)) {
                continue;
            }
            if (($el['name'] ?? '') === $name) {
                $el['caption'] = $caption;
                return true;
            }
            if (isset($el['items']) && is_array($el['items']) && $this->patch($el['items'], $name, $caption)) {
                return true;
            }
        }
        return false;
    }
}
