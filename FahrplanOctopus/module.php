<?php

declare(strict_types=1);
	class FahrpalnOctopus extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();
			$this->RegisterVariablestring("BatPlan_1", "Batterieplan heute" ,"", 2);
			$this->RegisterVariablestring("BatPlan_2", "Batterieplan morgen" ,"", 2);
			$this->RegisterVariablestring("WBPlan_1", "Wallbox heute" ,"", 2);
  		$this->RegisterVariablestring("WBPlan_2", "Wallbox morgen" ,"", 2);
			
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
		
		
		public function update
    	{

      // Vorgabenspeicher finden
          $settings=IPS_FindObjectIDByName("Batterie-Einstellungen",0);
          $teuer=IPS_GetInstanceIDByName("Batterie-Entladung",$settings);
          $günstig=IPS_GetInstanceIDByName("Batterie-Ladung",$settings);


      // Monat und Vorgaben bestimmen
          $time= (strtotime("tomorrow"));
          $Monat=idate("m",$time);
          $teuer= Jahresvariablen_Get($teuer,$Monat);
          $guenstig= Jahresvariablen_Get($günstig,$Monat);

      // Fahrplan aus Vorgaben erstellen
          for ($i = 0; $i <= 5; $i++) 
          { $BatPlan[$i]=$guenstig;
            $WBPlan[$i]=1;}
          
          for ($i = 5; $i <= 12; $i++) 
              { $BatPlan[$i]=$teuer;
                $WBPlan[$i]=0;}
        
          setvaluestring("BatPlan_2",json_encode($BatPlan));
          setvaluestring("WBPlan_2",json_encode($WBPlan));


   		 }
	

		
}
