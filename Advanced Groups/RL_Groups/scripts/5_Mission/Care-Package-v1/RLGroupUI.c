#ifdef CarePackage
modded class RLGroupUI {	

    override void AddCustomMarkersOnMapOpen() {

		GetRPCManager().SendRPC( "CarePackage_Server", "CarePackageLocation_Server", NULL);
		
		super.AddCustomMarkersOnMapOpen();
		
		if (mapWidget)
		{	
			if( g_Game.GetClientCarePackageMarker() != "0 0 0" )
			{
				mapWidget.AddUserMark(g_Game.GetClientCarePackageMarker(), "CarePackage", ARGB(255, 0, 255, 0), "CarePackage\\Icon.paa");	
			}
		}
    }
}
#endif