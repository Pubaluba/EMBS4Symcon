<?php

class ArpScan extends IPSModule
{
    private const OUI_URLS = [
        'https://standards-oui.ieee.org/oui/oui.csv',      // MA-L (24 Bit)
        'https://standards-oui.ieee.org/oui28/mam.csv',    // MA-M (28 Bit)
        'https://standards-oui.ieee.org/oui36/oui36.csv'   // MA-S (36 Bit)
    ];

    public function Create()
    {
        // Diese Zeile nicht löschen
        parent::Create();

        $this->RegisterPropertyString('ArpScanPath', '');
        $this->RegisterPropertyBoolean('UseSudo', false);
        $this->RegisterPropertyString('Interface', '');
        $this->RegisterPropertyInteger('Timeout', 500);
        $this->RegisterPropertyString('Network', '');
        $this->RegisterPropertyInteger('Bitcount', 24);
        $this->RegisterPropertyString('AlpineMirror', 'https://dl-cdn.alpinelinux.org/alpine');
        $this->RegisterPropertyString('AlpineBranch', 'latest-stable');
        $this->RegisterPropertyString('Architecture', '');

        $this->RegisterAttributeString('Devices', '[]');
    }

    public function ApplyChanges()
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        $this->RegisterVariableString('LocalIP', 'Eigene IP', '', 10);
        $this->RegisterVariableString('LocalNetwork', 'Eigenes Netzwerk', '', 20);
        $this->RegisterVariableInteger('DeviceCount', 'Gefundene Geräte', '', 30);
        $this->RegisterVariableInteger('LastScan', 'Letzter Scan', '~UnixTimestamp', 40);
        $this->RegisterVariableString('DeviceList', 'Geräteliste', '~HTMLBox', 50);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $devices = json_decode($this->ReadAttributeString('Devices'), true);
        foreach ($form['actions'] as &$element) {
            if (($element['name'] ?? '') === 'Devices') {
                $element['values'] = is_array($devices) ? $devices : [];
            }
            if (($element['name'] ?? '') === 'ArpScanState') {
                $element['caption'] = $this->stateText();
            }
        }
        unset($element);
        return json_encode($form);
    }

    // ------------------------------------------------------------------
    // Öffentliche Funktionen
    // ------------------------------------------------------------------

    /**
     * ARPS_GetIP($id)
     * Ermittelt die eigene IPv4-Adresse (Interface der Default-Route, sonst erstes Interface).
     */
    public function GetIP(): string
    {
        $interfaces = $this->detectInterfaces();
        if (count($interfaces) === 0) {
            trigger_error('Keine IPv4-Adresse gefunden.', E_USER_WARNING);
            return '';
        }

        $primary = $interfaces[0];
        $route = (string) @shell_exec('ip -4 route get 1.1.1.1 2>/dev/null');
        if (preg_match('/src\s+(\d+\.\d+\.\d+\.\d+)/', $route, $m)) {
            foreach ($interfaces as $iface) {
                if ($iface['IP'] === $m[1]) {
                    $primary = $iface;
                    break;
                }
            }
        }

        $network = $this->networkAddress($primary['IP'], $primary['Bitcount']);

        $this->SetValue('LocalIP', $primary['IP']);
        $this->SetValue('LocalNetwork', $network . '/' . $primary['Bitcount']);
        $this->UpdateFormField('Network', 'value', $network);
        $this->UpdateFormField('Bitcount', 'value', $primary['Bitcount']);

        return $primary['IP'];
    }

    /**
     * ARPS_GetNetworks($id)
     * Liefert alle IPv4-Interfaces als Array [Interface, IP, Bitcount, Network].
     */
    public function GetNetworks(): array
    {
        $result = $this->detectInterfaces();
        foreach ($result as &$iface) {
            $iface['Network'] = $this->networkAddress($iface['IP'], $iface['Bitcount']);
        }
        return $result;
    }

    /**
     * ARPS_InstallArpScan($id)
     * Lädt arp-scan inkl. aller Bibliotheken (Alpine Linux / musl, plattformspezifisch)
     * und legt sie im Modulordner unter runtime/ ab. Das Binary läuft über den
     * mitgelieferten musl-Loader und ist damit unabhängig von den Bibliotheken des Systems.
     */
    public function InstallArpScan(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->stateText();
        }
        @ini_set('memory_limit', '128M');
        try {
            $arch = $this->alpineArch();
            $mirror = rtrim(trim($this->ReadPropertyString('AlpineMirror')), '/');
            $branch = trim($this->ReadPropertyString('AlpineBranch'));
            if ($mirror === '' || $branch === '') {
                throw new Exception('Mirror und Alpine-Version dürfen nicht leer sein.');
            }

            // Paketindizes laden (main hat Vorrang vor community)
            $index = ['byName' => [], 'prov' => []];
            foreach (['main', 'community'] as $repo) {
                $this->loadApkIndex($index, $mirror . '/' . $branch . '/' . $repo . '/' . $arch . '/APKINDEX.tar.gz', $repo);
            }
            if (!isset($index['byName']['arp-scan'])) {
                throw new Exception('Paket arp-scan im Alpine-Index nicht gefunden (' . $branch . ', ' . $arch . ').');
            }

            $packages = $this->resolveApk($index, 'arp-scan');
            $this->SendDebug('Pakete', implode(', ', $packages), 0);

            $root = $this->runtimeDir() . '/root';
            $this->rrmdir($root);
            mkdir($root, 0755, true);

            $versions = [];
            foreach ($packages as $name) {
                $p = $index['byName'][$name];
                $url = $mirror . '/' . $branch . '/' . $p['repo'] . '/' . $arch . '/' . $name . '-' . $p['ver'] . '.apk';
                $this->SendDebug('Download', $url, 0);
                $tmp = tempnam(sys_get_temp_dir(), 'apk');
                $this->downloadToFile($url, $tmp, 120);
                try {
                    $this->extractTar($this->gzReadAll($tmp), $root);
                } finally {
                    @unlink($tmp);
                }
                $versions[$name] = $p['ver'];
            }

            // Binary und Loader suchen
            $binary = '';
            foreach (['usr/sbin/arp-scan', 'usr/bin/arp-scan', 'sbin/arp-scan', 'bin/arp-scan'] as $cand) {
                if (is_file($root . '/' . $cand)) {
                    $binary = $cand;
                    break;
                }
            }
            $loaders = glob($root . '/lib/ld-musl-*.so.1');
            if ($binary === '' || !$loaders) {
                throw new Exception('Installation unvollständig (Binary oder musl-Loader fehlt).');
            }
            chmod($root . '/' . $binary, 0755);
            chmod($loaders[0], 0755);

            file_put_contents($this->runtimeDir() . '/installed.json', json_encode([
                'arch'     => $arch,
                'binary'   => $binary,
                'loader'   => 'lib/' . basename($loaders[0]),
                'versions' => $versions,
                'date'     => time()
            ]));

            // Testlauf
            $cmd = $this->bundledCommand();
            $out = [];
            $rc = 0;
            exec($cmd . ' --version 2>&1', $out, $rc);
            $this->SendDebug('Test', implode("\n", $out), 0);
            if ($rc !== 0 || stripos(implode(' ', $out), 'arp-scan') === false) {
                @unlink($this->runtimeDir() . '/installed.json');
                throw new Exception('Testlauf von arp-scan fehlgeschlagen (Code ' . $rc . '): ' . implode(' | ', $out)
                    . ' – ist das Dateisystem mit "noexec" eingehängt oder die Architektur falsch? (' . $arch . ')');
            }
        } catch (Throwable $e) {
            trigger_error('Installation fehlgeschlagen: ' . $e->getMessage(), E_USER_WARNING);
            return 'Fehler: ' . $e->getMessage();
        }

        $text = $this->stateText();
        $this->UpdateFormField('ArpScanState', 'caption', $text);
        return $text;
    }

    /**
     * ARPS_UpdateVendorDatabase($id)
     * Lädt die IEEE-OUI-Listen (MA-L, MA-M, MA-S) und speichert sie im Modulordner.
     */
    public function UpdateVendorDatabase(): string
    {
        try {
            @ini_set('memory_limit', '128M');
            $dir = $this->runtimeDir();
            $tmpFile = $dir . '/oui.tsv.tmp';
            $out = fopen($tmpFile, 'w');
            $count = 0;
            foreach (self::OUI_URLS as $url) {
                $csvFile = $dir . '/oui.csv.tmp';
                $this->downloadToFile($url, $csvFile, 180);
                $fh = fopen($csvFile, 'r');
                fgetcsv($fh, 0, ',', '"', ''); // Kopfzeile
                while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                    if (count($r) >= 3 && preg_match('/^[0-9A-Fa-f]{6,9}$/', $r[1])) {
                        fwrite($out, strtoupper($r[1]) . "\t" . trim(preg_replace('/\s+/', ' ', $r[2])) . "\n");
                        $count++;
                    }
                }
                fclose($fh);
                @unlink($csvFile);
            }
            fclose($out);
            if ($count < 1000) {
                @unlink($tmpFile);
                throw new Exception('Hersteller-Liste unvollständig (' . $count . ' Einträge).');
            }
            rename($tmpFile, $dir . '/oui.tsv');
        } catch (Throwable $e) {
            trigger_error('Hersteller-Datenbank: ' . $e->getMessage(), E_USER_WARNING);
            return 'Fehler: ' . $e->getMessage();
        }
        $text = $this->stateText();
        $this->UpdateFormField('ArpScanState', 'caption', $text);
        return $text;
    }

    /**
     * ARPS_ScanNetwork($id, '192.168.1.0', 24)
     * Leere Parameter -> Werte aus der Konfiguration bzw. eigenes Netzwerk.
     * Rückgabe: Array aus ['IP', 'MAC', 'Vendor'], nach IP sortiert.
     */
    public function ScanNetwork(?string $IP = '', ?int $Bitcount = 0): array
    {
        $IP = trim((string) $IP);
        $Bitcount = (int) $Bitcount;
        if ($IP === '') {
            $IP = trim($this->ReadPropertyString('Network'));
        }
        if ($Bitcount <= 0) {
            $Bitcount = $this->ReadPropertyInteger('Bitcount');
        }
        if (strpos($IP, '/') !== false) {
            [$IP, $bits] = explode('/', $IP, 2);
            $Bitcount = (int) $bits;
        }
        if ($IP === '') {
            if ($this->GetIP() === '') {
                return [];
            }
            [$IP, $bits] = explode('/', $this->GetValue('LocalNetwork'), 2);
            $Bitcount = (int) $bits;
        }

        if (filter_var($IP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            trigger_error('Ungültige IPv4-Adresse: ' . $IP, E_USER_WARNING);
            return [];
        }
        if ($Bitcount < 16 || $Bitcount > 32) {
            trigger_error('Bitcount muss zwischen 16 und 32 liegen.', E_USER_WARNING);
            return [];
        }
        @ini_set('memory_limit', '128M');
        $netAddr = $this->networkAddress($IP, $Bitcount);
        // Große Netze in /24-Blöcke teilen (max. 254 Hosts pro Durchlauf)
        $chunks = $this->chunks($netAddr, $Bitcount);
        $this->SendDebug('Chunks', count($chunks) . ' Block/Blöcke', 0);

        $found = []; // Liste aus [ip, mac, vendorVonArpScan]
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $found = $this->scanViaArpTable($chunks);
            } else {
                try {
                    [$binary, $bundled] = $this->binaryCommand();
                    $found = $this->scanViaArpScan($binary, $bundled, $chunks);
                } catch (Throwable $e) {
                    // arp-scan nicht verfügbar -> Fallback über die ARP-Tabelle des Systems
                    $this->LogMessage('arp-scan nicht nutzbar, verwende ARP-Tabelle: ' . $e->getMessage(), KL_WARNING);
                    $found = $this->scanViaArpTable($chunks);
                }
            }
        } catch (Throwable $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
            return [];
        }

        // Hersteller-Datenbank bei Bedarf einmalig laden (nicht kritisch)
        if (!is_file($this->runtimeDir() . '/oui.tsv')) {
            $this->SendDebug('OUI', $this->UpdateVendorDatabase(), 0);
        }
        $vendors = $this->lookupOui(array_column($found, 1));

        $devices = [];
        foreach ($found as [$ip, $mac, $own]) {
            $devices[$ip . '|' . $mac] = [
                'IP'     => $ip,
                'MAC'    => $mac,
                'Vendor' => $this->vendorFor($mac, $vendors, $own)
            ];
        }
        $devices = array_values($devices);

        usort($devices, function ($a, $b) {
            return ip2long($a['IP']) <=> ip2long($b['IP']);
        });

        $this->WriteAttributeString('Devices', json_encode($devices));
        $this->SetValue('DeviceCount', count($devices));
        $this->SetValue('LastScan', time());
        $this->SetValue('DeviceList', $this->buildHtml($devices));
        $this->UpdateFormField('Devices', 'values', json_encode($devices));

        return $devices;
    }

    // ------------------------------------------------------------------
    // Scan-Methoden
    // ------------------------------------------------------------------

    /** Teilt ein Netz in /24-Blöcke. */
    private function chunks(string $net, int $bits): array
    {
        if ($bits >= 24) {
            return [[$net, $bits]];
        }
        $start = ip2long($net);
        $count = 1 << (24 - $bits);
        $res = [];
        for ($i = 0; $i < $count; $i++) {
            $res[] = [long2ip($start + ($i << 8)), 24];
        }
        return $res;
    }

    /** arp-scan blockweise ausführen. Rückgabe: [[ip, mac, hersteller], ...] */
    private function scanViaArpScan(string $binary, bool $bundled, array $chunks): array
    {
        $cmdBase = ($this->ReadPropertyBoolean('UseSudo') ? 'sudo -n ' : '') . $binary
            . ' --plain --quiet --retry=2 --timeout=' . max(50, $this->ReadPropertyInteger('Timeout'));
        if ($bundled) {
            // leere Vendor-Dateien: Herstellernamen kommen aus der IEEE-Liste dieses Moduls
            $empty = $this->runtimeDir() . '/empty.txt';
            if (!is_file($empty)) {
                file_put_contents($empty, '');
            }
            $cmdBase .= ' --ouifile=' . escapeshellarg($empty) . ' --macfile=' . escapeshellarg($empty);
        }
        $interface = trim($this->ReadPropertyString('Interface'));
        if ($interface !== '') {
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $interface)) {
                throw new Exception('Ungültiger Interface-Name.');
            }
            $cmdBase .= ' --interface=' . escapeshellarg($interface);
        }

        $found = [];
        $lastError = '';
        foreach ($chunks as [$net, $bits]) {
            $cmd = $cmdBase . ' ' . escapeshellarg($net . '/' . $bits) . ' 2>&1';
            $this->SendDebug('Command', $cmd, 0);
            $output = [];
            $rc = 0;
            exec($cmd, $output, $rc);
            $this->SendDebug('Output ' . $net, implode("\n", $output), 0);
            $n = 0;
            foreach ($output as $line) {
                if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+([0-9a-f]{2}(?::[0-9a-f]{2}){5})\s*(.*)$/i', $line, $m)) {
                    $found[] = [$m[1], strtolower($m[2]), trim(preg_replace('/\s*\(DUP: \d+\)\s*$/', '', $m[3]))];
                    $n++;
                }
            }
            if ($rc !== 0 && $n === 0) {
                $lastError = 'arp-scan fehlgeschlagen (Code ' . $rc . '): ' . implode(' | ', $output)
                    . ' – fehlen Root-Rechte? Siehe Hinweis zu sudo.';
            }
        }
        if (count($found) === 0 && $lastError !== '') {
            throw new Exception($lastError);
        }
        return $found;
    }

    /**
     * Windows (und Fallback unter Linux): UDP-Pakete an alle Hosts lösen die ARP-Auflösung
     * des Betriebssystems aus, anschließend wird die ARP-Tabelle ("arp -a") ausgelesen.
     */
    private function scanViaArpTable(array $chunks): array
    {
        $wait = max(500, $this->ReadPropertyInteger('Timeout') * 3) * 1000;
        for ($round = 0; $round < 2; $round++) {
            foreach ($chunks as [$net, $bits]) {
                $base = ip2long($net);
                $size = 1 << (32 - $bits);
                $from = $bits >= 31 ? 0 : 1;
                $to = $bits >= 31 ? $size - 1 : $size - 2;
                for ($i = $from; $i <= $to; $i++) {
                    $s = @stream_socket_client('udp://' . long2ip($base + $i) . ':9', $errno, $errstr, 0.05);
                    if ($s) {
                        @fwrite($s, "\0");
                        fclose($s);
                    }
                }
            }
            usleep($wait);
        }

        // Nur Einträge aus dem gewünschten Netz
        $ranges = [];
        foreach ($chunks as [$net, $bits]) {
            $ranges[] = [ip2long($net), ip2long($net) + (1 << (32 - $bits)) - 1];
        }
        $found = [];
        foreach ($this->readArpTable() as [$ip, $mac]) {
            $l = ip2long($ip);
            foreach ($ranges as [$lo, $hi]) {
                if ($l >= $lo && $l <= $hi) {
                    $found[] = [$ip, $mac, ''];
                    break;
                }
            }
        }
        return $found;
    }

    private function readArpTable(): array
    {
        $rows = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $out = [];
            exec('arp -a', $out);
            $this->SendDebug('arp -a', implode("\n", $out), 0);
            foreach ($out as $l) {
                if (preg_match('/(\d+\.\d+\.\d+\.\d+)\s+([0-9a-f]{2}(?:-[0-9a-f]{2}){5})/i', $l, $m)) {
                    $rows[] = [$m[1], strtolower(str_replace('-', ':', $m[2]))];
                }
            }
        } else {
            $lines = @file('/proc/net/arp', FILE_IGNORE_NEW_LINES) ?: [];
            array_shift($lines);
            foreach ($lines as $l) {
                $c = preg_split('/\s+/', trim($l));
                if (count($c) >= 4 && $c[2] === '0x2' && $c[3] !== '00:00:00:00:00:00') {
                    $rows[] = [$c[0], strtolower($c[3])];
                }
            }
        }
        // Broadcast und Multicast entfernen
        return array_values(array_filter($rows, function ($r) {
            return $r[1] !== 'ff:ff:ff:ff:ff:ff' && strncmp($r[1], '01:00:5e', 8) !== 0 && strncmp($r[1], '33:33', 5) !== 0;
        }));
    }

    // ------------------------------------------------------------------
    // arp-scan Bereitstellung
    // ------------------------------------------------------------------

    private function runtimeDir(): string
    {
        $dir = __DIR__ . '/runtime';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new Exception('Modulordner nicht beschreibbar: ' . $dir);
        }
        return $dir;
    }

    /** @return array [Befehl, bundled] */
    private function binaryCommand(): array
    {
        $custom = trim($this->ReadPropertyString('ArpScanPath'));
        if ($custom !== '' && is_file($custom)) {
            return [escapeshellarg($custom), false];
        }
        foreach (['/usr/sbin/arp-scan', '/usr/bin/arp-scan', '/usr/local/sbin/arp-scan', '/usr/local/bin/arp-scan'] as $p) {
            if (is_file($p) && is_executable($p)) {
                return [escapeshellarg($p), false];
            }
        }
        $cmd = $this->bundledCommand();
        if ($cmd === null) {
            $result = $this->InstallArpScan();
            $cmd = $this->bundledCommand();
            if ($cmd === null) {
                throw new Exception('arp-scan konnte nicht bereitgestellt werden. ' . $result);
            }
        }
        return [$cmd, true];
    }

    private function bundledCommand(): ?string
    {
        $info = @json_decode((string) @file_get_contents(__DIR__ . '/runtime/installed.json'), true);
        if (!is_array($info)) {
            return null;
        }
        $root = __DIR__ . '/runtime/root';
        $loader = $root . '/' . $info['loader'];
        $bin = $root . '/' . $info['binary'];
        if (!is_file($loader) || !is_file($bin)) {
            return null;
        }
        return escapeshellarg($loader)
            . ' --library-path ' . escapeshellarg($root . '/lib:' . $root . '/usr/lib')
            . ' ' . escapeshellarg($bin);
    }

    private function stateText(): string
    {
        $parts = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $parts[] = 'Windows: Scan über ARP-Tabelle (arp -a), arp-scan nicht erforderlich';
            $oui = __DIR__ . '/runtime/oui.tsv';
            $parts[] = is_file($oui) ? 'Hersteller-Datenbank: Stand ' . date('d.m.Y', filemtime($oui)) : 'Hersteller-Datenbank: nicht geladen';
            return implode(' | ', $parts);
        }
        $info = @json_decode((string) @file_get_contents(__DIR__ . '/runtime/installed.json'), true);
        if (is_array($info) && $this->bundledCommand() !== null) {
            $parts[] = 'arp-scan: installiert im Modulordner (' . ($info['versions']['arp-scan'] ?? '?') . ', ' . $info['arch'] . ')';
        } else {
            $sys = '';
            foreach (['/usr/sbin/arp-scan', '/usr/bin/arp-scan', '/usr/local/sbin/arp-scan', '/usr/local/bin/arp-scan'] as $p) {
                if (is_file($p)) {
                    $sys = $p;
                    break;
                }
            }
            $parts[] = $sys !== '' ? 'arp-scan: System-Installation (' . $sys . ')' : 'arp-scan: nicht installiert (wird beim ersten Scan automatisch geladen)';
        }
        $oui = __DIR__ . '/runtime/oui.tsv';
        $parts[] = is_file($oui) ? 'Hersteller-Datenbank: Stand ' . date('d.m.Y', filemtime($oui)) : 'Hersteller-Datenbank: nicht geladen';
        return implode(' | ', $parts);
    }

    private function alpineArch(): string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            throw new Exception('Die automatische Installation wird nur unter Linux unterstützt.');
        }
        $override = trim($this->ReadPropertyString('Architecture'));
        if ($override !== '') {
            return $override;
        }
        $machine = strtolower(php_uname('m'));
        $map = [
            'aarch64' => 'aarch64', 'arm64' => 'aarch64',
            'armv7l'  => 'armv7',   'armv8l' => 'armv7',
            'armv6l'  => 'armhf',
            'x86_64'  => 'x86_64',  'amd64'  => 'x86_64',
            'i686'    => 'x86',     'i386'   => 'x86'
        ];
        if (!isset($map[$machine])) {
            throw new Exception('Unbekannte Architektur: ' . $machine . ' (bitte unter "Erweitert" eintragen).');
        }
        return $map[$machine];
    }

    /** Liest den APKINDEX streamend (speicherschonend) und merkt sich nur das Nötigste. */
    private function loadApkIndex(array &$index, string $url, string $repo): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'apkindex');
        $found = false;
        try {
            $this->downloadToFile($url, $tmp, 120);
            $gz = gzopen($tmp, 'rb');
            if ($gz === false) {
                throw new Exception('APKINDEX nicht lesbar: ' . $url);
            }
            while (true) {
                $h = $this->gzReadExact($gz, 512);
                if (strlen($h) < 512) {
                    break;
                }
                if (trim($h, "\0") === '') {
                    continue;
                }
                $name = preg_replace('#^(\./)+#', '', rtrim(substr($h, 0, 100), "\0"));
                $size = (int) octdec(trim(substr($h, 124, 12), " \0"));
                $padded = (int) (ceil($size / 512) * 512);
                if ($name === 'APKINDEX') {
                    $this->parseApkIndexStream($gz, $size, $index, $repo);
                    $this->gzSkip($gz, $padded - $size);
                    $found = true;
                    break;
                }
                $this->gzSkip($gz, $padded);
            }
            gzclose($gz);
        } finally {
            @unlink($tmp);
        }
        if (!$found) {
            throw new Exception('APKINDEX leer oder nicht lesbar: ' . $url);
        }
    }

    private function parseApkIndexStream($gz, int $size, array &$index, string $repo): void
    {
        $remaining = $size;
        $carry = '';
        $fields = [];
        while ($remaining > 0) {
            $chunk = gzread($gz, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $remaining -= strlen($chunk);
            $lines = explode("\n", $carry . $chunk);
            $carry = array_pop($lines);
            foreach ($lines as $line) {
                $this->apkIndexLine($line, $fields, $index, $repo);
            }
        }
        if ($carry !== '') {
            $this->apkIndexLine($carry, $fields, $index, $repo);
        }
        $this->apkIndexLine('', $fields, $index, $repo);
    }

    private function apkIndexLine(string $line, array &$f, array &$index, string $repo): void
    {
        if ($line !== '') {
            if (strlen($line) > 2 && $line[1] === ':') {
                $k = $line[0];
                if ($k === 'P' || $k === 'V' || $k === 'D' || $k === 'p') {
                    $f[$k] = substr($line, 2);
                }
            }
            return;
        }
        // Leerzeile = Paket abgeschlossen
        if (isset($f['P'], $f['V']) && !isset($index['byName'][$f['P']])) {
            $deps = [];
            foreach (preg_split('/\s+/', trim($f['D'] ?? '')) as $d) {
                if (strncmp($d, 'so:', 3) === 0) {
                    $deps[] = preg_replace('/[<>=~].*$/', '', $d);
                }
            }
            $index['byName'][$f['P']] = ['ver' => $f['V'], 'repo' => $repo, 'deps' => $deps];
            if (!isset($index['prov'][$f['P']])) {
                $index['prov'][$f['P']] = $f['P'];
            }
            foreach (preg_split('/\s+/', trim($f['p'] ?? '')) as $tok) {
                if (strncmp($tok, 'so:', 3) === 0) {
                    $tok = preg_replace('/[<>=~].*$/', '', $tok);
                    if (!isset($index['prov'][$tok])) {
                        $index['prov'][$tok] = $f['P'];
                    }
                }
            }
        }
        $f = [];
    }

    private function gzReadExact($gz, int $n): string
    {
        $out = '';
        while (strlen($out) < $n && !gzeof($gz)) {
            $c = gzread($gz, $n - strlen($out));
            if ($c === false || $c === '') {
                break;
            }
            $out .= $c;
        }
        return $out;
    }

    private function gzSkip($gz, int $n): void
    {
        while ($n > 0 && !gzeof($gz)) {
            $c = gzread($gz, min(65536, $n));
            if ($c === false || $c === '') {
                break;
            }
            $n -= strlen($c);
        }
    }

    /** Löst arp-scan samt benötigter Shared Libraries (so:-Abhängigkeiten) auf. */
    private function resolveApk(array $index, string $start): array
    {
        $result = [];
        $queue = [$start];
        while ($queue) {
            $name = array_shift($queue);
            if (in_array($name, $result, true)) {
                continue;
            }
            if (count($result) >= 25) {
                throw new Exception('Zu viele Abhängigkeiten – Abbruch.');
            }
            $result[] = $name;
            foreach ($index['byName'][$name]['deps'] as $dep) {
                if ($dep === '') {
                    continue;
                }
                if (isset($index['prov'][$dep])) {
                    $queue[] = $index['prov'][$dep];
                } else {
                    throw new Exception('Abhängigkeit nicht auflösbar: ' . $dep);
                }
            }
        }
        // musl stellt den Loader – immer mitnehmen
        if (isset($index['byName']['musl']) && !in_array('musl', $result, true)) {
            $result[] = 'musl';
        }
        return $result;
    }

    // ------------------------------------------------------------------
    // Download / Archive
    // ------------------------------------------------------------------

    /** Lädt direkt in eine Datei (kein großer String im Arbeitsspeicher). */
    private function downloadToFile(string $url, string $file, int $timeout = 60): void
    {
        $ua = 'Mozilla/5.0 (compatible; Symcon-ArpScan)';
        if (function_exists('curl_init')) {
            $fh = fopen($file, 'wb');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT      => $ua
            ]);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fh);
            if ($ok === false || $code !== 200) {
                @unlink($file);
                throw new Exception('Download fehlgeschlagen: ' . $url . ' (HTTP ' . $code . ' ' . $err . ')');
            }
            return;
        }
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 1, 'header' => 'User-Agent: ' . $ua . "\r\n"]]);
        if (!@copy($url, $file, $ctx)) {
            @unlink($file);
            throw new Exception('Download fehlgeschlagen: ' . $url . ' (Internetzugang/Zertifikate prüfen)');
        }
    }

    /** Liest .gz inkl. aneinandergehängter gzip-Segmente (APK-Format). */
    private function gzReadAll(string $file): string
    {
        $gz = gzopen($file, 'rb');
        if ($gz === false) {
            throw new Exception('gzip-Datei nicht lesbar.');
        }
        $out = '';
        while (!gzeof($gz)) {
            $chunk = gzread($gz, 1048576);
            if ($chunk === false) {
                break;
            }
            $out .= $chunk;
        }
        gzclose($gz);
        return $out;
    }

    /** Einfacher TAR-Parser (ustar, GNU-Langnamen, PAX-Pfade). */
    private function parseTar(string $data): array
    {
        $entries = [];
        $pos = 0;
        $len = strlen($data);
        $longName = null;
        $pax = [];
        while ($pos + 512 <= $len) {
            $h = substr($data, $pos, 512);
            if (trim($h, "\0") === '') {
                $pos += 512;
                continue;
            }
            $name = rtrim(substr($h, 0, 100), "\0");
            $mode = (int) octdec(trim(substr($h, 100, 8), " \0"));
            $size = (int) octdec(trim(substr($h, 124, 12), " \0"));
            $type = $h[156];
            $link = rtrim(substr($h, 157, 100), "\0");
            if (substr($h, 257, 5) === 'ustar') {
                $prefix = rtrim(substr($h, 345, 155), "\0");
                if ($prefix !== '') {
                    $name = $prefix . '/' . $name;
                }
            }
            $pos += 512;
            $body = substr($data, $pos, $size);
            $pos += (int) (ceil($size / 512) * 512);

            if ($type === 'L') {
                $longName = rtrim($body, "\0");
                continue;
            }
            if ($type === 'x') {
                foreach (explode("\n", $body) as $rec) {
                    if (preg_match('/^\d+ (path|linkpath)=(.*)$/', $rec, $m)) {
                        $pax[$m[1]] = $m[2];
                    }
                }
                continue;
            }
            if ($type === 'g') {
                continue;
            }
            if ($longName !== null) {
                $name = $longName;
                $longName = null;
            }
            if (isset($pax['path'])) {
                $name = $pax['path'];
            }
            if (isset($pax['linkpath'])) {
                $link = $pax['linkpath'];
            }
            $pax = [];

            $name = preg_replace('#^(\./)+#', '', $name);
            $name = rtrim($name, '/');
            $entries[] = ['name' => $name, 'type' => $type, 'mode' => $mode, 'link' => $link, 'data' => $body];
        }
        return $entries;
    }

    private function extractTar(string $tarData, string $dest): void
    {
        $links = [];
        foreach ($this->parseTar($tarData) as $e) {
            $name = $e['name'];
            // Steuerdateien (.PKGINFO, .SIGN…) und unsichere Pfade überspringen
            if ($name === '' || $name[0] === '.' || $name[0] === '/' || in_array('..', explode('/', $name), true)) {
                continue;
            }
            $path = $dest . '/' . $name;
            switch ($e['type']) {
                case '5':
                    @mkdir($path, 0755, true);
                    break;
                case '2': // Symlink -> später als Kopie auflösen
                    $target = $e['link'];
                    $links[$path] = ($target !== '' && $target[0] === '/') ? $dest . $target : dirname($path) . '/' . $target;
                    break;
                case '1': // Hardlink
                    $links[$path] = $dest . '/' . ltrim($e['link'], '/');
                    break;
                case '0':
                case "\0":
                    @mkdir(dirname($path), 0755, true);
                    file_put_contents($path, $e['data']);
                    chmod($path, $e['mode'] & 0777 ?: 0644);
                    break;
            }
        }
        // Links in Kopien umwandeln (mehrere Durchläufe für verkettete Links)
        for ($i = 0; $i < 3 && $links; $i++) {
            foreach ($links as $path => $target) {
                $real = realpath($target);
                if ($real !== false && is_file($real)) {
                    @mkdir(dirname($path), 0755, true);
                    copy($real, $path);
                    chmod($path, fileperms($real) & 0777);
                    unset($links[$path]);
                }
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir() && !$f->isLink()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------------
    // Hersteller
    // ------------------------------------------------------------------

    /** Liest nur die für die gefundenen MACs relevanten Zeilen aus der Hersteller-Datei. */
    private function lookupOui(array $macs): array
    {
        $file = __DIR__ . '/runtime/oui.tsv';
        $wanted = [];
        foreach ($macs as $mac) {
            $hex = strtoupper(str_replace(':', '', $mac));
            foreach ([9, 7, 6] as $l) {
                $wanted[substr($hex, 0, $l)] = true;
            }
        }
        $db = [];
        if (!is_file($file) || !$wanted) {
            return $db;
        }
        $fh = fopen($file, 'r');
        while (($line = fgets($fh)) !== false) {
            $tab = strpos($line, "\t");
            if ($tab !== false) {
                $key = substr($line, 0, $tab);
                if (isset($wanted[$key])) {
                    $db[$key] = rtrim(substr($line, $tab + 1), "\r\n");
                }
            }
        }
        fclose($fh);
        return $db;
    }

    private function vendorFor(string $mac, array $oui, string $arpScanVendor): string
    {
        $hex = strtoupper(str_replace(':', '', $mac));
        foreach ([9, 7, 6] as $l) { // spezifischste Zuordnung zuerst (MA-S, MA-M, MA-L)
            $key = substr($hex, 0, $l);
            if (isset($oui[$key])) {
                return $oui[$key];
            }
        }
        if ($arpScanVendor !== '' && $arpScanVendor[0] !== '(') {
            return $arpScanVendor;
        }
        if ((hexdec(substr($hex, 0, 2)) & 0x02) !== 0) {
            return 'Zufällige/lokale MAC (privat)';
        }
        return 'Unbekannt';
    }

    // ------------------------------------------------------------------
    // Netzwerk-Helfer
    // ------------------------------------------------------------------

    private function detectInterfaces(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->detectInterfacesWindows();
        }
        $result = [];
        $lines = [];
        exec('ip -4 -o addr show scope global 2>/dev/null', $lines);
        foreach ($lines as $line) {
            if (preg_match('/^\d+:\s+(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $m)) {
                $result[] = [
                    'Interface' => explode('@', $m[1])[0],
                    'IP'        => $m[2],
                    'Bitcount'  => (int) $m[3]
                ];
            }
        }
        if (count($result) === 0) {
            $hosts = preg_split('/\s+/', trim((string) @shell_exec('hostname -I 2>/dev/null')));
            foreach ($hosts as $h) {
                if (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $result[] = ['Interface' => '', 'IP' => $h, 'Bitcount' => 24];
                }
            }
        }
        return $result;
    }

    /** Windows: Default-Route per "route print", Subnetzmaske aus "ipconfig" (sprachunabhängig). */
    private function detectInterfacesWindows(): array
    {
        $routeOut = [];
        exec('route print -4 0.0.0.0', $routeOut);
        $defaultIp = '';
        $bestMetric = PHP_INT_MAX;
        foreach ($routeOut as $l) {
            if (preg_match('/^\s*0\.0\.0\.0\s+0\.0\.0\.0\s+\S+\s+(\d+\.\d+\.\d+\.\d+)\s+(\d+)/', $l, $m) && (int) $m[2] < $bestMetric) {
                $bestMetric = (int) $m[2];
                $defaultIp = $m[1];
            }
        }

        $cfg = [];
        exec('ipconfig', $cfg);
        $ips = [];
        foreach ($cfg as $l) {
            if (preg_match('/:\s*(\d+\.\d+\.\d+\.\d+)/', $l, $m)) {
                $ips[] = $m[1];
            }
        }
        $result = [];
        for ($i = 0; $i + 1 < count($ips); $i++) {
            $ip = $ips[$i];
            $mask = ip2long($ips[$i + 1]);
            if ($mask === false || strncmp($ips[$i + 1], '255.', 4) !== 0) {
                continue;
            }
            $bin = decbin($mask);
            if (preg_match('/^1+0*$/', $bin) !== 1 || strncmp($ip, '169.254.', 8) === 0 || strncmp($ip, '127.', 4) === 0) {
                continue;
            }
            $entry = ['Interface' => '', 'IP' => $ip, 'Bitcount' => substr_count($bin, '1')];
            if ($ip === $defaultIp) {
                array_unshift($result, $entry);
            } else {
                $result[] = $entry;
            }
            $i++;
        }
        return $result;
    }

    private function networkAddress(string $ip, int $bits): string
    {
        $mask = $bits === 0 ? 0 : ((~0 << (32 - $bits)) & 0xFFFFFFFF);
        return long2ip(ip2long($ip) & $mask);
    }

    private function buildHtml(array $devices): string
    {
        $html = '<table style="width:100%;border-collapse:collapse;">'
            . '<tr><th align="left">IP</th><th align="left">MAC</th><th align="left">Hersteller</th></tr>';
        foreach ($devices as $d) {
            $html .= '<tr><td>' . htmlspecialchars($d['IP']) . '</td><td>' . htmlspecialchars($d['MAC'])
                . '</td><td>' . htmlspecialchars($d['Vendor']) . '</td></tr>';
        }
        return $html . '</table>';
    }
}
