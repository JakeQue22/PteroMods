#ifdef ND_MISSIONS
modded class RLGroupUI {

	void AddCustomMarkersOnMapOpen(MapWidget mapWidget_, RLMapMarkerManager mapMarkerMgr) {
		super.AddCustomMarkersOnMapOpen(mapWidget_, mapMarkerMgr);
		if (GetGame() && GetGame().GetPlayer()) {
			CustomNDShowAllMissions(mapWidget_, mapMarkerMgr);
		}
	}
	
	void AddCustomMarkers(MapWidget mapWidget_, RLMapMarkerManager mapMarkerMgr) {
		if (GetGame() && GetGame().GetPlayer()) {
			CustomNDShowAllMissions(mapWidget_, mapMarkerMgr);
		}
	}
	
	void CustomNDShowAllMissions(MapWidget mapWidget_, RLMapMarkerManager mapMarkerMgr) {
		if (!mapWidget_ || !mapMarkerMgr)
			return;
		Man player = GetGame().GetPlayer();

		if ( alp_ND_base.HasWalkieTalkie() ) 
		{
			alp_ND_base.MarkMissionOnMap(); //update mission possition
			alp_ND_base.UpdateMostWanted(); //update most wanted
			alp_ND_base.UpdateNPCquests(); //update NPC quests
		}
		//quests
		
		array< ref alpRegisteredQuest> reg_quests = alp_ND_base.GetMarkedRegisteredQuests();
		if ( reg_quests )
		{
			foreach ( alpRegisteredQuest reg : reg_quests )
			{
				if ( reg )
				{
				
					alpTraderQuest quest = alp_ND_base.GetQuest( reg.Quest );
					
					if ( quest )
					{
						mapWidget_.AddUserMark( Vector(reg.PosX, 0, reg.PosY ),quest.MapTitle, COLOR_YELLOW, "ND_MISSIONS\\gui\\images\\smTrader.paa" );
					}
				
				}
			}		
		}
			
		//most wanted
		array< ref alpMostWanted> mostWanted = alp_ND_base.GetMostWanted();
		if ( mostWanted )
		{
			foreach ( alpMostWanted wanted : mostWanted )
			{
				if ( wanted )
				{
					
					mapWidget_.AddUserMark(wanted.GetRealPostition(), wanted.GetName(), COLOR_RED, wanted.GetIcon() );	
				}
			}		
		}
		
		
		
		string inProgress = "";
		//missions
		array<ref alpMissionTemplateClient>	markedMission = alp_ND_base.GetMarkedMission();
		if ( markedMission )
		{
			foreach (alpMissionTemplateClient data : markedMission)
			{
				
				
				//IsMissionKnown
				if ( !alp_ND_base.IsMissionKnown( data.id, data.missionStarted, data.requiredLevel ) ) {
					continue;
				}
				
				inProgress = alpMAPMARKERS.GetMissionStatus( data.type, data.setting );

				//radoation
				if ( (  (data.showInMap && data.type == ALP_MISSIONTYPEID.radiation && data.setting & alpMISSION_SETTING.RADIATION_ACTIVE ) || (data.showInMap && data.type == ALP_MISSIONTYPEID.radiationMission && data.setting & alpMISSION_SETTING.RADIATION_ACTIVE ) ) && data.position!=vector.Zero && data.missionRadius>0 )
				{
					if (data.showInMap && data.type == ALP_MISSIONTYPEID.radiation) 
						mapWidget_.AddUserMark(data.position, data.title + inProgress, COLOR_RED, "ND_MISSIONS\\gui\\images\\smRadiation.paa");	
					else 
						mapWidget_.AddUserMark(data.position, data.title + inProgress, COLOR_RED, "ND_MISSIONS\\gui\\images\\smRadiation.paa");
					
					if ( alp_ND_base.alp_OptionsMS.ShowMissionRadius )
					{
						mapMarkerMgr.AddCircleNonScaling(data.position, data.missionRadius, COLOR_RED, Math.RandomInt(0,1000000));
					}
				}
			
				//others mission
				if (data.showInMap && data.type == ALP_MISSIONTYPEID.trader && data.position!=vector.Zero )
				{
					mapWidget_.AddUserMark(data.position, data.title + inProgress, COLOR_GREEN, "ND_MISSIONS\\gui\\images\\smTrader.paa");							
				}	
				if (data.showInMap && data.type == ALP_MISSIONTYPEID.carAccident && data.position!=vector.Zero )
				{
					mapWidget_.AddUserMark(data.position, data.title + inProgress, COLOR_GREEN, "ND_MISSIONS\\gui\\images\\mmMissionCar.paa");							
				}											
				if (data.showInMap && ( data.type == ALP_MISSIONTYPEID.noradiation || ( data.type == ALP_MISSIONTYPEID.radiation && !(data.setting & alpMISSION_SETTING.RADIATION_ACTIVE)  )  ) && data.position!=vector.Zero )
				{
					mapWidget_.AddUserMark(data.position, data.title + inProgress, COLOR_GREEN, "ND_MISSIONS\\gui\\images\\mmMission.paa");							
				}				
				if (data.showInMap && ( data.type == ALP_MISSIONTYPEID.noradiationMission || ( data.type == ALP_MISSIONTYPEID.radiationMission && !(data.setting & alpMISSION_SETTING.RADIATION_ACTIVE)  ) ) && data.position!=vector.Zero )
				{
					mapWidget_.AddUserMark(data.position, data.title + inProgress, COLOR_GREEN, "ND_MISSIONS\\gui\\images\\mmMission.paa");								
				}
				if (data.showInMap && data.type == ALP_MISSIONTYPEID.fuelstation && data.position!=vector.Zero )
				{
					mapWidget_.AddUserMark(data.position, data.title + inProgress, COLOR_GREEN, "ND_MISSIONS\\gui\\images\\mmMission.paa");								
				}				
				//show restricted area safeZone
				if (data.type == ALP_MISSIONTYPEID.trader && data.missionRadius > 0 && alp_ND_base.alp_OptionsMS.ShowMissionRadius )
				{
					mapMarkerMgr.AddCircleNonScaling(data.position, data.missionRadius, COLOR_GREEN, Math.RandomInt(0,1000000));

					if ( alp_ND_base.alp_OptionsMS.LabelOfSafeZone != "" )
					{
						vector pos2 = data.position;
						pos2[2] = pos2[2] + data.missionRadius * 0.5;				
						pos2[0] = pos2[0] - data.missionRadius * 0.75;
						
						mapWidget_.AddUserMark(pos2, alp_ND_base.alp_OptionsMS.LabelOfSafeZone , COLOR_GREEN, "");			
					}		
				}			
							
			}	
		}
	}
	
}
#endif