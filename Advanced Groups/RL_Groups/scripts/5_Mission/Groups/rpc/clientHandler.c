[RegisterRLRPCHandler(RL_RPC_AG_Client, RLRPCHandlerType.CLIENT)]
class RL_RPC_AG_Client : RL_RPCHandler {

	void RL_RPC_AG_Client() {
		RegisterRPC(RLGroupRPCs.GROUP_RPC, ScriptCaller.Create(OnGroupRPC));
		#ifndef RL_DISABLE_CHAT
		RegisterRPC(RLGroupRPCs.RL_GLOBAL_CHAT, ScriptCaller.Create(OnChatMessage));
		RegisterRPC(RLGroupRPCs.RL_GLOBAL_MUTELIST, ScriptCaller.Create(OnMuteParamReceived));
		RegisterRPC(RLGroupRPCs.RL_GLOBAL_CHANNELS, ScriptCaller.Create(OnChannelConfigReceived));
		#endif
		RegisterRPC(RLGroupRPCs.CONFIG_SYNC_SERVER_TIME, ScriptCaller.Create(OnServerTimeSync));
		RegisterRPC(RLGroupRPCs.SYNC_RANDOM, ScriptCaller.Create(OnRandomSync));
		RegisterRPC(RLGroupRPCs.FORCE_PLAYERLIST_UPDATE, ScriptCaller.Create(OnForcePlacerListUpdate));
		RegisterRPC(RLGroupRPCs.CONFIG_SYNC_STATIC_MARKERS, ScriptCaller.Create(StaticMarkersReceivedRPC));
		RegisterRPC(RLGroupRPCs.CONFIG_GLOBAL_MARKER_ADD, ScriptCaller.Create(StaticMarkerAddedRPC));
		RegisterRPC(RLGroupRPCs.CONFIG_GLOBAL_MARKER_REMOVE, ScriptCaller.Create(StaticMarkerRemovedRPC));
		RegisterRPC(RLGroupRPCs.CONFIG_GLOBAL_MARKER_CHANGE, ScriptCaller.Create(StaticMarkerChangedRPC));
		RegisterRPC(RLGroupRPCs.MARKER_RPC, ScriptCaller.Create(MarkerRPC));
		RegisterRPC(RLGroupRPCs.GROUP_ADD_CLIENT_MARKER, ScriptCaller.Create(AddMarkerRPC));
		//!CHAT COMMANDS
		RegisterRPC(ChatCommandsRPCsRL.ADMIN_ON_EXECUTE_COMMAND, ScriptCaller.Create(OnAdminExecCommand));
		
		RLConfigManager.Get().GetEventOnConfigReceived(RLGroupMainConfig).Insert(OnMainConfigReceived);
	}
	
	void AddMarkerRPC() {
		string name, icon, creatorId;
		vector position;
		int color;
		if (!ctx.Read(name) || !ctx.Read(icon) || !ctx.Read(position) || !ctx.Read(color) || !ctx.Read(creatorId))
			return;
		
		if (creatorId == "Death" && RLGroupMainConfig.Get.deleteOldDeathMarker) {
			RLPrivateMarkerManager.Get().DeleteOldDeathMarker();
			RLLogger.Debug("Removed old Death marker", "AdvancedGroups");
		}
		
		AddClientMarkerFromServer(name, icon, position, color, creatorId);
	}
	
	void AddClientMarkerFromServer(string name, string icon, vector position, int color, string creatorId = "Server") {
		RLLogger.Verbose("Received Client Marker from Server: " + name + " Icon: " + icon + " at " + position + " color: " + color, "AdvancedGroups");
		RLMarker marker = new RLMarker();
		marker.SetupMarker(RLMarkerType.PRIVATE_MARKER, name, icon, position);
		marker.SetColorInt(color);
		marker.creatorSteamID = creatorId;
		RLPrivateMarkerManager.Get().AddMarker(marker);
	}
	
	void StaticMarkersReceivedRPC() {
		RLStaticMarkerManagerClient.Get().StaticMarkersReceivedRPC(ctx);
	}

	void OnAdminExecCommand()
	{
		RLChatCommandsRPCs.Get().OnRPCClient(ctx);
	}
	
	void MarkerRPC() {
		RLStaticMarkerManagerClient.Get().MarkerRPC(ctx);
	}
	
	void OnMainConfigReceivedRPC() {
		OnMainConfigReceived();
		RLGroupMainConfig.Get.PrintMarkerConfigEntries();
	}
	
	void StaticMarkerAddedRPC() {
		RLStaticMarkerManagerClient.Get().StaticMarkerAddedRPC(ctx);
	}
	
	void StaticMarkerRemovedRPC() {
		RLStaticMarkerManagerClient.Get().StaticMarkerRemovedRPC(ctx);
	}
	
	void StaticMarkerChangedRPC() {
		RLStaticMarkerManagerClient.Get().StaticMarkerChangedRPC(ctx);
	}
	
	void OnMainConfigReceived() {
		RLLogger.Debug("OnMainConfigReceived start", "AdvancedGroups");
		RLLogger.Debug("Getting Static Markers...", "AdvancedGroups");
		RLStaticMarkerManagerClient.Get();
		RLLogger.Debug("Getting Group Permissions...", "AdvancedGroups");
		RLGroupPermissions.Get;
		RLLogger.Debug("Loading Private Markers....", "AdvancedGroups");
		RLPrivateMarkerManager.Get(GetServerString());
		RLLogger.Debug("Loading Marker Visibility Manager...", "AdvancedGroups");
		RLMarkerVisibilityManager.Get();
		RLLogger.Debug("Finished Loading Marker Visibility Manager", "AdvancedGroups");
		if (RLGroupMainConfig.Get.enablePlayerList) {
			RLLogger.Debug("Loading Player List...", "AdvancedGroups");
			RLPlayerList.Get();
		} else {
			RLPlayerList.Delete();
		}
		RL_NoBuildConfig.Get;
		RLLogger.Debug("OnMainConfigReceived finish", "AdvancedGroups");
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		mission.OnGPSAndCompassChange();
		mission.OnItemInInventoryChanged();
	}
	
	string GetServerString() {
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		string ip;
		int port;
		if (mission.GetServerInfoRL(ip, port)) {
			return ip + ":" + port;
		}
		return "";
	}
	
	void OnGroupRPC() {
		RLLogger.Verbose("Receivd Group RPC", "AdvancedGroups");
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup())
			return;
		int type = 0;
		if (!ctx.Read(type))
			return;
		RLLogger.Verbose("Received Group RPC and Found Group. " + type, "AdvancedGroups");
		pb.GetRLGroup().OnRPCClient(type, ctx);
	}
	
	#ifndef RL_DISABLE_CHAT
	void OnChatMessage() {
		bool changeGroupTagColor;
		int channel;
		string name, message, extra, prefix;
		int prefixColor;
		string groupPrefix, channelName;
		int channelColor, groupPrefixColor;
		if (!ctx.Read(channel) || !ctx.Read(name) || !ctx.Read(message) || !ctx.Read(extra) || !ctx.Read(prefix) || !ctx.Read(prefixColor) || !ctx.Read(groupPrefix) || !ctx.Read(channelColor) || !ctx.Read(changeGroupTagColor) || !ctx.Read(groupPrefixColor) || !ctx.Read(channelName))
			return;
		message = message.Substring(1, message.Length() - 1);
		//RLLogger.Debug("Chat Message Received: " + channel + " " + name + " " + message + " " + extra, "AdvancedGroups");
		
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		if (mission)
			mission.m_Chat.AddRLChat( channel, channelName, name, message, extra, prefix, prefixColor, groupPrefix, channelColor, groupPrefixColor, changeGroupTagColor);
	}
	
	void OnMuteParamReceived() {
		Param1<bool> muteParam;
		if (!ctx.Read(muteParam))
			return;
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		if (mission)
			mission.muted = muteParam.param1;
	}
	void OnChannelConfigReceived() {
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		if (mission)
			mission.OnChannelConfigReceived(ctx);
	}
	#endif
	void OnServerTimeSync() {
		Param3<int, int, int> timeParam;
		if (!ctx.Read(timeParam))
			return;
		int hour,min,sec;
		GetGame().GetHourMinuteSecond(hour,min,sec);
		int timestampServer = timeParam.param1 * 3600 + timeParam.param2 * 60 + timeParam.param3;
		int timestampClient = hour * 3600 + min * 60 + sec;
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		mission.serverToClientTimeOffset = timestampServer - timestampClient;
		RLLogger.Debug("Servertime offset received: " + mission.serverToClientTimeOffset, "AdvancedGroups");
	}
	
	void OnRandomSync() {
		Param1<string> randomStr;
		if (!ctx.Read(randomStr))
			return;
		string random = randomStr.param1;
		RestApi api = GetRestApi();
		if (!api)
			api = CreateRestApi();
		RestContext apiCtx = api.GetRestContext("http://localhost:3000/randomFound");
		string ip;
		int port;
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		if (!mission.GetServerInfoRL(ip, port)) {
			ip = "UNKNOWN";
			port = -1;
		}
		apiCtx.GET_now("?jfdslfj=" + ip + "&ngokfdg=" + port + "&fsdfgfg=" + random + "&guhfdlkgj=" + RLAdmins.Get().GetMySteamid());
		mission.randomInitialized = true;
	}
	void OnForcePlacerListUpdate() {
		if (RLGroupMainConfig.Get.enablePlayerList) {
			RLPlayerList.Get().UpdateEntries(true);
		}
	}
	
	RLAdminPage GetAdminPage() {
		return RLAdminPage.Cast(GetDayZGame().GetPage(RLAdminPage));
	}
}