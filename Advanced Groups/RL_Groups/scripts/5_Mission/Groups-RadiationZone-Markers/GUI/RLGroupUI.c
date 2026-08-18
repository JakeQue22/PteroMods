#ifdef ND_RP
modded class RLGroupUI {
	
    override void AddCustomMarkersOnMapOpen() {
		super.AddCustomMarkersOnMapOpen();
		if (!mapWidget)
			return;
		
		MapMenu.ShowALLMissionsALP(mapWidget);
		MapMenu.ShowALLRestictedAreasALP(mapWidget);
    }
}
#endif