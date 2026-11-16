<?php

// Bewusst KEIN declare(strict_types=1) (docs/RULES.md, P001).

/**
 * Zwölf Monatswerte (Ganzzahl, 0–100 %) als Variablen, z. B. für monatsabhängige Sollwerte.
 * Zugriff per Jahresvariablen_Get($id, $Monat) / Jahresvariablen_Set($id, $Monat, $Wert) oder über die Visualisierung.
 */
class Jahresvariablen extends IPSModuleStrict
{
    private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    private const DEFAULTS = [85, 75, 60, 15, 10, 10, 10, 10, 15, 60, 75, 85];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        // Create() läuft auch bei jedem Start von Symcon: Standardwerte nur setzen, wenn die Variablen neu sind,
        // sonst würden selbst eingestellte Monatswerte bei jedem Neustart überschrieben.
        $isNew = @IPS_GetObjectIDByIdent('Value_1', $this->InstanceID) === false;
        foreach (self::MONTHS as $i => $name) {
            $ident = 'Value_' . ($i + 1);
            $this->RegisterVariableInteger($ident, $name, ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'PROFILE' => '~Batterie.100'], $i + 1);
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
        if (!$this->isMonthIdent($Ident)) {
            throw new Exception('Unbekannter Ident: ' . $Ident);
        }
        $this->SetValue($Ident, (int)$Value);
    }

    /** Wert eines Monats (1 = Januar … 12 = Dezember), null bei ungültigem Index. */
    public function Get(int $Index): ?int
    {
        if ($Index < 1 || $Index > 12) {
            trigger_error('Index liegt außerhalb des gültigen Bereichs (1 - 12)', E_USER_WARNING);
            return null;
        }
        return (int)$this->GetValue('Value_' . $Index);
    }

    public function Set(int $Index, int $Value): bool
    {
        if ($Index < 1 || $Index > 12) {
            trigger_error('Index liegt außerhalb des gültigen Bereichs (1 - 12)', E_USER_WARNING);
            return false;
        }
        $this->SetValue('Value_' . $Index, $Value);
        $this->SendDebug('Set', 'Value_' . $Index . ' = ' . $Value, 0);
        return true;
    }

    /** Wert per Ident setzen (Value_1 … Value_12). */
    public function Setbyident(string $Ident, int $Value): bool
    {
        if (!$this->isMonthIdent($Ident)) {
            trigger_error('Unbekannter Ident: ' . $Ident . ' (erlaubt: Value_1 bis Value_12)', E_USER_WARNING);
            return false;
        }
        $this->SetValue($Ident, $Value);
        $this->SendDebug('Setbyident', $Ident . ' = ' . $Value, 0);
        return true;
    }

    private function isMonthIdent(string $ident): bool
    {
        return (bool)preg_match('/^Value_([1-9]|1[0-2])$/', $ident);
    }
}
