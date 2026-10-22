<?php

declare(strict_types=1);
	class Batfahrplan_octopus extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();

			// 12 Variablen registrieren (Index 1 bis 12)
      $this->RegisterVariablestring("Plan_heute", "Plan heute" ,"", 1);
			$this->RegisterVariablestring("Plan_morgen", "Plan morgen" , "", 2);
			$this->setvalue("Plan_heute"," hallo" );
			$this->setvalue("Plan_morgen",75);


			
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
		public function update(int $Index)
   	 	{
    	    return null;
        	

         }
		
	
	
	}
