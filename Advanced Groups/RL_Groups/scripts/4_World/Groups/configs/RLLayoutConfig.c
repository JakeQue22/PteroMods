class RLLayoutConfig {
	
	static int CURRENT_VERSION = 1;
	int version = CURRENT_VERSION;
	int playerlistLayoutIndex = 0;
	int gpsSizeIndex = 0;
	int playerMarkerPosIndex = 0;
	int playerMarkerStyleIndex = 0;
	float gpsZoom = 0.2;
	bool streamerModeEnabled = false;
	
	static ref RLLayoutConfig g_RLLayoutConfig;
	static ref ScriptInvoker Event_OnLayoutChanged = new ScriptInvoker();
	static ref ScriptInvoker Event_StreamerModeChanged = new ScriptInvoker();
	static ref ScriptInvoker Event_GPSChanged = new ScriptInvoker();
	
	static RLLayoutConfig Get() {
		if (!g_RLLayoutConfig) {
			g_RLLayoutConfig = Load();
		}
		return g_RLLayoutConfig;
	}
	
	static RLLayoutConfig Load() {
		RLLayoutConfig mgr = new RLLayoutConfig();
		RayLabConfigMover.MoveFile(RLGroupConstants.SAVE_PREFIX_OLD + RLGroupConstants.SAVE_SUFFIX_LAYOUT_MANAGER_TEMP, RLGroupConstants.CONFIG_FOLDER);
		if (!FileExist(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_LAYOUT_MANAGER_TEMP)) {
			mgr.Save();
			return mgr;
		}
		JsonFileLoader<RLLayoutConfig>.JsonLoadFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_LAYOUT_MANAGER_TEMP, mgr);
		if (mgr.version != CURRENT_VERSION) {
			mgr.UpdateVersion();
			mgr.version = CURRENT_VERSION;
			mgr.Save();
		}
		return mgr;
	}
	
	void Save() {
		RayLabConfigMover.CreateFolders(RLGroupConstants.CONFIG_FOLDER);
		JsonFileLoader<RLLayoutConfig>.JsonSaveFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_LAYOUT_MANAGER_TEMP, this);
	}
	
	static void ResetAll() {
		RLLayoutConfig cfg = RLLayoutConfig.Get();
		cfg.playerlistLayoutIndex = 0;
		cfg.streamerModeEnabled = false;
		cfg.gpsSizeIndex = 0;
		cfg.playerMarkerPosIndex = 0;
		cfg.playerMarkerStyleIndex = 0;
		cfg.gpsZoom = 0.2;
		InvokeOnLayoutChanged();
	}
	
	static void Reload() {
		g_RLLayoutConfig = Load();
		InvokeOnLayoutChanged();
	}
	
	static void InvokeOnLayoutChanged() {
		Event_OnLayoutChanged.Invoke();
	}
	
	static void InvokeGPSChanged() {
		Event_GPSChanged.Invoke();
	}
	
	void UpdateVersion() {
		if (version < 1) {
			gpsZoom = 0.2;
			gpsSizeIndex = 0;
			playerMarkerPosIndex = 0;
			playerMarkerStyleIndex = 0;
		}
	}
	
	void SetStreamerMode(bool enabled) {
		this.streamerModeEnabled = enabled;
		RLLogger.Debug("Streamer Mode changed to: " + enabled, "AdvancedGroups");
		Event_StreamerModeChanged.Invoke(enabled);
	}
	
	void SetPlayerlistLayout(int index) {
		playerlistLayoutIndex = index;
		InvokeOnLayoutChanged();
	}
	
	string GetPlayerListLayout() {
		if (playerlistLayoutIndex == 0) {
			return RLLayoutManager.Get().GetLayoutPathWithDefault("PlayerList_Normal", "RayLab_Groups/gui/layouts/playerlist/playerlistentry_default.layout");
		} else if (playerlistLayoutIndex == 1) {
			return RLLayoutManager.Get().GetLayoutPathWithDefault("PlayerList_Small", "RayLab_Groups/gui/layouts/playerlist/playerlistentry_small.layout");
		} else if (playerlistLayoutIndex == 2) {
			return RLLayoutManager.Get().GetLayoutPathWithDefault("PlayerList_Tiny", "RayLab_Groups/gui/layouts/playerlist/playerlistentry_tiny.layout");
		}
		return "";
		
	}
	
}