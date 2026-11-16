<?php

/**
 * Wiederverwendbare Hilfsfunktionen für Symcon-Module (Trait).
 * Setzt voraus, dass die verwendende Klasse von IPSModule bzw. IPSModuleStrict erbt.
 *
 * Regeln, die hier umgesetzt sind (docs/RULES.md):
 *  - Laufzeitdaten außerhalb des Modulordners (F3)
 *  - Downloads direkt in Dateien, nie als String (F2)
 *  - Shell-Aufrufe nur mit escapeshellarg und mit Rückgabecode (Sicherheit)
 *  - Fehler sichtbar als Warnung, Details im Debug
 */
trait EPHelper
{
    /**
     * Verzeichnis für Laufzeitdaten: <Kernel-Verzeichnis>/<Name>. Nie im Modulordner.
     */
    protected function runtimeDir(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new InvalidArgumentException('Ungültiger Verzeichnisname: ' . $name);
        }
        $dir = rtrim(IPS_GetKernelDir(), '/\\') . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Verzeichnis nicht anlegbar: ' . $dir);
        }
        return $dir;
    }

    /**
     * Lädt eine HTTPS-URL direkt in eine Datei (kein PHP-String, 32-MB-Limit beachten).
     */
    protected function downloadToFile(string $url, string $file, int $timeout = 300): void
    {
        if (!preg_match('#^https://#i', $url)) {
            throw new InvalidArgumentException('Nur HTTPS-Downloads sind erlaubt: ' . $url);
        }
        $fh = fopen($file, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Datei nicht schreibbar: ' . $file);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Symcon-Modul)',
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        fclose($fh);
        if ($ok === false || $code !== 200) {
            @unlink($file);
            throw new RuntimeException('Download fehlgeschlagen: ' . $url . ' (HTTP ' . $code . ')');
        }
    }

    /**
     * Führt ein Programm aus. Programm und jedes Argument werden mit escapeshellarg geschützt.
     * Argumente trotzdem vorher validieren (filter_var, Regex, Zahlenbereiche).
     *
     * @return array{0:int,1:array<int,string>} [Rückgabecode, Ausgabezeilen]
     */
    protected function runCommand(string $binary, array $args = []): array
    {
        $cmd = escapeshellarg($binary);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg((string) $arg);
        }
        $output = [];
        $rc = -1;
        exec($cmd . ' 2>&1', $output, $rc);
        return [$rc, $output];
    }

    /**
     * Meldet einen Fehler: Warnung in der Konsole, technische Details im Debug-Fenster.
     */
    protected function fail(string $message, string $debug = ''): void
    {
        $this->SendDebug('Fehler', $debug !== '' ? $debug : $message, 0);
        trigger_error($message, E_USER_WARNING);
    }

    /**
     * 'Windows', 'Linux', 'Darwin' ... für plattformabhängige Wege (F6).
     */
    protected function platform(): string
    {
        return PHP_OS_FAMILY;
    }
}
