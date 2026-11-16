<?php

/**
 * Dauerhafter Tagesspeicher für die Profile EINER Variablen (eine Datei je Variable und Messart-Einstellung).
 *
 * Zweck: Mehrjährige Verläufe nicht bei jeder Neuberechnung erneut aus dem Archiv lesen. Abgeschlossene Tage
 * ändern sich nicht; sie werden einmal gelesen und hier abgelegt.
 *
 * Format: feste Datensätze à 389 Byte, angehängt: Tag (YYYYMMDD, uint32) + Status (uint8) + 96 × int32 (W).
 * Status 1 = Profil gültig, 0 = zu wenig Daten an diesem Tag. Ein später angehängter Datensatz desselben Tages
 * ersetzt den früheren. Im Speicher bleiben die Profile gepackt (384 Byte je Tag), damit auch fünf Jahre mit
 * mehreren Variablen weit unter dem 32-MB-Limit eines PHP-Threads bleiben.
 */
final class EPDayCache
{
    private const RECORD = 389;
    private const MAGIC = "EPDC1\n";

    /** @var string */
    private $file;
    /** @var array<int, string> Tag => Status-Byte + gepacktes Profil */
    private $days = [];
    /** @var int Anzahl der Datensätze in der Datei (inkl. ersetzter) */
    private $records = 0;

    public function __construct(string $file)
    {
        $this->file = $file;
        $this->load();
    }

    private function load(): void
    {
        $this->days = [];
        $this->records = 0;
        if (!is_file($this->file)) {
            return;
        }
        $raw = (string)@file_get_contents($this->file);
        if (strncmp($raw, self::MAGIC, strlen(self::MAGIC)) !== 0) {
            return;   // unbekanntes Format: wird beim nächsten Schreiben neu angelegt
        }
        $len = strlen($raw);
        for ($p = strlen(self::MAGIC); $p + self::RECORD <= $len; $p += self::RECORD) {
            $day = unpack('V', substr($raw, $p, 4))[1];
            $this->days[$day] = substr($raw, $p + 4, self::RECORD - 4);
            $this->records++;
        }
    }

    public function has(int $day): bool
    {
        return isset($this->days[$day]);
    }

    /** Status des Tages: 1 gültig, 0 ohne ausreichende Daten, null = nicht im Speicher. */
    public function status(int $day): ?int
    {
        return isset($this->days[$day]) ? ord($this->days[$day][0]) : null;
    }

    /** @return float[]|null 96 Werte in W oder null (nicht gespeichert oder ohne Daten) */
    public function get(int $day): ?array
    {
        if (!isset($this->days[$day]) || ord($this->days[$day][0]) !== 1) {
            return null;
        }
        return array_map('floatval', array_values(unpack('l96', substr($this->days[$day], 1))));
    }

    /** Mittlere Leistung über den Tag in W (schneller als get(), wenn nur die Tagesenergie gebraucht wird). */
    public function dayMeanW(int $day): ?float
    {
        $p = $this->get($day);
        return $p === null ? null : array_sum($p) / count($p);
    }

    /**
     * Tag ablegen. $profile = 96 Werte (W) oder null für „zu wenig Daten“.
     *
     * @param float[]|null $profile
     */
    public function put(int $day, ?array $profile): void
    {
        $vals = array_fill(0, 96, 0);
        if ($profile !== null) {
            foreach (array_values($profile) as $i => $v) {
                if ($i < 96) {
                    $vals[$i] = (int)max(-2147483647, min(2147483647, round((float)$v)));
                }
            }
        }
        $rec = chr($profile === null ? 0 : 1) . pack('l96', ...$vals);
        $this->days[$day] = $rec;
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $new = !is_file($this->file) || filesize($this->file) === 0;
        $fh = @fopen($this->file, $new ? 'wb' : 'ab');
        if ($fh === false) {
            throw new RuntimeException('Tagesspeicher nicht beschreibbar: ' . $this->file);
        }
        if ($new) {
            fwrite($fh, self::MAGIC);
            $this->records = 0;
        }
        fwrite($fh, pack('V', $day) . $rec);
        fclose($fh);
        $this->records++;
    }

    /** Datei neu schreiben, wenn mehr als ein Viertel der Datensätze ersetzt ist. */
    public function compactIfNeeded(): void
    {
        $live = count($this->days);
        if ($this->records <= 50 || $this->records <= $live * 1.25) {
            return;
        }
        ksort($this->days);
        $tmp = $this->file . '.tmp';
        $fh = @fopen($tmp, 'wb');
        if ($fh === false) {
            return;
        }
        fwrite($fh, self::MAGIC);
        foreach ($this->days as $day => $rec) {
            fwrite($fh, pack('V', $day) . $rec);
        }
        fclose($fh);
        if (@rename($tmp, $this->file)) {
            $this->records = $live;
        } else {
            @unlink($tmp);
        }
    }

    /** @return int[] gespeicherte Tage mit gültigem Profil */
    public function validDays(): array
    {
        $out = [];
        foreach ($this->days as $day => $rec) {
            if (ord($rec[0]) === 1) {
                $out[] = $day;
            }
        }
        return $out;
    }

    public function count(): int
    {
        return count($this->days);
    }

    public function clear(): void
    {
        $this->days = [];
        $this->records = 0;
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }
}
