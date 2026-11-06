<?php

class ArpScan extends IPSModule
{
    public function Create()
    {
        // Diese Zeile nicht löschen
        parent::Create();

        $this->RegisterPropertyString('ArpScanPath', '/usr/sbin/arp-scan');
        $this->RegisterPropertyBoolean('UseSudo', false);
        $this->RegisterPropertyString('Interface', '');
        $this->RegisterPropertyInteger('Timeout', 500);
        $this->RegisterPropertyString('Network', '');
        $this->RegisterPropertyInteger('Bitcount', 24);

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
        }
        unset($element);
        return json_encode($form);
    }

    /**
     * ARPS_GetIP($id)
     * Ermittelt die eigene IPv4-Adresse (Interface der Default-Route, sonst erstes Interface).
     * Trägt IP und Netzwerk in die Statusvariablen und das Formular ein.
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
     * ARPS_ScanNetwork($id, '192.168.1.0', 24)
     * Führt arp-scan aus. Leere Parameter -> Werte aus der Konfiguration bzw. eigenes Netzwerk.
     * Rückgabe: Array aus ['IP', 'MAC', 'Vendor'], nach IP sortiert.
     */
    public function ScanNetwork(string $IP = '', int $Bitcount = 0): array
    {
        $IP = trim($IP);
        if ($IP === '') {
            $IP = trim($this->ReadPropertyString('Network'));
        }
        if ($Bitcount <= 0) {
            $Bitcount = $this->ReadPropertyInteger('Bitcount');
        }
        // "192.168.1.0/24" als einzelner Parameter erlaubt
        if (strpos($IP, '/') !== false) {
            [$IP, $bits] = explode('/', $IP, 2);
            $Bitcount = (int) $bits;
        }
        // Nichts angegeben -> eigenes Netzwerk
        if ($IP === '') {
            $own = $this->GetIP();
            if ($own === '') {
                return [];
            }
            [$IP, $bits] = explode('/', $this->GetValue('LocalNetwork'), 2);
            $Bitcount = (int) $bits;
        }

        // Strikte Validierung (Schutz vor Shell-Injection)
        if (filter_var($IP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            trigger_error('Ungültige IPv4-Adresse: ' . $IP, E_USER_WARNING);
            return [];
        }
        if ($Bitcount < 16 || $Bitcount > 32) {
            trigger_error('Bitcount muss zwischen 16 und 32 liegen.', E_USER_WARNING);
            return [];
        }
        $target = $this->networkAddress($IP, $Bitcount) . '/' . $Bitcount;

        $path = $this->ReadPropertyString('ArpScanPath');
        if (!is_file($path)) {
            trigger_error('arp-scan nicht gefunden unter ' . $path . ' (Installation: apt install arp-scan)', E_USER_WARNING);
            return [];
        }

        $cmd = ($this->ReadPropertyBoolean('UseSudo') ? 'sudo -n ' : '')
            . escapeshellarg($path)
            . ' --plain --quiet --retry=2 --timeout=' . max(50, $this->ReadPropertyInteger('Timeout'));
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

        $devices = [];
        foreach ($output as $line) {
            if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+([0-9a-f]{2}(?::[0-9a-f]{2}){5})\s*(.*)$/i', $line, $m)) {
                $vendor = trim(preg_replace('/\s*\(DUP: \d+\)\s*$/', '', $m[3]));
                $devices[$m[1] . '|' . strtolower($m[2])] = [
                    'IP'     => $m[1],
                    'MAC'    => strtolower($m[2]),
                    'Vendor' => $vendor
                ];
            }
        }
        $devices = array_values($devices);

        if ($rc !== 0 && count($devices) === 0) {
            trigger_error('arp-scan fehlgeschlagen (Code ' . $rc . '): ' . implode(' | ', $output)
                . ' – fehlen Root-Rechte? Siehe Hinweis zu sudo/setcap.', E_USER_WARNING);
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
        // Fallback ohne "ip"-Befehl
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
