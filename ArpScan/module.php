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
                file_put_contents($tmp, $this->download($url, 120));
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
            $dir = $this->runtimeDir();
            $tmpFile = $dir . '/oui.tsv.tmp';
            $out = fopen($tmpFile, 'w');
            $count = 0;
            foreach (self::OUI_URLS as $url) {
                $csv = $this->download($url, 180);
                $fh = fopen('php://temp', 'r+');
                fwrite($fh, $csv);
                rewind($fh);
                unset($csv);
                fgetcsv($fh, 0, ',', '"', ''); // Kopfzeile
                while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                    if (count($r) >= 3 && preg_match('/^[0-9A-Fa-f]{6,9}$/', $r[1])) {
                        fwrite($out, strtoupper($r[1]) . "\t" . trim(preg_replace('/\s+/', ' ', $r[2])) . "\n");
                        $count++;
                    }
                }
                fclose($fh);
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
        $target = $this->networkAddress($IP, $Bitcount) . '/' . $Bitcount;

        // arp-scan bereitstellen (System, eigener Pfad oder automatisch installiert)
        try {
            [$binary, $bundled] = $this->binaryCommand();
        } catch (Throwable $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
            return [];
        }

        // Hersteller-Datenbank bei Bedarf einmalig laden (nicht kritisch)
        if (!is_file($this->runtimeDir() . '/oui.tsv')) {
            $r = $this->UpdateVendorDatabase();
            $this->SendDebug('OUI', $r, 0);
        }

        $cmd = ($this->ReadPropertyBoolean('UseSudo') ? 'sudo -n ' : '') . $binary
            . ' --plain --quiet --retry=2 --timeout=' . max(50, $this->ReadPropertyInteger('Timeout'));
        if ($bundled) {
            // leere Vendor-Dateien: Herstellernamen kommen aus der IEEE-Liste dieses Moduls
            $empty = $this->runtimeDir() . '/empty.txt';
            if (!is_file($empty)) {
                file_put_contents($empty, '');
            }
            $cmd .= ' --ouifile=' . escapeshellarg($empty) . ' --macfile=' . escapeshellarg($empty);
        }
        $interface = trim($this->ReadPropertyString('Interface'));
        if ($interface !== '') {
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $interface)) {
                trigger_error('Ungültiger Interface-Name.', E_USER_WARNING);
                return [];
            }
            $cmd .= ' --interface=' . escapeshellarg($interface);
        }
        $cmd .= ' ' . escapeshellarg($target) . ' 2>&1';

        $this->SendDebug('Command', $cmd, 0);
        $output = [];
        $rc = 0;
        exec($cmd, $output, $rc);
        $this->SendDebug('Output', implode("\n", $output), 0);

        $oui = $this->loadOui();
        $devices = [];
        foreach ($output as $line) {
            if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+([0-9a-f]{2}(?::[0-9a-f]{2}){5})\s*(.*)$/i', $line, $m)) {
                $mac = strtolower($m[2]);
                $own = trim(preg_replace('/\s*\(DUP: \d+\)\s*$/', '', $m[3]));
                $devices[$m[1] . '|' . $mac] = [
                    'IP'     => $m[1],
                    'MAC'    => $mac,
                    'Vendor' => $this->vendorFor($mac, $oui, $own)
                ];
            }
        }
        $devices = array_values($devices);

        if ($rc !== 0 && count($devices) === 0) {
            trigger_error('arp-scan fehlgeschlagen (Code ' . $rc . '): ' . implode(' | ', $output)
                . ' – fehlen Root-Rechte? Siehe Hinweis zu sudo.', E_USER_WARNING);
            return [];
        }

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

    private function loadApkIndex(array &$index, string $url, string $repo): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'apkindex');
        try {
            file_put_contents($tmp, $this->download($url, 120));
            $text = '';
            foreach ($this->parseTar($this->gzReadAll($tmp)) as $entry) {
                if ($entry['name'] === 'APKINDEX') {
                    $text = $entry['data'];
                    break;
                }
            }
        } finally {
            @unlink($tmp);
        }
        if ($text === '') {
            throw new Exception('APKINDEX leer oder nicht lesbar: ' . $url);
        }
        foreach (preg_split("/\n\n/", $text) as $block) {
            $f = [];
            foreach (explode("\n", $block) as $line) {
                if (strlen($line) > 2 && $line[1] === ':') {
                    $f[$line[0]] = substr($line, 2);
                }
            }
            if (!isset($f['P'], $f['V']) || isset($index['byName'][$f['P']])) {
                continue;
            }
            $index['byName'][$f['P']] = [
                'ver'  => $f['V'],
                'repo' => $repo,
                'deps' => isset($f['D']) ? preg_split('/\s+/', trim($f['D'])) : []
            ];
            $provides = array_merge([$f['P']], isset($f['p']) ? preg_split('/\s+/', trim($f['p'])) : []);
            foreach ($provides as $tok) {
                $tok = preg_replace('/[<>=~].*$/', '', $tok);
                if ($tok !== '' && !isset($index['prov'][$tok])) {
                    $index['prov'][$tok] = $f['P'];
                }
            }
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
                if ($dep === '' || $dep[0] === '!' || strncmp($dep, 'so:', 3) !== 0) {
                    continue;
                }
                $dep = preg_replace('/[<>=~].*$/', '', $dep);
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

    private function download(string $url, int $timeout = 60): string
    {
        $ua = 'Mozilla/5.0 (compatible; Symcon-ArpScan)';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT      => $ua
            ]);
            $data = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($data !== false && $code === 200) {
                return $data;
            }
            throw new Exception('Download fehlgeschlagen: ' . $url . ' (HTTP ' . $code . ' ' . $err . ')');
        }
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 1, 'header' => 'User-Agent: ' . $ua . "\r\n"]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            throw new Exception('Download fehlgeschlagen: ' . $url . ' (Internetzugang/Zertifikate prüfen)');
        }
        return $data;
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

    private function loadOui(): array
    {
        $file = __DIR__ . '/runtime/oui.tsv';
        $db = [];
        if (!is_file($file)) {
            return $db;
        }
        $fh = fopen($file, 'r');
        while (($line = fgets($fh)) !== false) {
            $p = explode("\t", rtrim($line, "\r\n"), 2);
            if (count($p) === 2) {
                $db[$p[0]] = $p[1];
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
