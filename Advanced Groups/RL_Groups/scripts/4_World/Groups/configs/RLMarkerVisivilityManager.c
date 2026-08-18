class RLMarkerVisibilityManager {

	static int CURRENT_VERSION = 7;
	int version = CURRENT_VERSION;
	
	ref array<ref RLMarkerVisibilityEntry> entries = new array<ref RLMarkerVisibilityEntry>();
	ref array<ref RLGlobalVisibilityEntry> globalentries = new array<ref RLGlobalVisibilityEntry>();
	ref array<ref Param2<string, bool>> hiddenChannels = new array<ref Param2<string, bool>>();
	
	int globalVisiblityState;
	string pingMarkerIcon = "RayLab_Groups\\gui\\icons\\ping.paa";
	string playerMarkerIcon = "RayLab_Groups\\gui\\icons\\player.paa";
	bool compassEnabled = true;
	bool playerlistEnabled = true;
	bool showNoBuildZones = true;
	bool disableShowClantextures = false;
	bool gpsEnabled = true;
	bool centerMapOnPlayer = false;
	int pingSize = 16;
	int playerSize = 16;
	int chatSize = 15;
	
	static ref RLMarkerVisibilityManager g_RLMarkerVisibilityManager;
	
	static void Delete() {
		if (g_RLMarkerVisibilityManager)
			delete g_RLMarkerVisibilityManager;
	}
	
	void ~RLMarkerVisibilityManager() {
		Save();
	}
	
	static RLMarkerVisibilityManager Get() {
		if (!g_RLMarkerVisibilityManager) {
			g_RLMarkerVisibilityManager = Load();
		}
		return g_RLMarkerVisibilityManager;
	}
	
	static RLMarkerVisibilityManager Load() {
		RLMarkerVisibilityManager mgr;
		
		RayLabConfigMover.MoveFile(RLGroupConstants.SAVE_PREFIX_OLD + RLGroupConstants.SAVE_SUFFIX_PRIVATE_MARKER_STATES, RLGroupConstants.CONFIG_FOLDER);
		if (!FileExist(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_PRIVATE_MARKER_STATES)) {
			mgr = LoadDefault();
			RayLabConfigMover.CreateFolders(RLGroupConstants.CONFIG_FOLDER);
			JsonFileLoader<RLMarkerVisibilityManager>.JsonSaveFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_PRIVATE_MARKER_STATES, mgr);
			return mgr;
		}
		mgr = new RLMarkerVisibilityManager();
		JsonFileLoader<RLMarkerVisibilityManager>.JsonLoadFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_PRIVATE_MARKER_STATES, mgr);
		if (mgr.version != CURRENT_VERSION) {
			RLLogger.Info("Upgrading Visibility Manager Version from " + mgr.version + " to " + CURRENT_VERSION, "AdvancedGroups");
			mgr.UpgradeVersion(mgr.version, CURRENT_VERSION);
			mgr.Save();
		}
		return mgr;
	}
	
	static RLMarkerVisibilityManager LoadDefault() {
		RLMarkerVisibilityManager def = new RLMarkerVisibilityManager;
		def.version = CURRENT_VERSION;
		def.globalVisiblityState = 0;
		def.pingMarkerIcon = "RayLab_Groups\\gui\\icons\\ping.paa";
		def.compassEnabled = true;
		def.playerlistEnabled = true;
		def.showNoBuildZones = true;
		def.gpsEnabled = true;
		return def;
	}
	
	void UpgradeVersion(int from, int to) {
		version = CURRENT_VERSION;
		if (from < 1) {
			pingMarkerIcon = "RayLab_Groups\\gui\\icons\\ping.paa";
			compassEnabled = true;
			playerlistEnabled = true;
		}
		if (from < 2) {
			chatSize = 15;
		}
		if (from < 3) {
			pingSize = 16;
		}
		if (from < 4) {
			showNoBuildZones = true;
		}
		if (from < 5) {
			playerSize = 16;
			playerMarkerIcon = "RayLab_Groups\\gui\\icons\\player.paa";
		}
		if (from < 6) {
			hiddenChannels = new array<ref Param2<string, bool>>();
		}
	}
	
	bool IsChannelHidden(string name) {
		bool hidden = false;
		if (!hiddenChannels)
			hiddenChannels = new array<ref Param2<string, bool>>();
		foreach (Param2<string, bool> hiddenParam : hiddenChannels) {
			if (hiddenParam.param1 == name) {
				hidden = hiddenParam.param2;
				break;
			}
		}
		RLLogger.Debug("Is Channel Hidden ? " + name + "=" + hidden, "AdvancedGroups");
		return hidden;
	}
	
	void SetChannelHidden(string name, bool hidden) {
		if (!hiddenChannels)
			hiddenChannels = new array<ref Param2<string, bool>>();
		RLLogger.Debug("Setting Channel Hidden ? " + name + "=" + hidden, "AdvancedGroups");
		foreach (Param2<string, bool> hiddenParam : hiddenChannels) {
			if (hiddenParam.param1 == name) {
				hiddenParam.param2 = hidden;
				return;
			}
		}
		hiddenChannels.Insert(new Param2<string, bool>(name, hidden));
		
	}
	
	int GetChatSize() {
		return Math.Max(7, chatSize);
	}
	
	void Save() {
		for (int i = 0; i < entries.Count(); i++) {
			RLMarkerVisibilityEntry entry = entries.Get(i);
			if (entry.displaystate == 0) {
				entries.Remove(i--);
			}
		}
		RayLabConfigMover.CreateFolders(RLGroupConstants.CONFIG_FOLDER);
		JsonFileLoader<RLMarkerVisibilityManager>.JsonSaveFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_PRIVATE_MARKER_STATES, this);
	}
	
	string GetPingMarkerIcon() {
		if (!FileExist(pingMarkerIcon) || pingMarkerIcon.Length() < 4)
			return "RayLab_Groups\\gui\\icons\\ping.paa";
		return pingMarkerIcon;
	}
	
	string GetPlayerMarkerIcon() {
		if (!FileExist(playerMarkerIcon) || playerMarkerIcon.Length() < 4)
			return "RayLab_Groups\\gui\\icons\\player.paa";
		return playerMarkerIcon;
	}
	
	void ResetPingToDefault() {
		SetPingMarkerIcon("RayLab_Groups\\gui\\icons\\ping.paa");
		SetPlayerMarkerIcon("RayLab_Groups\\gui\\icons\\player.paa");
		pingSize = 16;
		playerSize = 16;
		chatSize = 15;
	}
	
	void ResetPingToLast() {
		RLMarkerVisibilityManager mgr = new RLMarkerVisibilityManager();
		if (FileExist(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_PRIVATE_MARKER_STATES)) {
			JsonFileLoader<RLMarkerVisibilityManager>.JsonLoadFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_PRIVATE_MARKER_STATES, mgr);
			SetPingMarkerIcon(mgr.pingMarkerIcon);
			chatSize = mgr.chatSize;
		} else {
			ResetPingToDefault();
		}
	}
	
	void SetPingMarkerIcon(string icon) {
		this.pingMarkerIcon = icon;
	}
	
	void SetPlayerMarkerIcon(string icon) {
		this.playerMarkerIcon = icon;
	}
	
	TStringArray GetPingMarkerIcons() {
		TStringArray arr = new TStringArray();
		foreach (string icon : RLGroupMainConfig.Get.availableIcons) {
			arr.Insert(icon);
		}
		if (arr.Find("RayLab_Groups\\gui\\icons\\ping.paa") == -1)
			arr.Insert("RayLab_Groups\\gui\\icons\\ping.paa");
		return arr;
	}
	
	TStringArray GetPlayerMarkerIcons() {
		TStringArray arr = new TStringArray();
		foreach (string icon : RLGroupMainConfig.Get.availableIcons) {
			arr.Insert(icon);
		}
		if (arr.Find("RayLab_Groups\\gui\\icons\\player.paa") == -1)
			arr.Insert("RayLab_Groups\\gui\\icons\\player.paa");
		return arr;
	}
	
	RLMarkerVisibilityEntry GetVisibilityOrAdd(int uid) {
		foreach (RLMarkerVisibilityEntry entry : entries) {
			if (entry.uid == uid)
				return entry;
		}
		RLMarkerVisibilityEntry ent = new RLMarkerVisibilityEntry();
		ent.uid = uid;
		ent.displaystate = 0;
		entries.Insert(ent);
		return ent;
	}
	
	RLGlobalVisibilityEntry GetGlobalVisibilityOrAdd(RLMarkerType type) {
		foreach (RLGlobalVisibilityEntry entry : globalentries) {
			if (entry.type == type)
				return entry;
		}
		RLGlobalVisibilityEntry ent = new RLGlobalVisibilityEntry();
		ent.type = type;
		ent.displaystate = 0;
		globalentries.Insert(ent);
		return ent;
	}
	
	bool Is3DVisiblie(int uid, RLMarkerType type, bool testType = true) {
		if (GetVisibilityOrAdd(uid).displaystate != 0)
			return false;
		return !testType || IsGlobal3DVisible(type);
	}
	
	bool IsMapVisible(int uid, RLMarkerType type, bool testType = true) {
		if (GetVisibilityOrAdd(uid).displaystate == 2)
			return false;
		return !testType || IsGlobal2DVisible(type);
	}
	
	bool IsGlobal3DVisible(RLMarkerType type) {
		return GetGlobalVisibilityOrAdd(type).displaystate == 0;
	}
	
	bool IsGlobal2DVisible(RLMarkerType type) {
		return GetGlobalVisibilityOrAdd(type).displaystate != 2;
	}
	
	int GetNextState() {
		globalVisiblityState++;
		globalVisiblityState = globalVisiblityState % 6;
		if (globalVisiblityState == 0) {
			SetAllGlobalStates(0);
		} else if (globalVisiblityState == 1) {
			SetAllGlobalStates(0);
			GetGlobalVisibilityOrAdd(RLMarkerType.SERVER_STATIC).displaystate = 1;
			GetGlobalVisibilityOrAdd(RLMarkerType.SERVER_DYNAMIC).displaystate = 1;
		} else if (globalVisiblityState == 2) {
			SetAllGlobalStates(0);
			GetGlobalVisibilityOrAdd(RLMarkerType.SERVER_STATIC).displaystate = 1;
			GetGlobalVisibilityOrAdd(RLMarkerType.SERVER_DYNAMIC).displaystate = 1;
			GetGlobalVisibilityOrAdd(RLMarkerType.PRIVATE_MARKER).displaystate = 1;
		} else if (globalVisiblityState == 3) {
			SetAllGlobalStates(1);
			GetGlobalVisibilityOrAdd(RLMarkerType.GROUP_PLAYER_MARKER).displaystate = 0;
			GetGlobalVisibilityOrAdd(RLMarkerType.GROUP_PING).displaystate = 0;
		} else if (globalVisiblityState == 4) {
			SetAllGlobalStates(1);
			GetGlobalVisibilityOrAdd(RLMarkerType.SERVER_STATIC).displaystate = 0;
			GetGlobalVisibilityOrAdd(RLMarkerType.SERVER_DYNAMIC).displaystate = 0;
		} else {
			SetAllGlobalStates(1);
		}
		Save();
		return globalVisiblityState;
	}
	
	void SetAllGlobalStates(int state) {
		GetGlobalVisibilityOrAdd(RLMarkerType.SERVER_STATIC).displaystate = state;
		GetGlobalVisibilityOrAdd(RLMarkerType.SERVER_DYNAMIC).displaystate = state;
		GetGlobalVisibilityOrAdd(RLMarkerType.GROUP_PING).displaystate = state;
		GetGlobalVisibilityOrAdd(RLMarkerType.GROUP_MARKER).displaystate = state;
		GetGlobalVisibilityOrAdd(RLMarkerType.GROUP_PLAYER_MARKER).displaystate = state;
		GetGlobalVisibilityOrAdd(RLMarkerType.PRIVATE_MARKER).displaystate = state;
	}
	
	string GetCurrentStateName() {
		if (globalVisiblityState == 0) {
			return "All Visible";
		} else if (globalVisiblityState == 1) {
			return "No Server Marker";
		} else if (globalVisiblityState == 2) {
			return "Only Group Marker";
		} else if (globalVisiblityState == 3) {
			return "Only Player Marker";
		} else if (globalVisiblityState == 4) {
			return "Only Server Marker";
		}
		return "All Hidden";
	}

}
class RLMarkerVisibilityEntry {

	int uid;
	int displaystate; // 0 3D+2D // 1 2D // 2 None
	
	int GetNextState() {
		displaystate++;
		displaystate = displaystate % 3;
		RLMarkerVisibilityManager.Get().Save();
		return displaystate;
	}

}
class RLGlobalVisibilityEntry {

	RLMarkerType type;
	int displaystate; // 0 3D+2D // 1 2D // 2 None
	
	int GetNextState() {
		displaystate++;
		displaystate = displaystate % 3;
		return displaystate;
	}

}