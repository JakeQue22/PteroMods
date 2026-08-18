modded class MissionBase {

	void MissionBase() {
		RLLogger.Debug("Mission Base Init", "Core");
		GetDayZGame().InitRegisteredRPCHandlersRL();
		RLConfigManager.Get().LoadAllConfigs();
		RLInherit.Get();
		
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Call(RLTestManager.Finish);
	}
	
	override void OnInit() {
		super.OnInit();
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Call(RLConfigManager.Get().OnMissionInit);
		RLAdmins.RequestConfig();
		GetDayZGame().ExecuteInitRPCs();
		Print("Mission Base init finished");
	}
	
	void ~MissionBase() {
		RLLogger.Debug("Mission Base Deleted", "Core");
		RLAdmins.Delete();
		if (GetDayZGame()) {
			GetDayZGame().ClearRegisteredPages();
			GetDayZGame().ClearRegisteredRPCs();
		}
		RLWarningPopup.Delete();
		RLConfigManager.Delete();
	}
}