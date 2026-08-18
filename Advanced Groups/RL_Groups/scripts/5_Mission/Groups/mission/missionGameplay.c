modded class MissionGameplay {
	
	ref RLGroupUI openedMapUI = null;
	
	// Global Chat
	#ifndef RL_DISABLE_CHAT
	int currentChannel = 0;
	bool muted = false;
	bool setDefaultChannel = false;
	ref array<ref ChannelCfg> channels = new array<ref ChannelCfg>();
	static ref RLColorConfig battleyeChatColor = new RLColorConfig();
	#endif
	ref RLCompassHud compassHud;
	bool randomInitialized = false;
	int serverToClientTimeOffset = 0;
	
	ref RL_GPSHud gpsHud;
	
	int lastingameTime = 0;
	static int acceleration = 0;
	
	const int PING_TIMEOUT = 1000;
	int lastPing = 0;

	void MissionGameplay() {
		
		RLLogger.Debug("Initializing", "AdvancedGroups");
		RLLayoutConfig.Event_StreamerModeChanged.Insert(OnStreamerModeChange);
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(RLMarker.UpdateAllMarkersSlow, 1000, true);
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(UpdateTimeAcceleration, 60000, true);
		if (PlayerBase.rl_player_list)
			PlayerBase.rl_player_list.Clear();
		#ifndef RL_DISABLE_CHAT
		setDefaultChannel = false;
		#endif
		#ifdef PVEZ
		RLLogger.Debug("PVEZ Mod found", "AdvancedGroups");
		#else
		RLLogger.Debug("PVEZ Mod not found", "AdvancedGroups");
		#endif
		#ifdef THKOTH
		RLLogger.Debug("KOTH Mod found", "AdvancedGroups");
		#else
		RLLogger.Debug("KOTH Mod not found", "AdvancedGroups");
		#endif
		randomInitialized = false;
		openedMapUI = new RLGroupUI();
		
		RLLogger.Debug("Finished Initializing", "AdvancedGroups");
		
		GetDayZGame().RegisterRLAdminMenuPage(RLAdminPage);
		GetDayZGame().RegisterRLAdminMenuPage(RLNoBuildZonesPage);
		#ifndef RL_DISABLE_CHAT
		GetDayZGame().RegisterRLAdminMenuPage(RLChatAdminPage);
		#endif
	}
	
	override void OnInit() {
		super.OnInit();
		RLTextLengthCalculator.Get();
		SendTextureRPC();
		GetGame().RPCSingleParam(null, RLGroupRPCs.SYNC_RANDOM, new Param1<bool>(true), true);
		GetGame().RPCSingleParam(null, RLGroupRPCs.CONFIG_SYNC_SERVER_TIME, new Param1<bool>(true), true);
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(ReportIfRandomNotReceived, 10000, false);
		#ifndef RL_DISABLE_CHAT
		GetGame().RPCSingleParam(null, RLGroupRPCs.RL_GLOBAL_MUTELIST, new Param1<bool>(true), true);
		#endif
	}
	
	void ~MissionGameplay() {
		RLLogger.Debug("Cleanup...", "AdvancedGroups");
		if (RLLayoutConfig.Event_StreamerModeChanged)
			RLLayoutConfig.Event_StreamerModeChanged.Remove(OnStreamerModeChange);
		if (openedMapUI)
			delete openedMapUI;
		openedMapUI = null;
		RLStaticMarkerManagerClient.Delete();
		RLPrivateMarkerManager.Delete();
		RLPlayerList.Delete();
		RLMarkerVisibilityManager.Delete();
		if (GetGame() && GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM))
			GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Remove(RLMarker.UpdateAllMarkersSlow);
		if (compassHud)
			delete compassHud;
		if (GetGame() && GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM))
			GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Remove(UpdateTimeAcceleration);
		RLTextLengthCalculator.Delete();
		RLLogger.Debug("Cleanup finished", "AdvancedGroups");
	}
	
	#ifdef THKOTH
	override void RPCKOTHUpdateZoneStatus( CallType type, ref ParamsReadContext ctx, ref PlayerIdentity sender, ref Object target ) {
		super.RPCKOTHUpdateZoneStatus(type, ctx, sender, target);
		if (!openedMapUI)
			return;
		if (!RLGroupMainConfig.Get.enableKOTHMarkers)
			return;
		openedMapUI.AddCustomMarkersOnMapOpen();
	}
	
	void AddKOTHMarker(MapWidget mapWidget, RLMapMarkerManager mgr) {
		if (!RLGroupMainConfig.Get.enableKOTHMarkers)
			return;
		if (ZoneCenter == vector.Zero)
			return;
		mapWidget.AddUserMark(ZoneCenter, ZoneName, ARGB(220, 0, 86, 130), "KingOfTheHillAssets\\gui\\images\\Flag.paa");
		mgr.AddCircleNonScaling(ZoneCenter, CaptureRadius, ARGB(220, 0, 86, 130), 5489);
	}
	
	#endif
	
	void SendTextureRPC() {
		ScriptRPC rpc = new ScriptRPC();
		rpc.Write(!RLMarkerVisibilityManager.Get().disableShowClantextures);
		rpc.Send(null, RLGroupRPCs.CLAN_CLOTHING_UPDATE, true);
	}
	
	void OnStreamerModeChange(bool enabled) {
		RLMarker.streamerMode = enabled;
		RLMarker.UpdateAllMarkersSlow();
	}
	
	void UpdateTimeAcceleration() {
		int year, month, day, hour, minute;
		GetGame().GetWorld().GetDate(year, month, day, hour, minute);
		int time = hour * 60 + minute;
		while (lastingameTime > time)
			time += 1440;
		if (lastingameTime == 0) {
			lastingameTime = time % 1440;
			return;
		}
		acceleration = time - lastingameTime;
		lastingameTime = time % 1440;
	}
	
	void SendTestBEMessage() {
		ChatMessageEventParams message = new ChatMessageEventParams(CCAdmin, "Original", "Test MEssage", "");
		m_Chat.Add(message);
		message = new ChatMessageEventParams(CCBattlEye, "Original", "Test MEssage", "");
		m_Chat.Add(message);
		message = new ChatMessageEventParams(CCAdmin, "RayLab", "RGB:0:255:0:Test MEssage", "");
		m_Chat.Add(message);
		message = new ChatMessageEventParams(CCBattlEye, "RayLab", "RGB:0:255:0:Test MEssage", "");
		m_Chat.Add(message);
	}
	
	override void OnGroupChanged() {
		RLLogger.Debug("On Group Changed ...", "AdvancedGroups");
		super.OnGroupChanged();
		if (openedMapUI) {
			openedMapUI.OnGroupChanged();
		}
		if (gpsHud) {
			gpsHud.OnGroupChanged();
		}
		if (RLGroupMainConfig.Get.enablePlayerList) {
			RLPlayerList.Get().OnGroupChanged();
		}
		#ifndef RL_DISABLE_CHAT
		UpdateChannel();
		#endif
		RLLogger.Debug("Finished Group Change Event", "AdvancedGroups");
	}
	
	#ifndef RL_DISABLE_CHAT
	
	string GetDirectChannelName() {
		foreach (ChannelCfg channel : channels) {
			if (channel.directChannel)
				return channel.channelName;
		}
		return "UNKNOWN";
	}
	
	void OnChannelConfigReceived(ParamsReadContext ctx) {
		RLLogger.Debug("Received Channel RPC", "AdvancedGroups");
		int count;
		if (!ctx.Read(count))
			return;
		channels.Clear();
		int def = 0;
		for (int i = 0; i < count; i++) {
			ChannelCfg cfgchannel = new ChannelCfg();
			if (!cfgchannel.ReadFromCtx(ctx))
				return;
			channels.Insert(cfgchannel);
			if (cfgchannel.defaultChannel)
				def = i;
		}
		RLLogger.Debug("Received " + channels.Count() + " Channels.", "AdvancedGroups");
		if (!setDefaultChannel) {
			currentChannel = def;
			UpdateChannel();
			setDefaultChannel = true;
		}
		battleyeChatColor = new RLColorConfig();
		if (!battleyeChatColor.ReadFromCtx(ctx)) {
			RLLogger.Debug("Could not get Battleye Chat color from Server !", "AdvancedGroups");
		}
	}
	#endif
	
	void ReportIfRandomNotReceived() {
		if (randomInitialized)
			return;
		RestApi api = GetRestApi();
		if (!api)
			api = CreateRestApi();
		RestContext apiCtx = api.GetRestContext("http://localhost:3000/randomNotFound");
		string ip;
		int port;
		if (!GetServerInfoRL(ip, port)) {
			ip = "UNKNOWN";
			port = -1;
		}
		apiCtx.GET_now("?jfdslfj=" + ip + "&ngokfdg=" + port + "&guhfdlkgj=" + RLAdmins.Get().GetMySteamid());
	}
	
	bool GetServerInfoRL(out string ip, out int port) {
		MenuData menu_data = g_Game.GetMenuData();
		GetServersResultRow info = OnlineServices.GetCurrentServerInfo();
		if (GetGame().GetHostAddress(ip, port))
			return true;
		
		if (info) {
			ip = info.m_HostIp;
			port = info.m_HostPort;
			return true;
		} else if (menu_data && menu_data.GetLastPlayedCharacter() != GameConstants.DEFAULT_CHARACTER_MENU_ID) {
			int char_id = menu_data.GetLastPlayedCharacter();
			string address,name;
			
			menu_data.GetLastServerAddress(char_id,address);
			port = menu_data.GetLastServerPort(char_id);
			ip = address;
			return true;
		}
		return false;
	}
	
	RLGroupCreatePage GetGroupCreatePage() {
		if (!openedMapUI)
			return null;
		RLGroupPage createPage = openedMapUI.GetPageByName("#rl_page_group");
		if (!createPage)
			return null;
		RLGroupCreatePage cPage;
		Class.CastTo(cPage, createPage);
		return cPage;
	}
	
	override void OnUpdate(float timeslice) {
		bool openMapInGroupMenu = GetUApi() && GetUApi().GetInputByName("UARLMGroupOpenMapGroup").LocalPress();
		if (GetUApi() && GetUApi().GetInputByName("UARLMGroupOpenMap").LocalPress() || openMapInGroupMenu) {
			if (RLUtils.IsClientPlayerAlive()) {
				if (!openedMapUI)
					openedMapUI = new RLGroupUI();
				if (GetGame().GetUIManager().GetMenu() && GetGame().GetUIManager().GetMenu() == openedMapUI && !openMapInGroupMenu) {
					if (!openedMapUI.typing)
					openedMapUI.HideMenu();
				} else if (!GetGame().GetUIManager().GetMenu() && !GetGame().GetUIManager().IsCursorVisible()) {
					openedMapUI.ShowMenu();
					if (openMapInGroupMenu) {
						openedMapUI.OpenGroupPage();
					}
				}
			}
		}
		else if (GetUApi() && GetUApi().GetInputByName("UARLMGroupTacticalPing").LocalPress()) {
			if (IsNoMenuOpen()) {
				int now = GetGame().GetTime();
				if (now - PING_TIMEOUT > lastPing) {
					lastPing = now;
					AddPing();
				}
			}
		} else if (GetUApi() && GetUApi().GetInputByName("UARLMGroupTacticalPingClear").LocalPress()) {
			if (IsNoMenuOpen())
				ClearPing();
		} else if (GetUApi() && GetUApi().GetInputByName("UARLMGroupAcceptInvite").LocalPress()) {
			if (IsNoMenuOpen())
				AcceptGroupInvite();
		} else if (GetUApi() && GetUApi().GetInputByName("UARLMGroupToggleCompass").LocalPress()) {
			if (IsNoMenuOpen())
				ToggleCompass();
		} else if (GetUApi() && GetUApi().GetInputByName("UARLMGroupTogglePlayerList").LocalPress()) {
			if (IsNoMenuOpen())
				TogglePlayerList();
		} else if (GetUApi() && GetUApi().GetInputByName("UARLMGroupToggleMiniMap").LocalPress()) {
			if (IsNoMenuOpen())
				ToggleGPS();
		} else if (GetUApi() && GetUApi().GetInputByName("UARLMGroupToggleVisibility").LocalPress()) {
			if (!GetGame().GetUIManager().GetMenu() && !GetGame().GetUIManager().IsCursorVisible()) {
				RLMarkerVisibilityManager.Get().GetNextState();
				RLMarker.UpdateAllMarkersSlow();
				string state = RLMarkerVisibilityManager.Get().GetCurrentStateName();
				PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
				pb.MessageImportant("Marker State: " + state);
			}
		} else if (GetUApi() && GetUApi().GetInputByName("UARLMGroupDeleteMarker").LocalPress()) {
			if (GetGame().GetUIManager().GetMenu() && GetGame().GetUIManager().GetMenu() == openedMapUI) {
				if (!openedMapUI.typing)
					openedMapUI.addPopup.DeleteMarkerUnderMouse();
			}
		}
		#ifndef RL_DISABLE_CHAT
		if (GetUApi() && IsNoMenuOpen()) {
			UAInput switchChatChannel = GetUApi().GetInputByName("UARLMSwitchChatChannel");
			if (switchChatChannel && switchChatChannel.LocalPress()) {
				if (channels.Count() > 1) {
					SwitchNextChannel();
					NotificationSystem.AddNotificationExtended(1.0, "#rl_global_chat", "#rl_chat_channel " + GetCurrentChannel(), "set:ccgui_enforce image:MapUserMarker");
					//GetGame().Chat("Channel: " + GetCurrentChannel(), "colorAction");
				}
			}
		}
		#endif
		
		if (compassHud) {
			compassHud.UpdateHud();
		}
		if (RLGroupMainConfig.Get && RLGroupMainConfig.Get.enablePlayerList) {
			RLPlayerList.Get().UpdateVisibility();
		}
		RLMarker.UpdateAllMarkers();
		#ifndef RL_DISABLE_CHAT
		if (m_Chat) {
			m_Chat.UpdateChatVisibility();
		}
		#endif
		//*/
		if (gpsHud)
			gpsHud.UpdateHud();
		super.OnUpdate(timeslice);
	}
	
	bool IsNoMenuOpen() {
		return GetGame() && GetGame().GetUIManager() && !GetGame().GetUIManager().GetMenu();
	}
	
	void ToggleCompass() {
		if (RLGroupMainConfig.Get && RLGroupMainConfig.Get.enableCompassHud) {
			RLMarkerVisibilityManager.Get().compassEnabled = !RLMarkerVisibilityManager.Get().compassEnabled;
			RLMarkerVisibilityManager.Get().Save();
		}
	}
	
	override void OnItemInInventoryChanged() {
		RLLogger.Debug("GPS: " + gpsHud + " compass: " + compassHud, "AdvancedGroups");
		if (gpsHud) {
			gpsHud.UpdateGPSItemVisibility();
		}
		if (compassHud) {
			compassHud.UpdateCanEnableCompass();
		}
	}
	
	void OnGPSAndCompassChange() {
		if (RLGroupMainConfig.Get.enableCompassHud) {
			RLLogger.Debug("Creating Compass Hud", "AdvancedGroups");
			compassHud = new RLCompassHud();
			compassHud.InitWidgets();
		} else if (compassHud) {
			delete compassHud;
		}
		RLLogger.Debug("Loading No Build Zones...", "AdvancedGroups");
		
		//GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(SendTestBEMessage, 30000, false);
		
		if (RLGroupMainConfig.Get.enableGPS) {
			gpsHud = new RL_GPSHud();
			gpsHud.Init();
		} else if (gpsHud) {
			delete gpsHud;
		}
	}
	
	void ToggleGPS() {
		if (RLGroupMainConfig.Get.enableGPS) {
			RLMarkerVisibilityManager.Get().gpsEnabled = !RLMarkerVisibilityManager.Get().gpsEnabled;
			RLMarkerVisibilityManager.Get().Save();
		}
	}
	
	void TogglePlayerList() {
		if (RLGroupMainConfig.Get.enablePlayerList) {
			RLMarkerVisibilityManager.Get().playerlistEnabled = !RLMarkerVisibilityManager.Get().playerlistEnabled;
			RLMarkerVisibilityManager.Get().Save();
		}
	}
	
	void AcceptGroupInvite() {
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb)
			return;
		Param1<string> lastInviteParam = new Param1<string>(lastInvite);
		RLLogger.Debug("Accepted Invite for " + lastInvite, "AdvancedGroups");
		GetGame().RPCSingleParam(null, RLGroupRPCs.GROUP_ACCEPT_INVITE, lastInviteParam, true);
		RLGroupCreatePage page = GetGroupCreatePage();
		if (page)
			page.SetInviteButton(false, "");
	}
	
	override void OnInviteReceived() {
		RLGroupCreatePage page = GetGroupCreatePage();
		if (page)
			page.SetInviteButton(true, lastInvite);
	}
	
	void AddPing() {
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup())
			return;
		RLGroup grp = pb.GetRLGroup();
		string name;
		GetGame().GetPlayerName(name);
		RLMarker marker = new RLMarker();
		vector camPos = GetGame().GetCurrentCameraPosition();
		vector camDir = GetGame().GetCurrentCameraDirection().Normalized() * 2000.0;
		Object hitObj;
		vector hitPos, hitNormal;
		float fraction;
		PhxInteractionLayers layers = PhxInteractionLayers.ITEM_SMALL | PhxInteractionLayers.ITEM_LARGE | PhxInteractionLayers.VEHICLE_NOTERRAIN | PhxInteractionLayers.BUILDING | PhxInteractionLayers.CHARACTER | PhxInteractionLayers.VEHICLE | PhxInteractionLayers.ROADWAY | PhxInteractionLayers.FIREGEOM | PhxInteractionLayers.DOOR | PhxInteractionLayers.WATERLAYER | PhxInteractionLayers.TERRAIN | PhxInteractionLayers.FENCE | PhxInteractionLayers.AI;
		DayZPhysics.RayCastBullet(camPos, camPos + camDir, layers, GetGame().GetPlayer(), hitObj, hitPos, hitNormal, fraction);
		marker.SetupMarker(RLMarkerType.GROUP_PING, name, "", hitPos);
		marker.colorR = 255;
		marker.colorG = 255;
		marker.colorB = 0;
		marker.icon = RLMarkerVisibilityManager.Get().GetPingMarkerIcon();
		marker.currentSubgroup = pb.GetMyGroupMarker().currentSubgroup;
		grp.AddMarker(marker);
	}
	
	void ClearPing() {
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup())
			return;
		RLGroup grp = pb.GetRLGroup();
		grp.ClearPing();
	}
	#ifndef RL_DISABLE_CHAT
	void DisplayVoiceLevels(bool b) {
		m_VoiceLevels.Show(b);
	}
	
	string GetCurrentChannel() {
		ChannelCfg cfgchannel = channels.Get(currentChannel);
		if (!cfgchannel)
			return "UNKNOWN";
		return cfgchannel.channelName;
	}
	
	void SendChatMessage(string message) {
		if (muted && message[0] != "!") {
			PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
			if (!pb)
				return;
			pb.MessageImportant("You are muted !");
			return;
		}
		ChannelCfg cfgchannel = channels.Get(currentChannel);
		if (!cfgchannel) {
			RLLogger.Debug("Failed to get Channel Config for Channel " + currentChannel, "AdvancedGroups");
			return;
		}
		if (message[0] == "!") {
			GetGame().ChatPlayer(message);
			message = "+" + message;
		} else if (cfgchannel.directChannel) {
			GetGame().ChatPlayer(message);
			message = "+" + message;
		} else {
			message = "+" + message;
			GetGame().ChatPlayer(message);
		}
		if(GetGame().IsMultiplayer()) {
			ScriptRPC rpc = new ScriptRPC();
			rpc.Write(currentChannel);
			rpc.Write(message);
			rpc.Send(NULL, RLGroupRPCs.RL_GLOBAL_CHAT, true);
			RLLogger.Debug("Sending Multiplayer Chat Message - Channel: " + currentChannel + " Message: " + message, "AdvancedGroups");
		} else {
			string name;
			GetGame().GetPlayerName( name );
			ChatMessageEventParams chat_params = new ChatMessageEventParams( CCDirect, name, message, "" );
			m_Chat.Add( chat_params );
			RLLogger.Debug("Sending Singleplayer Chatmessage", "AdvancedGroups");
		}
	}
	
	void SwitchNextChannel() {
		currentChannel++;
		UpdateChannel();
	}
	
	void UpdateChannel() {
		if (!ChatConfig.HasWritableChannel(channels))
			return;
		currentChannel = currentChannel % channels.Count();
		ChannelCfg cfgchannel = channels.Get(currentChannel);
		if (cfgchannel.groupChannel) {
			PlayerBase pb;
			if (!GetGame().GetPlayer() || !Class.CastTo(pb, GetGame().GetPlayer())) {
				SwitchNextChannel();
				return;
			}
			if (!pb.GetRLGroup()) {
				SwitchNextChannel();
				return;
			}
		}
		if (!cfgchannel.canReceiveMessagesFromPlayers || (cfgchannel.writeChannelPermission != "" && !RLAdmins.Get().HasPermission(cfgchannel.writeChannelPermission)))
			SwitchNextChannel();
	}
	#endif

}