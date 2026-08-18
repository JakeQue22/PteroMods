class RLSafezoneMarker : RLGroupMember {

	bool admin = false;
	
	override void FindPlayerBase() {}
	override void InitCompassWidget() {}
	override bool ShowMarkerMapOrGPS(bool isMap) {return false;}
	override bool IsGroupMarker() {return false;}
	override bool ShowDistance3D() {return false;}
	override bool ShowPlayerMarker() {return true;}
	override bool ShowMarker3D() {
		if (clientPBFound.IsInMyRLGroup(true) || !RLUtils.IsPlayerAlive(clientPBFound))
			return false;
		Man myPlayer = GetGame().GetPlayer();
		if (!RLUtils.IsPlayerAlive(myPlayer) || !RLSafezoneMarkers.Get.IsInRadius(RL_PlayerBase_Utils.GetHeadPosition(myPlayer), RL_PlayerBase_Utils.GetHeadPosition(myPlayer)))
			return false;
		return RLSafezoneMarkers.Get.IsInRadius(RL_PlayerBase_Utils.GetHeadPosition(clientPBFound), RL_PlayerBase_Utils.GetHeadPosition(myPlayer));
	}
	override int Get3DColorARGB() {
		if (admin)
			return RLSafezoneMarkers.Get.adminTagColor.GetColorARGB();
		return RLSafezoneMarkers.Get.playerTagColor.GetColorARGB();
	}
	
	static RLSafezoneMarker CreateSafezoneMarker(PlayerBase pb) {
		
		RLSafezoneMarker safezoneMarker = new RLSafezoneMarker();
		safezoneMarker.admin = RLSafezoneMarkers.Get.IsAdmin(pb.steamidHash);
		safezoneMarker.CreateMember(pb);
		safezoneMarker.clientPBFound = pb;
		if (RLSafezoneMarkers.Get.obfuscatePlayerNames) {
			safezoneMarker.name = RLGroupMainConfig.Get.ObfuscatePlayerName(pb);
			safezoneMarker.admin = false;
		} else if (safezoneMarker.admin) {
			safezoneMarker.name = RLSafezoneMarkers.Get.adminTagPrefix + safezoneMarker.name;
		} else {
			safezoneMarker.name = RLSafezoneMarkers.Get.playerTagPrefix + safezoneMarker.name;
		}
		safezoneMarker.InitMarker();
		return safezoneMarker;
	}
	
}