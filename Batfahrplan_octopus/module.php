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
			$this->setvalue("Plan_heute","" );
			$this->setvalue("Plan_morgen","");


			
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
    	    
			$time= strtotime("today");
			$monat = idate("m",$time);
			$Vorgabeteuer=get
			$Vorgabegünstig=
			$Soll_guenstig=
			$Soll_teuer=
			
			for ($i=0;$i<24;$i++)
     { 
    switch($i)
    {
        case $Preisstunde[0]:
        case $Preisstunde[1]:
       case $Preisstunde[2]:
       case $Preisstunde[3]:
       case $Preisstunde[4]:
       $plan[$i]=$Soll_guenstig;    break;
       
        default:
          $plan[$i]=$Soll_teuer;            break;
			
		     	

         }
		
	
	
	}
