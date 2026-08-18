#ifdef THKOTH
modded class RLGroupUI {

	override void AddCustomMarkersOnMapOpen(MapWidget mapWidget_, RLMapMarkerManager mapMarkerMgr) {
		super.AddCustomMarkersOnMapOpen(mapWidget_, mapMarkerMgr);
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		mission.AddKOTHMarker(mapWidget_, mapMarkerMgr);
	}
	
}
#endif