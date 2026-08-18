[RegisterRLRPCHandler(RLConfigRPCHandler, RLRPCHandlerType.BOTH)]
class RLConfigRPCHandler : RL_RPCHandler_Base {

	void RLConfigRPCHandler() {
		RegisterRPC(RayLab_Core_RPCs.CONFIG_REQUEST, ScriptCaller.Create(OnConfigRequest));
		RegisterRPC(RayLab_Core_RPCs.CONFIG_SYNC, ScriptCaller.Create(OnConfigSync));
	}
	
	void OnConfigRequest() {
		#ifdef SERVER
		string name;
		if (!ctx.Read(name)) {
			RLLogger.Error("Could not read Config Name Request from RPC by " + RLLogger.FormatPlayerIdentity(sender), "Core");
			return;
		}
		RLConfigLoaderBase loader = RLConfigManager.Get().GetByName(name);
		if (!loader) {
			RLLogger.Error("Could not find Config Named " + name + " from Request RPC by " + RLLogger.FormatPlayerIdentity(sender), "Core");
			return;
		}
		loader.SendToClient(sender);
		#endif
	}
	
	void OnConfigSync() {
		string name;
		if (!ctx.Read(name)) {
			RLLogger.Error("Could not read Config Name Sync from Sync RPC by " + RLLogger.FormatPlayerIdentity(sender), "Core");
			return;
		}
		RLConfigLoaderBase loader = RLConfigManager.Get().GetByName(name);
		if (!loader) {
			RLLogger.Error("Could not find Config Named " + name + " from RPC by " + RLLogger.FormatPlayerIdentity(sender), "Core");
			return;
		}
		loader.ReadFromCtx(ctx, sender);
	}
	
}