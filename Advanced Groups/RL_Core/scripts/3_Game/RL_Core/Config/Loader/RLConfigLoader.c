class RLConfigLoader<Class T1> : RLConfigLoaderBase {

	[RLConfigManager.Get().RegisterConfigLoader(T1)]
	static ref T1 Get;
	static ref RLConfigLoaderBase Loader;
	
	void RLConfigLoader() {
		Loader = this;
		RLLogger.Verbose("Created Loader: " + this, "Core");
	}
	
	void ~RLConfigLoader() {
		RLLogger.Verbose("Deleted Loader: " + this, "Core");
		Delete();
	}
	
	static Class SetPriority(RLConfigPriority prio) {
		RLConfigManager.Get().SetConfigPriority(T1, prio);
		return null;
	}
	
	override string GetName() {
		return T1.ToString();
	}
	
	override void Load() {
		RLLogger.Verbose("Loading: " + GetName(), "Core");
		InitVars();
		#ifndef SERVER
		if (!IsClientSideConfig()) {
			RequestFromServer();
			return;
		}
		#else
		if (IsClientSideConfig()) {
			return;
		}
		#endif
		Class.CastTo(Get, T1.Spawn());
		string path = GetFilePath();
		if (!FileExist(path)) {
			RayLabConfigMover.CreateParentFolders(path);
			Get.LoadDefault();
			Save();
		} else {
			bool forceValid = IsForceValid();
			bool loadFull = false;
			Get.JsonLoadVar(path, forceValid, loadFull);
			if (loadFull) {
				RLJsonLoader<T1>.JsonLoadFile(path, Get, forceValid);
			}
		}
		if (Get.version != Get.GetCurrentVersion()) {
			RLLogger.Debug("Updating Config " + GetName() + " from Version " + Get.version + " to " + Get.GetCurrentVersion(), "Core");
			Get.UpdateVersion();
			Get.version = Get.GetCurrentVersion();
			Save();
		}
		if (Get.OnLoad()) {
			RLLogger.Debug("OnLoad triggered Saving of Config " + GetName(), "Core");
			Save();
		}
	}
	
	override void InitMission() {
		if (Get && Get.OnLoadMission()) {
			RLLogger.Debug("OnLoad triggered Saving of Config " + GetName(), "Core");
			Save();
		}
	}
	
	override void Delete() {
		if (Get)
			delete Get;
	}
	
	override void Reload() {
		Load();
	}
	
	override void Save() {
		#ifdef SERVER
		if (IsClientSideConfig())
			return;
		#else
		if (!IsClientSideConfig()) {
			if (IsSyncedToClient())
				SendToClient(null);
			return;
		}
		#endif
		bool saveFull = false;
		Get.JsonSaveVar(GetFilePath(), saveFull);
		if (saveFull)
			RLJsonLoader<T1>.JsonSaveFile(GetFilePath(), Get);
	}
	
	override void SendToClient(PlayerIdentity target) {
		if (!IsSyncedToClient())
			return;
		RLLogger.Verbose("sending config " + GetName() + " to " + RLLogger.FormatPlayerIdentity(target), "Core");
		ScriptRPC rpc = new ScriptRPC();
		rpc.Write(GetName());
		rpc.Write(Get);
		Get.WriteExtraCtx(rpc);
		if (!target && GetReceivePermission() != "" && GetGame().IsServer()) {
			RLAdmins.Get().SendRPCToAdminsWithPermission(GetReceivePermission(), rpc, RayLab_Core_RPCs.CONFIG_SYNC);
		} else {
			rpc.Send(null, RayLab_Core_RPCs.CONFIG_SYNC, true, target);
		}
	}
	
	override void ReadFromCtx(ParamsReadContext ctx, PlayerIdentity sender) {
		#ifdef SERVER
		string permission = GetChangePermission();
		if (!RLAdmins.Get().HasPermission(permission, sender)) {
			RLLogger.Error("Player " + RLLogger.FormatPlayerIdentity(sender) + " tried to update Config " + T1.ToString(), "Core");
			return;
		}
		#endif
		
		T1 newInstance;
		if (!ctx.Read(newInstance) || !newInstance.ReadExtraCtx(ctx)) {
			#ifdef SERVER
			RLLogger.Error("Could not read config " + GetName() + " from " + RLLogger.FormatPlayerIdentity(sender), "Core");
			#else
			RLLogger.Error("Could not read config " + GetName() + " from Server", "Core");
			#endif
			return;
		}
		#ifdef SERVER
		RLLogger.Info("received config " + GetName() + " from " + RLLogger.FormatPlayerIdentity(sender), "Core");
		#else
		RLLogger.Info("received config " + GetName() + " from Server", "Core");
		#endif
		Get = newInstance;
		Get.OnReceivedFromRPC(sender);
		RLConfigManager.Get().OnConfigReceived(T1);
		#ifdef SERVER
		Save();
		SendToClient(null);
		NotificationSystem.SendNotificationToPlayerIdentityExtended(sender, 4.0, "Config Saved", "The config was saved sucessfully !", GetRLMessageInfoIcon());
		#endif
		
	}
	
	private void RequestFromServer() {
		if (!IsSyncedToClient())
			return;
		ScriptRPC rpc = new ScriptRPC();
		rpc.Write(GetName());
		rpc.Send(null, RayLab_Core_RPCs.CONFIG_REQUEST, true);
		RLLogger.Info("requested config " + GetName() + " from Server", "Core");
	}
	
}
string GetRLMessageInfoIcon() {
	return RLIconConfig.Get.info;
}