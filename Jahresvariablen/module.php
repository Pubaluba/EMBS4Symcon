<?php

declare(strict_types=1);
	class Jahresvariablen extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();

			// 12 Variablen registrieren (Index 0 bis 11)
        for ($i = 0; $i < 12; $i++) {
            // RegisterVariableVariant erlaubt flexibel Zahlen, Booleans oder Strings
            $this->RegisterVariableInteger("Value_" . $i, "Variable " . $i, "", $i);}
		}

		public function Destroy()
		{
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges()
		{
			//Never delete this line!
			parent::ApplyChanges();
		}
		public function Get(int $Index)
   	 	{
    	    if ($Index < 0 || $Index > 11) {
            trigger_error("Index liegt außerhalb des gültigen Bereichs (0 - 11)", E_USER_WARNING);
            return null;
        	}

        return $this->GetValue("Value_" . $Index);
    }
		
	
	
	
	}
