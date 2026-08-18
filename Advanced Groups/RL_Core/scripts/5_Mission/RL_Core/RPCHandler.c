[RegisterRLRPCHandler(RLCoreMissionRPCHandler, RLRPCHandlerType.SERVER)]
class RLCoreMissionRPCHandler : RL_RPCHandler {
	
	void RLCoreMissionRPCHandler() {
		RegisterRPC(RayLab_Core_RPCs.UPDATE_CHECK_INFO, ScriptCaller.Create(OnCheckInfoRequestReceived));
	}
	
	void OnCheckInfoRequestReceived() {
		if (!HasPermission("adminmenu.open"))
			return;
		TStringArray ret;
		if (!RLVersionUpdateCheckCallbackBase.error && RLVersionUpdateCheckCallbackBase.received) {
			
			if (RLVersionUpdateCheckCallbackBase.oldVersions.Count() > 0) {
				rpc.Write(RLVersionUpdateCheckCallbackBase.oldVersions);
				ret = RLVersionUpdateCheckCallbackBase.oldVersions;
			} else {
				ret = {"All Mods are up to date"};
				rpc.Write(ret);
			}
				
		} else if (RLVersionUpdateCheckCallbackBase.error) {
			ret = {"Error", RLVersionUpdateCheckCallbackBase.errorMessage};
			rpc.Write(ret);
		} else {
			ret = {"Error", "Could not contact Update Server !"};
			rpc.Write(ret);
		}
		Print(ret[0]);
		rpc.Send(null, RayLab_Core_RPCs.UPDATE_CHECK_INFO, true, sender);
	}
	
}