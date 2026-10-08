<?php

declare(strict_types=1);
<?php

class EMBSTest extends IPSModule
{
    public function Create()
    {
        // Immer zuerst die Elternmethode aufrufen
        parent::Create();

        // 12 Variablen registrieren (Index 0 bis 11)
        for ($i = 0; $i < 12; $i++) {
            // RegisterVariableVariant erlaubt flexibel Zahlen, Booleans oder Strings
            $this->RegisterVariableVariant("Value_" . $i, "Variable " . $i, "", $i);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    /**
     * Liest den Wert der Variable anhand der Indexnummer (0 bis 11)
     * 
     * @param int $Index
     * @return mixed
     */
    public function GetValue(int $Index)
    {
        if ($Index < 0 || $Index > 11) {
            trigger_error("Index liegt außerhalb des gültigen Bereichs (0 - 11)", E_USER_WARNING);
            return null;
        }

        return $this->GetValue("Value_" . $Index);
    }

    /**
     * Schreibt den Wert in die Variable anhand der Indexnummer (0 bis 11)
     * 
     * @param int $Index
     * @param mixed $Value
     * @return bool
     */
    public function SetValue(int $Index, $Value)
    {
        if ($Index < 0 || $Index > 11) {
            trigger_error("Index liegt außerhalb des gültigen Bereichs (0 - 11)", E_USER_WARNING);
            return false;
        }

        $this->SetValue("Value_" . $Index, $Value);
        return true;
    }
}
