<?php

declare(strict_types=1);
	class Fahrplan_octopus extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();
			$this->RegisterVariableString("BatPlan_1", "Batterieplan heute" ,"", 1);
			$this->RegisterVariableString("BatPlan_2", "Batterieplan morgen" ,"", 2);
			$this->RegisterVariableString("WBPlan_1", "Wallbox heute" ,"", 3);
  			$this->RegisterVariableString("WBPlan_2", "Wallbox morgen" ,"", 4);
			
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
