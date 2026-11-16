<?php

/**
 * Nachbildung der benötigten Symcon-Funktionen für Tests ohne Symcon (nur für tests/, wird nicht ausgeliefert).
 * Das Archiv erzeugt Werte aus Generatorfunktionen; Rohwerte werden wie in Symcon nur bei Wertänderung „geloggt“.
 */

if (!defined('VM_UPDATE')) {
    define('VM_UPDATE', 10603);
    define('IPS_KERNELSTARTED', 10001);
    define('KR_READY', 10103);
}

if (!defined('VARIABLE_PRESENTATION_SLIDER')) {
    define('VARIABLE_PRESENTATION_SLIDER', '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}');
}

function IPS_GetObjectIDByIdent(string $ident, int $parent)
{
    if (isset(FakeSymcon::$idents[$parent][$ident])) {
        return FakeSymcon::$idents[$parent][$ident];
    }
    trigger_error('Objekt mit Ident ' . $ident . ' nicht gefunden', E_USER_WARNING);
    return false;
}

function IPS_GetKernelRunlevel(): int
{
    return FakeSymcon::$runlevel;
}

final class FakeSymcon
{
    public const ARCHIVE = 10000;
    public static $kernelDir = '';
    /** @var array<int, array> id => [name, value, profile, logged, aggType, kind, gen] */
    public static $vars = [];
    /** @var array<string, string> Profil => Suffix */
    public static $profiles = [];
    public static $calls = 0;
    public static $runlevel = 10103;
    /** @var array<int, array<string, int>> Instanz => Ident => Objekt-ID */
    public static $idents = [];
    /** @var array<string, int[]> Modul-GUID => Instanzen */
    public static $instances = [];
    private static $cum = [];

    public static function reset(string $kernelDir): void
    {
        self::$kernelDir = $kernelDir;
        self::$vars = [];
        self::$profiles = [];
        self::$calls = 0;
        self::$cum = [];
        self::$runlevel = 10103;
        self::$idents = [];
        self::$instances = [];
    }

    /**
     * kind: power (W/kW-Wert = gen(t)), temp (°C), interval (kWh je Viertelstunde aus gen in W),
     * counter (Zählerstand kWh aus gen in W), value (fester Wert)
     */
    public static function addVar(int $id, string $name, string $kind, ?callable $gen, string $profile = '', int $aggType = 0, float $scale = 1.0, $value = 0): void
    {
        self::$vars[$id] = compact('name', 'kind', 'gen', 'profile', 'aggType', 'scale', 'value') + ['logged' => $kind !== 'value'];
    }

    public static function quarterEnergyKwh(int $id, int $qStart): float
    {
        $v = self::$vars[$id];
        return ($v['gen'])($qStart + 450) * 0.25 / 1000.0;
    }

    /** Zählerstand am Ende der Viertelstunde, die bei $qStart beginnt (Summe ab einem festen Anfang). */
    public static function counterAt(int $id, int $qEnd): float
    {
        $t0 = strtotime('2020-01-01');
        if (!isset(self::$cum[$id])) {
            self::$cum[$id] = ['t' => $t0, 'm' => 1000.0];
        }
        $c = &self::$cum[$id];
        if ($qEnd < $c['t']) {
            $c = ['t' => $t0, 'm' => 1000.0];
        }
        while ($c['t'] < $qEnd) {
            $c['m'] += self::quarterEnergyKwh($id, $c['t']);
            $c['t'] += 900;
        }
        return round($c['m'], 3);
    }
}

function IPS_GetInstanceListByModuleID(string $guid): array
{
    if ($guid === '{43192F0B-135B-4CE7-A0A7-1475603F3060}') {
        return [FakeSymcon::ARCHIVE];
    }
    return FakeSymcon::$instances[$guid] ?? [];
}

function IPS_InstanceExists(int $id): bool
{
    foreach (FakeSymcon::$instances as $ids) {
        if (in_array($id, $ids, true)) {
            return true;
        }
    }
    return $id === FakeSymcon::ARCHIVE;
}

function IPS_VariableExists(int $id): bool
{
    return isset(FakeSymcon::$vars[$id]);
}

function IPS_GetName(int $id): string
{
    return FakeSymcon::$vars[$id]['name'] ?? ('#' . $id);
}

function IPS_GetVariable(int $id): array
{
    return ['VariableProfile' => FakeSymcon::$vars[$id]['profile'] ?? '', 'VariableCustomProfile' => '',
            'VariableUpdated' => time(), 'VariableChanged' => time()];
}

function IPS_VariableProfileExists(string $p): bool
{
    return isset(FakeSymcon::$profiles[$p]);
}

function IPS_GetVariableProfile(string $p): array
{
    return ['Suffix' => FakeSymcon::$profiles[$p] ?? ''];
}

function IPS_GetKernelDir(): string
{
    return FakeSymcon::$kernelDir;
}

function GetValue(int $id)
{
    $v = FakeSymcon::$vars[$id];
    return $v['kind'] === 'value' ? $v['value'] : ($v['gen'])(time());
}

function AC_GetLoggingStatus(int $aid, int $vid): bool
{
    return $aid === FakeSymcon::ARCHIVE && !empty(FakeSymcon::$vars[$vid]['logged']);
}

function AC_GetAggregationType(int $aid, int $vid): int
{
    return (int)(FakeSymcon::$vars[$vid]['aggType'] ?? 0);
}

function AC_GetAggregatedValues(int $aid, int $vid, int $level, int $start, int $end, int $limit): array
{
    FakeSymcon::$calls++;
    $v = FakeSymcon::$vars[$vid];
    $end = min($end, time());
    $rows = [];
    if ($level === 8) {
        for ($t = $start - $start % 900; $t <= $end; $t += 900) {
            if ($t + 900 > time()) {
                break;
            }
            $avg = ($v['kind'] === 'counter' && $v['aggType'] === 1)
                ? FakeSymcon::quarterEnergyKwh($vid, $t) * $v['scale']
                : ($v['gen'])($t + 450) * $v['scale'];
            $rows[] = ['TimeStamp' => $t, 'Avg' => $avg, 'Duration' => 900, 'Min' => $avg, 'Max' => $avg];
        }
    } elseif ($level === 1) {
        for ($t = strtotime('today', $start); $t <= $end; $t = strtotime('tomorrow', $t)) {
            $sum = 0.0;
            for ($h = 0; $h < 24; $h++) {
                $sum += ($v['gen'])($t + $h * 3600 + 1800);
            }
            $rows[] = ['TimeStamp' => $t, 'Avg' => $sum / 24 * $v['scale'], 'Duration' => 86400];
        }
    } else {
        throw new RuntimeException('Aggregationsstufe ' . $level . ' nicht nachgebildet');
    }
    $rows = array_reverse($rows);
    return $limit > 0 ? array_slice($rows, 0, $limit) : array_slice($rows, 0, 10000);
}

function AC_GetLoggedValues(int $aid, int $vid, int $start, int $end, int $limit): array
{
    FakeSymcon::$calls++;
    $v = FakeSymcon::$vars[$vid];
    $end = min($end === 0 ? time() : $end, time());
    // Kandidaten ab zwei Tage vor dem Bereich erzeugen, nur Wertänderungen behalten
    $from = ($start === 0 ? $end : $start) - 2 * 86400;
    $from -= $from % 900;
    $rows = [];
    $last = null;
    for ($t = $from + 900; $t <= $end; $t += 900) {
        if ($v['kind'] === 'interval') {
            $val = round(FakeSymcon::quarterEnergyKwh($vid, $t - 900) * $v['scale'], 4);
        } elseif ($v['kind'] === 'counter') {
            $val = FakeSymcon::counterAt($vid, $t) * $v['scale'];
        } else {
            $val = round(($v['gen'])($t) * $v['scale'], 3);
        }
        if ($last !== null && $val === $last) {
            continue;
        }
        $last = $val;
        if ($t >= $start) {
            $rows[] = ['TimeStamp' => $t, 'Value' => $val, 'Duration' => 900];
        }
    }
    $rows = array_reverse($rows);
    return $limit > 0 ? array_slice($rows, 0, $limit) : array_slice($rows, 0, 10000);
}

/** Minimale Basisklasse mit den von den Modulen genutzten Methoden. */
abstract class IPSModuleStrict
{
    public $InstanceID;
    public $props = [];
    public $attrs = [];
    public $values = [];
    public $timers = [];
    public $status = 0;
    public $debug = [];
    public $msgs = [];

    public function __construct(int $id)
    {
        $this->InstanceID = $id;
    }

    public function Create(): void
    {
    }

    public function ApplyChanges(): void
    {
    }

    public function setProperty(string $n, $v): void
    {
        if (!array_key_exists($n, $this->props)) {
            throw new RuntimeException('Property ' . $n . ' nicht registriert');
        }
        $this->props[$n] = is_array($v) ? json_encode($v) : $v;
    }

    protected function RegisterPropertyBoolean(string $n, bool $v): void { $this->props[$n] = $v; }
    protected function RegisterPropertyInteger(string $n, int $v): void { $this->props[$n] = $v; }
    protected function RegisterPropertyFloat(string $n, float $v): void { $this->props[$n] = $v; }
    protected function RegisterPropertyString(string $n, string $v): void { $this->props[$n] = $v; }
    protected function ReadPropertyBoolean(string $n): bool { return (bool)$this->props[$n]; }
    protected function ReadPropertyInteger(string $n): int { return (int)$this->props[$n]; }
    protected function ReadPropertyFloat(string $n): float { return (float)$this->props[$n]; }
    protected function ReadPropertyString(string $n): string { return (string)$this->props[$n]; }
    protected function RegisterAttributeString(string $n, string $v): void { $this->attrs[$n] = $v; }
    protected function ReadAttributeString(string $n): string { return (string)$this->attrs[$n]; }
    protected function WriteAttributeString(string $n, string $v): void
    {
        if (!array_key_exists($n, $this->attrs)) {
            throw new RuntimeException('Attribut ' . $n . ' nicht registriert');
        }
        $this->attrs[$n] = $v;
    }
    protected function RegisterTimer(string $n, int $ms, string $script): void { $this->timers[$n] = 0; }
    protected function SetTimerInterval(string $n, int $ms): void
    {
        if (!array_key_exists($n, $this->timers)) {
            throw new RuntimeException('Timer ' . $n . ' nicht registriert');
        }
        $this->timers[$n] = $ms;
    }
    public $actions = [];

    private function registerIdent(string $i): void
    {
        FakeSymcon::$idents[$this->InstanceID][$i] = FakeSymcon::$idents[$this->InstanceID][$i] ?? (90000 + count(FakeSymcon::$idents, COUNT_RECURSIVE));
    }

    // Profil-Argument wie in Symcon 8: Profilname oder Darstellungs-Array
    protected function RegisterVariableString(string $i, string $n, $p = '', int $pos = 0): bool { $this->registerIdent($i); $this->values[$i] = $this->values[$i] ?? ''; return true; }
    protected function RegisterVariableFloat(string $i, string $n, $p = '', int $pos = 0): bool { $this->registerIdent($i); $this->values[$i] = $this->values[$i] ?? 0.0; return true; }
    protected function RegisterVariableInteger(string $i, string $n, $p = '', int $pos = 0): bool { $this->registerIdent($i); $this->values[$i] = $this->values[$i] ?? 0; return true; }
    protected function EnableAction(string $i): bool { $this->actions[$i] = true; return true; }
    public $visuType = 0;
    public $visuMessages = [];
    protected function SetVisualizationType(int $t): void { $this->visuType = $t; }
    protected function UpdateVisualizationValue($d): bool { $this->visuMessages[] = $d; return true; }
    protected function GetIDForIdent(string $i): int { return FakeSymcon::$idents[$this->InstanceID][$i] ?? 0; }
    public function Destroy(): void
    {
    }
    protected function GetValue(string $ident) { return $this->values[$ident]; }
    protected function SetValue(string $ident, $v): bool
    {
        if (!array_key_exists($ident, $this->values)) {
            throw new RuntimeException('Variable ' . $ident . ' nicht registriert');
        }
        $this->values[$ident] = $v;
        return true;
    }
    protected function SetStatus(int $s): bool { $this->status = $s; return true; }
    protected function SendDebug(string $m, string $d, int $f): bool { $this->debug[] = $m . ': ' . $d; return true; }
    protected function UpdateFormField(string $f, string $p, $v): bool { return true; }
    protected function RegisterMessage(int $s, int $m): bool { $this->msgs[$s][] = $m; $this->msgs[$s] = array_values(array_unique($this->msgs[$s])); return true; }
    protected function UnregisterMessage(int $s, int $m): bool { $this->msgs[$s] = array_values(array_diff($this->msgs[$s] ?? [], [$m])); if (!$this->msgs[$s]) { unset($this->msgs[$s]); } return true; }
    protected function GetMessageList(): array { return $this->msgs; }
}
