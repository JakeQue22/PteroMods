#ifdef PVEZ
modded class RLGroupUI {
	
	override void AddCustomMarkersOnMapOpen(MapWidget mapWidget_, RLMapMarkerManager mapMarkerMgr) {
		super.AddCustomMarkersOnMapOpen(mapWidget_, mapMarkerMgr);
		if (!mapWidget_)
			return;
		array<ref PVEZ_LawbreakerMarker> rlmarkers = g_Game.pvez_LawbreakersMarkers.markers;
		array<ref PVEZ_Zone> zones = g_Game.pvez_Zones.activeZones;
		int color = ARGB(255,
			g_Game.pvez_Config.MAP.Zones_Border_Color.R,	// red
			g_Game.pvez_Config.MAP.Zones_Border_Color.G,	// green
			g_Game.pvez_Config.MAP.Zones_Border_Color.B);	// blue	
		foreach (PVEZ_Zone zone : zones) {
			vector pos = Vector(zone.X, 0, zone.Z);
			if (zone.ShowNameOnMap) {
				mapWidget_.AddUserMark(zone.GetVectorPos(), zone.Name, color, "");
			}
		}
		string label = "";
		if (g_Game.pvez_Config.MAP.Lawbreakers_Markers.Custom_Lawbreaker_Label != string.Empty)
			label = g_Game.pvez_Config.MAP.Lawbreakers_Markers.Custom_Lawbreaker_Label;
		else
			label = Widget.TranslateString("#pvez_rl_map_label");

		for (int i = 0; i < rlmarkers.Count(); i++) {
			mapWidget_.AddUserMark(rlmarkers[i].Position, label + " " + rlmarkers[i].Name, ARGB(255, 255, 90, 0), "\\PVEZ\\GUI\\Textures\\skull_ca.paa");
		}
	}
	
	override void AddCustomMarkers(MapWidget mapWidget_, RLMapMarkerManager mapMarkerMgr) {
		super.AddCustomMarkers(mapWidget_, mapMarkerMgr);
		//mapMarkerMgr.AddCircleNonScaling(Vector(3709.30, 0, 5993.22), 300.0, ARGB(255, 0, 255, 0));
		if (!mapMarkerMgr || !g_Game.pvez_Config || !g_Game.pvez_Config.MAP || !g_Game.pvez_Config.MAP.Zones_Border_Color)
			return;
		array<ref PVEZ_Zone> zones = g_Game.pvez_Zones.activeZones;
		int color = ARGB(255,
			g_Game.pvez_Config.MAP.Zones_Border_Color.R,	// red
			g_Game.pvez_Config.MAP.Zones_Border_Color.G,	// green
			g_Game.pvez_Config.MAP.Zones_Border_Color.B);	// blue	
		foreach (PVEZ_Zone zone : zones) {
			if (zone.ShowBorderOnMap) {
				mapMarkerMgr.AddCircleNonScaling(zone.GetVectorPos(), zone.Radius, color, 5488);
			}
		}
	}
	
}
#endif