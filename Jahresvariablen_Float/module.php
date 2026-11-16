<?php

// Bewusst KEIN declare(strict_types=1) (docs/RULES.md, P001).

/**
 * Zwölf Monatswerte (Kommazahl) als Variablen.
 * Zugriff per EMBSVARFloat_GET($id, $Monat) / EMBSVARFloat_SET($id, $Monat, $Wert) oder über die Visualisierung.
 * Präfix bis Version 1.0: EMBS_VAR_Float (laut Symcon-Doku nicht zulässig, daher umbenannt).
 */
class Jahresvariablen_Float extends IPSModuleStrict
{
    private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    private const DEFAULTS = [85.0, 75.0, 60.0, 15.0, 10.0, 10.0, 10.0, 10.0, 15.0, 60.0, 75.0, 85.0];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        // Create() läuft auch bei jedem Start von Symcon: Standardwerte nur für neue Variablen setzen.
        $isNew = @IPS_GetObjectIDByIdent('Value_1', $this->InstanceID) === false;
        foreach (self::MONTHS as $i => $name) {
            $ident = 'Value_' . ($i + 1);
            $this->RegisterVariableFloat($ident, $name, '', $i + 1);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, self::DEFAULTS[$i]);
            }
        }
    }

    public function Destroy(): void
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();
    }

    /** Bedienung in der Visualisierung (wird durch EnableAction aufgerufen). */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        if (!preg_match('/^Value_([1-9]|1[0-2])$/', $Ident)) {
            throw new Exception('Unbekannter Ident: ' . $Ident);
        }
        $this->SetValue($Ident, (float)$Value);
    }

    /** Wert eines Monats (1 = Januar … 12 = Dezember), null bei ungültigem Index. */
    public function GET(int $Index): ?float
    {
        if ($Index < 1 || $Index > 12) {
            trigger_error('Index liegt außerhalb des gültigen Bereichs (1 - 12)', E_USER_WARNING);
            return null;
        }
        return (float)$this->GetValue('Value_' . $Index);
    }

    public function SET(int $Index, float $Value): bool
    {
        if ($Index < 1 || $Index > 12) {
            trigger_error('Index liegt außerhalb des gültigen Bereichs (1 - 12)', E_USER_WARNING);
            return false;
        }
        $this->SetValue('Value_' . $Index, $Value);
        $this->SendDebug('SET', 'Value_' . $Index . ' = ' . $Value, 0);
        return true;
    }
}
