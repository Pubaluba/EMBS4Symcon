<?php

// Diagnoseskript fuer das Zielsystem (SymBox, Windows, Docker ...).
// Als PHP-Skript in Symcon anlegen, ausfuehren und die Ausgabe zusammen mit Fehlermeldungen weitergeben (F7).
// Aendert nichts am System; schreibt nur eine temporaere Testdatei in IPS_GetKernelDir() und loescht sie wieder.

$line = function (string $k, $v): void {
    echo str_pad($k, 28) . (is_bool($v) ? ($v ? 'ja' : 'nein') : $v) . "\n";
};

$line('php_uname', php_uname());
$line('PHP_VERSION', PHP_VERSION);
$line('PHP_OS_FAMILY', PHP_OS_FAMILY);
$line('Architektur (php_uname m)', php_uname('m'));
$line('memory_limit', ini_get('memory_limit'));
$line('max_execution_time', ini_get('max_execution_time'));
$line('disable_functions', ini_get('disable_functions') ?: '(leer)');

if (function_exists('IPS_GetKernelVersion')) {
    $line('Symcon Kernel', IPS_GetKernelVersion());
    $line('Kernel-Verzeichnis', IPS_GetKernelDir());
    $probe = rtrim(IPS_GetKernelDir(), '/\\') . DIRECTORY_SEPARATOR . 'diagnose_probe.tmp';
    $ok = @file_put_contents($probe, 'x') !== false;
    $line('Kernel-Verz. beschreibbar', $ok);
    if ($ok) {
        @unlink($probe);
    }
}

foreach (['curl', 'zlib', 'openssl', 'json', 'mbstring', 'sockets'] as $ext) {
    $line('Erweiterung ' . $ext, extension_loaded($ext));
}
foreach (['exec', 'shell_exec', 'proc_open', 'stream_socket_client'] as $fn) {
    $line('Funktion ' . $fn, function_exists($fn));
}

if (function_exists('exec') && PHP_OS_FAMILY !== 'Windows') {
    $out = [];
    $rc = -1;
    exec('id 2>&1', $out, $rc);
    $line('id (Benutzer/Gruppen)', $rc === 0 ? implode(' ', $out) : "Fehler rc=$rc");
    $out = [];
    exec('command -v arp-scan 2>&1', $out, $rc);
    $line('arp-scan im System', $rc === 0 ? implode(' ', $out) : 'nein');
}
