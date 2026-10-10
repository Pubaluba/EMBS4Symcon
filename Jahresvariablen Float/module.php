<?php

declare(strict_types=1);
	class Jahresvariablen Float extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();

			// 12 Variablen registrieren (Index 1 bis 12)
            $this->RegisterVariableFloat("Value_1", "Januar" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~Intensity.1',], 1);
			$this->RegisterVariableFloat("Value_2", "Februar" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~~Intensity.1',], 2);
			$this->RegisterVariableFloat("Value_3", "März" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~~Intensity.1',], 3);
			$this->RegisterVariableFloat("Value_4", "April" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~~Intensity.1',], 4);
			$this->RegisterVariableFloat("Value_5", "Mai" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~~Intensity.1',], 5);
			$this->RegisterVariableFloat("Value_6", "Juni" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~~Intensity.1',], 6);
			$this->RegisterVariableFloat("Value_7", "Juli" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~~Intensity.1',], 7);
			$this->RegisterVariableFloat("Value_8", "August" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~~Intensity.1',], 8);
			$this->RegisterVariableFloat("Value_9", "September" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~~Intensity.1',], 9);
			$this->RegisterVariableFloat("Value_10", "Oktober" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~~Intensity.1',], 10);
			$this->RegisterVariableFloat("Value_11", "November" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~~Intensity.1',], 11);
			$this->RegisterVariableFloat("Value_12", "Dezember" , ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,    'PROFILE' => '~~Intensity.1',], 12);
			$this->EnableAction("Value_1");
			$this->EnableAction("Value_2");
			$this->EnableAction("Value_3");
			$this->EnableAction("Value_4");
			$this->EnableAction("Value_5");
			$this->EnableAction("Value_6");
			$this->EnableAction("Value_7");
			$this->EnableAction("Value_8");
			$this->EnableAction("Value_9");
			$this->EnableAction("Value_10");
			$this->EnableAction("Value_11");
			$this->EnableAction("Value_12");
			$this->setvalue("Value_1",85);
			$this->setvalue("Value_2",75);
			$this->setvalue("Value_3",60);
			$this->setvalue("Value_4",15);
			$this->setvalue("Value_5",10);
			$this->setvalue("Value_6",10);
			$this->setvalue("Value_7",10);
			$this->setvalue("Value_8",10);
			$this->setvalue("Value_9",15);
			$this->setvalue("Value_10",60);
			$this->setvalue("Value_11",75);
			$this->setvalue("Value_12",85);

			
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
