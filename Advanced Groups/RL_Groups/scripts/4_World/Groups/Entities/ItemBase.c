modded class ItemBase {

	void OnInventoryUpdateGPS() {
		MissionBaseWorld mission = MissionBaseWorld.Cast(GetGame().GetMission());
		if (mission) {
			GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Call(mission.OnItemInInventoryChanged);
		}
	}
	
	override void OnInventoryExit(Man player) {
		super.OnInventoryExit(player);
		if (GetGame().IsClient())
			OnInventoryUpdateGPS();
	}
	override void OnInventoryEnter(Man player) {
		super.OnInventoryEnter(player);
		if (GetGame().IsClient())
			OnInventoryUpdateGPS();
	}

}
modded class TerritoryFlagKit {

	string RLGetFlagpoleName() {
		return "TerritoryFlag";
	}
	
}