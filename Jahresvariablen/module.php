<?php

declare(strict_types=1);
	class Jahresvariablen extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();

			// 12 Variablen registrieren (Index 1 bis 12)
            $this->RegisterVariableInteger("Value_1", "Januar" , "", 1);}
			$this->RegisterVariableInteger("Value_2", "Februar" , "", 2);}
			$this->RegisterVariableInteger("Value_3", "März" , "", 3);}
			$this->RegisterVariableInteger("Value_4", "April" , "", 4);}
			$this->RegisterVariableInteger("Value_5", "Mai" , "", 5);}
			$this->RegisterVariableInteger("Value_6", "Juni" , "", 6);}
			$this->RegisterVariableInteger("Value_7", "Juli" , "", 7);}
			$this->RegisterVariableInteger("Value_8", "August" , "", 8);}
			$this->RegisterVariableInteger("Value_9", "September" , "", 9);}
			$this->RegisterVariableInteger("Value_10", "Oktober" , "", 10);}
			$this->RegisterVariableInteger("Value_11", "November" , "", 11);}
			$this->RegisterVariableInteger("Value_12", "Dezember" , "", 12);}
			

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
		
	public function Set(int $Index, $Value)
    {
        if ($Index < 0 || $Index > 11) {
            trigger_error("Index liegt außerhalb des gültigen Bereichs (0 - 11)", E_USER_WARNING);
            return false;
        }

        $this->SetValue("Value_" . $Index, $Value);
        return true;
    }
	
	
	}
