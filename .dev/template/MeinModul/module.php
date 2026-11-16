<?php

// Bewusst KEIN declare(strict_types=1): Formularwerte kommen teils als anderer Typ an (docs/RULES.md, P001).
// Basisklasse IPSModuleStrict: ab Symcon 8.1, verlangt Type Hints bei allen öffentlichen Funktionen (P003).

require_once __DIR__ . '/../libs/EPHelper.php';

class MeinModul extends IPSModuleStrict
{
    use EPHelper;

    private const LOCK_SECONDS = 600;

    public function Create(): void
    {
        // Diese Zeile nicht löschen
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Interval', 5);
        $this->RegisterAttributeString('LastMessage', '');

        $this->RegisterTimer('CheckTimer', 0, 'MMOD_TimerCheck($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        $this->RegisterVariableInteger('LastRun', 'Letzter Lauf', '~UnixTimestamp', 10);

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetTimerInterval('CheckTimer', 0);
            $this->SetStatus(104);
            return;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $this->SetTimerInterval('CheckTimer', 0);
            $this->SetStatus(201);
            return;
        }

        $minutes = max(0, $this->ReadPropertyInteger('Interval'));
        $this->SetTimerInterval('CheckTimer', $minutes * 60 * 1000);
        $this->SetStatus(102);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $message = $this->ReadAttributeString('LastMessage');
        foreach ($form['actions'] as &$element) {
            if (($element['name'] ?? '') === 'StatusLabel') {
                $element['caption'] = $message !== '' ? $message : 'Bereit.';
            }
        }
        unset($element);
        return json_encode($form);
    }

    /**
     * Beispiel für eine öffentliche Funktion: MMOD_Check($id, $Host)
     * Parameter nullable, leere Werte fallen auf die gespeicherte Konfiguration zurück (F1).
     */
    public function Check(?string $Host = ''): string
    {
        $host = trim((string) $Host);
        if ($host === '') {
            $host = trim($this->ReadPropertyString('Host'));
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $this->fail('Ungültige IPv4-Adresse: ' . $host);
            return 'Fehler: ungültige IPv4-Adresse';
        }

        $message = 'Host ' . $host . ' ist eine gültige IPv4-Adresse';

        $this->SetValue('LastRun', time());
        $this->WriteAttributeString('LastMessage', $message);
        $this->SendDebug('Check', $message, 0);
        $this->UpdateFormField('StatusLabel', 'caption', $message);
        return $message;
    }

    /** Wird vom Timer aufgerufen; Sperre verhindert überlappende Läufe. */
    public function TimerCheck(): void
    {
        if ((int) $this->GetBuffer('RunLock') > time()) {
            return;
        }
        $this->SetBuffer('RunLock', (string) (time() + self::LOCK_SECONDS));
        try {
            $this->Check('');
        } finally {
            $this->SetBuffer('RunLock', '0');
        }
    }
}
