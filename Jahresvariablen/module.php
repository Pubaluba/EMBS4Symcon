<?php

declare(strict_types=1);
	class Jahresvariablen extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();

			// 12 Variablen registrieren (Index 1 bis 12)
            $this->RegisterVariableInteger("Value_1", "Januar" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~Batterie.100',], 1);
			$this->RegisterVariableInteger("Value_2", "Februar" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~Batterie.100',], 2);
			$this->RegisterVariableInteger("Value_3", "März" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~Batterie.100',], 3);
			$this->RegisterVariableInteger("Value_4", "April" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~Batterie.100',], 4);
			$this->RegisterVariableInteger("Value_5", "Mai" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~Batterie.100',], 5);
			$this->RegisterVariableInteger("Value_6", "Juni" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~Batterie.100',], 6);
			$this->RegisterVariableInteger("Value_7", "Juli" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~Batterie.100',], 7);
			$this->RegisterVariableInteger("Value_8", "August" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~Batterie.100',], 8);
			$this->RegisterVariableInteger("Value_9", "September" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~Batterie.100',], 9);
			$this->RegisterVariableInteger("Value_10", "Oktober" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~Batterie.100',], 10);
			$this->RegisterVariableInteger("Value_11", "November" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~Batterie.100',], 11);
			$this->RegisterVariableInteger("Value_12", "Dezember" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~Batterie.100',], 12);
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
    	    if ($Index < 1 || $Index > 12) {
            trigger_error("Index liegt außerhalb des gültigen Bereichs (1 - 12)", E_USER_WARNING);
            return null;
        	}

        return $this->GetValue("Value_" . $Index);
    }
		
	public function Set(int $Index, $Value)
    {
        if ($Index < 1 || $Index > 12) {
            trigger_error("Index liegt außerhalb des gültigen Bereichs (1 - 12)", E_USER_WARNING);
            return false;
        }

        $this->SetValue("Value_" . $Index, $Value);
        return true;
    }
	
	
	}
