#ifdef CarePackageV2
modded class RLGroupUI : UIScriptedMenu {	

    override void AddCustomMarkersOnMapOpen() {
		
		GetRPCManager().SendRPC( "CarePackage_Server", "CarePackageLocation_Server", NULL);
		
		super.AddCustomMarkersOnMapOpen();		
		
		Print("Hello World, is AG even fucking here?");
		
		if (mapWidget)
		{	
			if(g_Game.GetClientCarePackageMarkerArrayLength() > 0)
			for( int i=0; i < g_Game.GetClientCarePackageMarkerArrayLength(); i++ )
			{
				Print(g_Game.GetClientCarePackageMarker(i));
				if( g_Game.GetClientCarePackageMarker(i) != "0 0 0" )
				{
					mapWidget.AddUserMark(g_Game.GetClientCarePackageMarker(i), "CarePackage", ARGB(255, 0, 255, 0), "CarePackage\\Icon.paa");	
				}
			}
		}
    }
}
#endif