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

		
	
	
	
	}
