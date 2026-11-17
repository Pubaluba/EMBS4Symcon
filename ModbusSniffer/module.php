<?php

declare(strict_types=1);

/**
 * ModbusSniffer – passiver Modbus-Splitter für IP-Symcon
 *
 * Liest Modbus RTU (seriell oder RTU-over-TCP) bzw. Modbus TCP (MBAP) mit,
 * ohne jemals selbst etwas auf den Bus zu legen.
 *
 * Grundregel: Dieses Modul ruft an KEINER Stelle SendDataToParent() auf.
 * Anfragen untergeordneter Instanzen werden abgefangen und entweder aus dem
 * mitgelesenen Cache beantwortet oder verworfen – sie erreichen den Bus nie.
 *
 * Autor: Benjamin Sommer (EMBS4Symcon)
 */
class ModbusSniffer extends IPSModule
{
    // Parent-Module
    private const MODULE_SERIAL_PORT   = '{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}';
    private const MODULE_SERVER_SOCKET = '{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}';

    // Datenfluss vom Parent (RX)
    private const RX_SIMPLE = '{018EF6B5-AB94-40C6-AA53-46943E824ACF}'; // Serial Port / Client Socket
    private const RX_SERVER = '{7A1272A4-C6A7-4E25-9A62-AD07AD8BE2C7}'; // Server Socket

    // Datenfluss zu/von Kindern
    private const CHILD_MODBUS_REQUEST = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}'; // native Modbus-Adresse -> Gateway
    private const CHILD_FRAME          = '{E07F7DDA-41A4-2699-6375-0BD4C00B6695}'; // Sniffer -> eigene Kinder

    private const IF_SERIAL = 0;
    private const IF_SERVER = 1;

    private const PROTO_RTU = 0;
    private const PROTO_TCP = 1;

    private const TABLES = [1 => 'co', 2 => 'di', 3 => 'hr', 4 => 'ir'];

    private const MAX_BUFFER = 4096;      // Schutz gegen Müll-Ströme
    private const PENDING_TIMEOUT = 2.0;  // s, Request ohne Antwort verfällt

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('InterfaceType', self::IF_SERIAL);
        $this->RegisterPropertyInteger('Protocol', self::PROTO_RTU);
        $this->RegisterPropertyInteger('FrameGap', 100);
        $this->RegisterPropertyBoolean('AnswerFromCache', true);
        $this->RegisterPropertyInteger('DeviceID', 1);
        $this->RegisterPropertyInteger('MaxAge', 60);
        $this->RegisterPropertyBoolean('ForwardFrames', true);
        $this->RegisterPropertyBoolean('LogBlocked', false);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyInteger('InterfaceType') === self::IF_SERVER) {
            $this->ForceParent(self::MODULE_SERVER_SOCKET);
        } else {
            $this->ForceParent(self::MODULE_SERIAL_PORT);
        }

        $this->ResetRuntime();

        if ($this->ReadPropertyInteger('InterfaceType') === self::IF_SERIAL
            && $this->ReadPropertyInteger('Protocol') === self::PROTO_TCP) {
            // Modbus TCP über eine serielle Leitung gibt es nicht
            $this->SetStatus(201);
            return;
        }
        $this->SetStatus(102);
    }

    // ---------------------------------------------------------------------
    // Öffentliche Funktionen (MBSNIFF_*)
    // ---------------------------------------------------------------------

    /** Leert Cache, Puffer und Statistik. */
    public function ClearCache(): void
    {
        foreach ($this->GetBufferList() as $name) {
            if (strpos($name, 'C_') === 0) {
                $this->SetBuffer($name, '');
            }
        }
        $this->ResetRuntime();
    }

    /** Statistik als Array: Frames, CRC-/Sync-Fehler, blockierte Anfragen, Cache-Antworten. */
    public function GetStatistics(): array
    {
        return $this->GetStats();
    }

    /**
     * Liefert mitgelesene Werte.
     * $Table: 'hr' Holding, 'ir' Input, 'co' Coils, 'di' Discrete Inputs
     * Rückgabe: [Adresse => ['Value' => int, 'Age' => Sekunden]] (fehlende Adressen fehlen)
     */
    public function GetCachedValues(int $Unit, string $Table, int $Start, int $Quantity): array
    {
        $cache = $this->LoadCache($Unit, $Table);
        $now = microtime(true);
        $out = [];
        for ($a = $Start; $a < $Start + $Quantity; $a++) {
            if (isset($cache[$a])) {
                $out[$a] = ['Value' => $cache[$a][0], 'Age' => round($now - $cache[$a][1], 1)];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Datenfluss vom Parent
    // ---------------------------------------------------------------------

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        if (!is_object($data) || !isset($data->DataID)) {
            return '';
        }

        if ($data->DataID === self::RX_SERVER) {
            $key = ($data->ClientIP ?? '?') . ':' . ($data->ClientPort ?? '?');
            $type = (int) ($data->Type ?? 0);
            if ($type === 1 || $type === 2) { // Connect / Disconnect
                $this->SendDebug('Socket', $key . ($type === 1 ? ' verbunden' : ' getrennt'), 0);
                $this->SetBuffer('RX_' . $key, '');
                $this->SetBuffer('P_' . $key, '');
                return '';
            }
        } else {
            $key = 'serial';
        }

        $chunk = $this->DecodeBuffer((string) ($data->Buffer ?? ''));
        if ($chunk === '') {
            return '';
        }
        $this->HandleChunk($key, $chunk);
        return '';
    }

    // ---------------------------------------------------------------------
    // Datenfluss von Kindern – hier wird geblockt
    // ---------------------------------------------------------------------

    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString);
        $stats = $this->GetStats();
        $stats['Blocked']++;

        if (!is_object($data) || ($data->DataID ?? '') !== self::CHILD_MODBUS_REQUEST) {
            $this->SetStats($stats);
            $this->LogBlocked('Unbekannte Anfrage verworfen: ' . $JSONString);
            return false;
        }

        $fc = (int) ($data->Function ?? 0);
        $addr = (int) ($data->Address ?? 0);
        $qty = (int) ($data->Quantity ?? 0);

        if (!isset(self::TABLES[$fc]) || !$this->ReadPropertyBoolean('AnswerFromCache')) {
            $this->SetStats($stats);
            $this->LogBlocked(sprintf('FC%d Adr %d Anz %d blockiert (nicht auf Bus gesendet)', $fc, $addr, $qty));
            return false;
        }

        $answer = $this->BuildCacheAnswer($this->ReadPropertyInteger('DeviceID'), $fc, $addr, $qty);
        if ($answer === null) {
            $this->SetStats($stats);
            $this->LogBlocked(sprintf('FC%d Adr %d Anz %d blockiert – keine (aktuellen) Werte im Cache', $fc, $addr, $qty));
            return false;
        }

        $stats['CacheAnswers']++;
        $this->SetStats($stats);
        $this->SendDebug('Cache-Antwort', sprintf('FC%d Adr %d Anz %d -> %s', $fc, $addr, $qty, bin2hex($answer)), 0);
        return $answer;
    }

    // ---------------------------------------------------------------------
    // Stream-Verarbeitung
    // ---------------------------------------------------------------------

    private function HandleChunk(string $key, string $chunk): void
    {
        $now = microtime(true);
        $buf = $this->GetBuffer('RX_' . $key);
        $last = (float) $this->GetBuffer('T_' . $key);
        $gap = $this->ReadPropertyInteger('FrameGap') / 1000;

        // Lange Ruhe = Framegrenze: Reste des alten Puffers auswerten, dann verwerfen
        if ($buf !== '' && $gap > 0 && $last > 0 && ($now - $last) > $gap) {
            $this->ParseBuffer($key, $buf, true);
            if ($buf !== '') {
                $this->SendDebug('Verworfen', $key . ': ' . bin2hex($buf), 0);
                $buf = '';
            }
        }

        $buf .= $chunk;
        if (strlen($buf) > self::MAX_BUFFER) {
            $buf = substr($buf, -self::MAX_BUFFER);
        }
        $this->ParseBuffer($key, $buf, false);

        $this->SetBuffer('RX_' . $key, $buf);
        $this->SetBuffer('T_' . $key, (string) $now);
    }

    private function ParseBuffer(string $key, string &$buf, bool $flush): void
    {
        $useTcp = $this->ReadPropertyInteger('InterfaceType') === self::IF_SERVER
            && $this->ReadPropertyInteger('Protocol') === self::PROTO_TCP;
        if ($useTcp) {
            $this->ParseMBAP($key, $buf);
        } else {
            $this->ParseRTU($key, $buf, $flush);
        }
    }

    /** Modbus RTU: Framegrenzen über Funktionscode-Längen + CRC ermitteln. */
    private function ParseRTU(string $key, string &$buf, bool $flush): void
    {
        $syncLost = false;
        while (strlen($buf) >= 4) {
            $unit = ord($buf[0]);
            $fc = ord($buf[1]);
            $pending = $this->GetPending($key);
            $preferResp = $pending !== null && $pending['unit'] === $unit && $pending['fc'] === ($fc & 0x7F);

            $candidates = $unit <= 247 ? self::RtuCandidates($buf, $preferResp) : [];
            $waiting = false;
            $accepted = false;
            foreach ($candidates as [$len, $dir]) {
                if ($len < 4 || $len > 256) {
                    continue;
                }
                if ($len > strlen($buf)) {
                    $waiting = true;
                    continue;
                }
                $frame = substr($buf, 0, $len);
                if (self::CRC16(substr($frame, 0, -2)) === substr($frame, -2)) {
                    $buf = (string) substr($buf, $len);
                    $this->CountFrame();
                    $this->SendDebug('RTU ' . $key, bin2hex($frame), 0);
                    $this->ProcessFrame($key, $unit, substr($frame, 1, -2), $dir, null);
                    $accepted = true;
                    break;
                }
            }
            if ($accepted) {
                continue;
            }
            if ($waiting && !$flush) {
                break; // auf weitere Bytes warten
            }
            // Kein gültiger Frame an Position 0 -> ein Byte verwerfen und neu synchronisieren
            if (!$syncLost) {
                $syncLost = true;
                $this->CountError();
            }
            $buf = (string) substr($buf, 1);
        }
        if ($flush) {
            // Restbytes nach Ruhepause sind kein vollständiger Frame
            if ($buf !== '' && !$syncLost) {
                $this->CountError();
            }
        }
    }

    /**
     * Mögliche Framelängen für RTU (inkl. Adresse und CRC) mit Richtung.
     * Richtung: 'req', 'resp', 'exc' oder 'ambig' (Anfrage und Antwort identisch aufgebaut).
     * @return array<int, array{0:int,1:string}>
     */
    public static function RtuCandidates(string $b, bool $preferResp): array
    {
        $fc = ord($b[1]);
        $len = strlen($b);
        $byte = static fn (int $i): ?int => $i < $len ? ord($b[$i]) : null;

        if ($fc & 0x80) {
            return [[5, 'exc']];
        }

        $req = [];
        $resp = [];
        switch ($fc) {
            case 1: case 2: case 3: case 4:
                $req[] = 8;
                if ($byte(2) !== null) {
                    $resp[] = 5 + $byte(2);
                }
                break;
            case 5: case 6: case 8:
                return [[8, 'ambig']];
            case 22:
                return [[10, 'ambig']];
            case 15: case 16:
                $resp[] = 8;
                $req[] = $byte(6) !== null ? 9 + $byte(6) : 257; // 257 = noch unbekannt -> warten
                break;
            case 23:
                if ($byte(2) !== null) {
                    $resp[] = 5 + $byte(2);
                }
                $req[] = $byte(10) !== null ? 13 + $byte(10) : 257;
                break;
            case 7:
                $req[] = 4;
                $resp[] = 5;
                break;
            case 11:
                $req[] = 4;
                $resp[] = 8;
                break;
            case 12: case 17:
                $req[] = 4;
                if ($byte(2) !== null) {
                    $resp[] = 5 + $byte(2);
                }
                break;
            default:
                return [];
        }

        $out = [];
        $first = $preferResp ? [$resp, 'resp', $req, 'req'] : [$req, 'req', $resp, 'resp'];
        foreach ([[$first[0], $first[1]], [$first[2], $first[3]]] as [$lens, $dir]) {
            foreach ($lens as $l) {
                // 257 signalisiert "Länge noch unbekannt": als unvollständig behandeln
                $out[] = [$l === 257 ? $len + 1 : $l, $dir];
            }
        }
        return $out;
    }

    /** Modbus TCP: MBAP-Header (Transaction-ID, Protokoll 0, Länge). */
    private function ParseMBAP(string $key, string &$buf): void
    {
        $syncLost = false;
        while (strlen($buf) >= 8) {
            $h = unpack('ntid/npid/nlen', substr($buf, 0, 6));
            if ($h['pid'] !== 0 || $h['len'] < 2 || $h['len'] > 254) {
                if (!$syncLost) {
                    $syncLost = true;
                    $this->CountError();
                }
                $buf = (string) substr($buf, 1);
                continue;
            }
            $total = 6 + $h['len'];
            if (strlen($buf) < $total) {
                break;
            }
            $frame = substr($buf, 0, $total);
            $buf = (string) substr($buf, $total);
            $this->CountFrame();
            $this->SendDebug('TCP ' . $key, bin2hex($frame), 0);
            $pdu = substr($frame, 7);
            $this->ProcessFrame($key, ord($frame[6]), $pdu, self::ClassifyPdu($pdu), $h['tid']);
        }
    }

    /** Richtung eines PDUs anhand seiner Struktur (für Modbus TCP). */
    public static function ClassifyPdu(string $pdu): string
    {
        $fc = ord($pdu[0]);
        $l = strlen($pdu);
        if ($fc & 0x80) {
            return 'exc';
        }
        $bcFits = $l >= 2 && $l === 2 + ord($pdu[1]);
        switch ($fc) {
            case 1: case 2: case 3: case 4:
                if ($l === 5 && !$bcFits) {
                    return 'req';
                }
                if ($bcFits && $l !== 5) {
                    return 'resp';
                }
                return 'ambig';
            case 15: case 16:
                return $l === 5 ? 'resp' : 'req';
            case 23:
                return $bcFits ? 'resp' : 'req';
            default:
                return 'ambig';
        }
    }

    // ---------------------------------------------------------------------
    // Frame-Auswertung
    // ---------------------------------------------------------------------

    private function ProcessFrame(string $key, int $unit, string $pdu, string $dir, ?int $tid): void
    {
        $fc = ord($pdu[0]) & 0x7F;
        $pending = $this->GetPending($key, $tid);
        $matches = $pending !== null && $pending['unit'] === $unit && $pending['fc'] === $fc;

        if ($dir === 'ambig') {
            // Antwort auf 5/6/8/22 ist ein Echo der Anfrage
            $dir = ($matches && $pending['pdu'] === bin2hex($pdu)) ? 'resp' : 'req';
        }

        if ($dir === 'req') {
            $this->SetPending($key, $tid, ['unit' => $unit, 'fc' => $fc, 'pdu' => bin2hex($pdu), 'ts' => microtime(true)]);
            $this->Forward(['Type' => 'request', 'Source' => $key, 'Unit' => $unit, 'Function' => $fc]
                + self::DecodeRequest($fc, $pdu));
            return;
        }

        $this->SetPending($key, $tid, null);

        if ($dir === 'exc') {
            $this->Forward(['Type' => 'exception', 'Source' => $key, 'Unit' => $unit, 'Function' => $fc,
                'Exception' => strlen($pdu) > 1 ? ord($pdu[1]) : 0]);
            return;
        }

        if (!$matches) {
            // Antwort ohne zugehörige Anfrage (z. B. Mitschnitt mitten in einer Transaktion)
            $this->Forward(['Type' => 'response', 'Source' => $key, 'Unit' => $unit, 'Function' => $fc,
                'Paired' => false, 'Raw' => bin2hex($pdu)]);
            return;
        }

        $req = self::DecodeRequest($fc, hex2bin($pending['pdu']));
        $values = $this->ApplyTransaction($unit, $fc, $req, $pdu);
        $this->Forward(['Type' => 'response', 'Source' => $key, 'Unit' => $unit, 'Function' => $fc,
            'Paired' => true, 'Table' => $values['table'] ?? '', 'Address' => $values['address'] ?? 0,
            'Values' => $values['values'] ?? [], 'Raw' => bin2hex($pdu)]);
    }

    /** Zerlegt eine Anfrage in Startadresse, Anzahl und ggf. Schreibwerte. */
    public static function DecodeRequest(int $fc, string $pdu): array
    {
        $r = ['Address' => 0, 'Quantity' => 0, 'Values' => []];
        if (strlen($pdu) < 5) {
            return $r;
        }
        $u = unpack('nstart/nqty', substr($pdu, 1, 4));
        $r['Address'] = $u['start'];
        switch ($fc) {
            case 1: case 2: case 3: case 4:
                $r['Quantity'] = $u['qty'];
                break;
            case 5:
                $r['Quantity'] = 1;
                $r['Values'] = [$u['qty'] === 0xFF00 ? 1 : 0];
                break;
            case 6:
                $r['Quantity'] = 1;
                $r['Values'] = [$u['qty']];
                break;
            case 15:
                $r['Quantity'] = $u['qty'];
                $r['Values'] = self::UnpackBits(substr($pdu, 6), $u['qty']);
                break;
            case 16:
                $r['Quantity'] = $u['qty'];
                $r['Values'] = self::UnpackRegs(substr($pdu, 6), $u['qty']);
                break;
            case 23:
                $r['Quantity'] = $u['qty']; // Lesebereich
                if (strlen($pdu) >= 10) {
                    $w = unpack('nwstart/nwqty', substr($pdu, 5, 4));
                    $r['WriteAddress'] = $w['wstart'];
                    $r['Values'] = self::UnpackRegs(substr($pdu, 10), $w['wqty']);
                }
                break;
        }
        return $r;
    }

    /** Übernimmt eine bestätigte Transaktion in den Cache. */
    private function ApplyTransaction(int $unit, int $fc, array $req, string $resp): array
    {
        switch ($fc) {
            case 1: case 2:
                $vals = self::UnpackBits(substr($resp, 2), $req['Quantity']);
                $this->StoreCache($unit, self::TABLES[$fc], $req['Address'], $vals);
                return ['table' => self::TABLES[$fc], 'address' => $req['Address'], 'values' => $vals];
            case 3: case 4:
                $vals = self::UnpackRegs(substr($resp, 2), $req['Quantity']);
                $this->StoreCache($unit, self::TABLES[$fc], $req['Address'], $vals);
                return ['table' => self::TABLES[$fc], 'address' => $req['Address'], 'values' => $vals];
            case 5: case 15:
                $this->StoreCache($unit, 'co', $req['Address'], $req['Values']);
                return ['table' => 'co', 'address' => $req['Address'], 'values' => $req['Values']];
            case 6: case 16:
                $this->StoreCache($unit, 'hr', $req['Address'], $req['Values']);
                return ['table' => 'hr', 'address' => $req['Address'], 'values' => $req['Values']];
            case 23:
                if (isset($req['WriteAddress'])) {
                    $this->StoreCache($unit, 'hr', $req['WriteAddress'], $req['Values']);
                }
                $vals = self::UnpackRegs(substr($resp, 2), $req['Quantity']);
                $this->StoreCache($unit, 'hr', $req['Address'], $vals);
                return ['table' => 'hr', 'address' => $req['Address'], 'values' => $vals];
        }
        return [];
    }

    /** Antwort im Format des nativen Modbus-Gateways: FC + Bytezahl + Daten. */
    private function BuildCacheAnswer(int $unit, int $fc, int $addr, int $qty): ?string
    {
        if ($qty < 1 || $qty > 2000) {
            return null;
        }
        $cache = $this->LoadCache($unit, self::TABLES[$fc]);
        $maxAge = $this->ReadPropertyInteger('MaxAge');
        $now = microtime(true);
        $vals = [];
        for ($a = $addr; $a < $addr + $qty; $a++) {
            if (!isset($cache[$a]) || ($maxAge > 0 && ($now - $cache[$a][1]) > $maxAge)) {
                return null;
            }
            $vals[] = $cache[$a][0];
        }
        if ($fc <= 2) {
            $data = '';
            foreach (array_chunk($vals, 8) as $chunk) {
                $byte = 0;
                foreach ($chunk as $i => $v) {
                    $byte |= ($v ? 1 : 0) << $i;
                }
                $data .= chr($byte);
            }
        } else {
            $data = pack('n*', ...$vals);
        }
        return chr($fc) . chr(strlen($data) & 0xFF) . $data;
    }

    // ---------------------------------------------------------------------
    // Hilfsfunktionen
    // ---------------------------------------------------------------------

    public static function CRC16(string $data): string
    {
        $crc = 0xFFFF;
        $l = strlen($data);
        for ($i = 0; $i < $l; $i++) {
            $crc ^= ord($data[$i]);
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 1) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
            }
        }
        return chr($crc & 0xFF) . chr($crc >> 8);
    }

    public static function UnpackRegs(string $data, int $qty): array
    {
        $qty = min($qty, intdiv(strlen($data), 2));
        return $qty > 0 ? array_values(unpack('n' . $qty, $data)) : [];
    }

    public static function UnpackBits(string $data, int $qty): array
    {
        $out = [];
        for ($i = 0; $i < $qty && intdiv($i, 8) < strlen($data); $i++) {
            $out[] = (ord($data[intdiv($i, 8)]) >> ($i % 8)) & 1;
        }
        return $out;
    }

    private function DecodeBuffer(string $buffer): string
    {
        // Symcon liefert den Buffer im einfachen Datenfluss UTF-8-kodiert
        return mb_convert_encoding($buffer, 'ISO-8859-1', 'UTF-8');
    }

    private function Forward(array $payload): void
    {
        if (!$this->ReadPropertyBoolean('ForwardFrames')) {
            return;
        }
        $payload['DataID'] = self::CHILD_FRAME;
        $payload['Timestamp'] = microtime(true);
        $this->SendDataToChildren(json_encode($payload));
    }

    private function LoadCache(int $unit, string $table): array
    {
        $raw = $this->GetBuffer('C_' . $unit . '_' . $table);
        $c = $raw === '' ? [] : json_decode($raw, true);
        return is_array($c) ? $c : [];
    }

    private function StoreCache(int $unit, string $table, int $start, array $values): void
    {
        if ($values === []) {
            return;
        }
        $cache = $this->LoadCache($unit, $table);
        $ts = round(microtime(true), 3);
        foreach ($values as $i => $v) {
            $cache[$start + $i] = [$v, $ts];
        }
        $this->SetBuffer('C_' . $unit . '_' . $table, json_encode($cache));
    }

    private function GetPending(string $key, ?int $tid = null): ?array
    {
        $raw = $this->GetBuffer('P_' . $key);
        $all = $raw === '' ? [] : json_decode($raw, true);
        $p = $all[$tid ?? 'rtu'] ?? null;
        if ($p === null || (microtime(true) - $p['ts']) > self::PENDING_TIMEOUT) {
            return null;
        }
        return $p;
    }

    private function SetPending(string $key, ?int $tid, ?array $value): void
    {
        $raw = $this->GetBuffer('P_' . $key);
        $all = $raw === '' ? [] : json_decode($raw, true);
        $now = microtime(true);
        // abgelaufene Einträge aufräumen
        $all = array_filter($all, static fn ($p) => ($now - $p['ts']) <= self::PENDING_TIMEOUT);
        if ($value === null) {
            unset($all[$tid ?? 'rtu']);
        } else {
            $all[$tid ?? 'rtu'] = $value;
        }
        $this->SetBuffer('P_' . $key, json_encode($all));
    }

    private function GetStats(): array
    {
        $s = json_decode($this->GetBuffer('Stats'), true);
        return is_array($s) ? $s : ['Frames' => 0, 'Errors' => 0, 'Blocked' => 0, 'CacheAnswers' => 0];
    }

    private function SetStats(array $s): void
    {
        $this->SetBuffer('Stats', json_encode($s));
    }

    private function CountFrame(): void
    {
        $s = $this->GetStats();
        $s['Frames']++;
        $this->SetStats($s);
    }

    private function CountError(): void
    {
        $s = $this->GetStats();
        $s['Errors']++;
        $this->SetStats($s);
    }

    private function LogBlocked(string $msg): void
    {
        $this->SendDebug('BLOCKIERT', $msg, 0);
        if ($this->ReadPropertyBoolean('LogBlocked')) {
            $this->LogMessage('ModbusSniffer: ' . $msg, KL_WARNING);
        }
    }

    private function ResetRuntime(): void
    {
        foreach ($this->GetBufferList() as $name) {
            if (preg_match('/^(RX_|T_|P_)/', $name)) {
                $this->SetBuffer($name, '');
            }
        }
        $this->SetStats(['Frames' => 0, 'Errors' => 0, 'Blocked' => 0, 'CacheAnswers' => 0]);
    }
}
