class RLGroupTimeoutConfig {

	static const string CONFIG_PATH = "$profile:RayLab/Config/RLGroup/Timeout.json";
	static ref RLGroupTimeoutConfig g_RLGroupTimeoutConfig;
	
	static RLGroupTimeoutConfig Get() {
		if (!g_RLGroupTimeoutConfig)
			g_RLGroupTimeoutConfig = Load();
		return g_RLGroupTimeoutConfig;
	}
	
	static void Delete() {
		if (g_RLGroupTimeoutConfig)
			delete g_RLGroupTimeoutConfig;
	}
	
	static RLGroupTimeoutConfig Load() {
		RLGroupTimeoutConfig cfg = new RLGroupTimeoutConfig();
		if (!FileExist(CONFIG_PATH)) {
			RayLabConfigMover.CreateParentFolders(CONFIG_PATH);
			cfg = LoadDefault();
			cfg.Save();
		} else {
			JsonFileLoader<RLGroupTimeoutConfig>.JsonLoadFile(CONFIG_PATH, cfg);
		}
		cfg.UpdateVersion();
		cfg.OnLoad();
		return cfg;
	}
	
	static RLGroupTimeoutConfig LoadDefault() {
		RLGroupTimeoutConfig cfg = new RLGroupTimeoutConfig();
		return cfg;
	}
	
	static const int CURRENT_VERSION = 1;
	int version = CURRENT_VERSION;
	bool enableTimeout = false;
	int timeoutDifferentGroupDurationSeconds = 86400;
	int timeoutSameGroupDurationSeconds = 3600;
	ref array<ref RLGroupTimeoutEntry> players = new array<ref RLGroupTimeoutEntry>();
	[NonSerialized()]
	ref map<string, ref RLGroupTimeoutEntry> playersMap = new map<string, ref RLGroupTimeoutEntry>();
	
	void Save() {
		JsonFileLoader<RLGroupTimeoutConfig>.JsonSaveFile(CONFIG_PATH, this);
	}
	
	void UpdateVersion() {
		if (version == CURRENT_VERSION)
			return;
		
		Save();
	}
	
	void OnLoad() {
		int timeout = Math.Max(timeoutDifferentGroupDurationSeconds, timeoutSameGroupDurationSeconds);
		for (int i = 0; i < players.Count(); i++) {
			RLGroupTimeoutEntry entry2 = players.Get(i);
			if (entry2 == null || entry2.AllTimeoutsExpired(timeout)) {
				players.Remove(i--);
			}
		}
		playersMap = new map<string, ref RLGroupTimeoutEntry>();
		foreach (RLGroupTimeoutEntry entry : players) {
			playersMap.Insert(entry.steamid, entry);
		}
	}
	
	RLGroupTimeoutEntry GetPlayerEntry(string steamid, bool addIfNotFound) {
		RLGroupTimeoutEntry entry = null;
		if (!playersMap.Find(steamid, entry)) {
			entry = new RLGroupTimeoutEntry();
			entry.steamid = steamid;
			if (addIfNotFound) {
				playersMap.Insert(steamid, entry);
				players.Insert(entry);
			}
		}
		return entry;
	}
	
	void OnGroupJoin(string steamid, string group) {
		if (!enableTimeout)
			return;
		RLGroupTimeoutEntry entry = GetPlayerEntry(steamid, true);
		entry.lastGroup = group;
		entry.timeoutStartTimestamp = CurrentTime();
		Save();
	}
	
	bool CanJoinGroup(string steamid, string group, out int remainingSeconds) {
		if (!enableTimeout)
			return true;
		RLGroupTimeoutEntry entry = GetPlayerEntry(steamid, false);
		remainingSeconds = entry.GetRemainingTime(group);
		if (remainingSeconds <= 0)
			return true;
		return RLAdmins.Get().HasPermission("group.ignoretimeout", steamid) && RLAdmins.Get().IsActive(steamid);
	}
	
	static int CurrentTime() {
		return (new RLDate()).Init(true).GetTimestamp();
	}
	
}
class RLGroupTimeoutEntry {

	string steamid;
	string lastGroup;
	int timeoutStartTimestamp;
	
	int GetRemainingTime(string group) {
		int timeout = RLGroupTimeoutConfig.Get().timeoutDifferentGroupDurationSeconds;
		if (group == lastGroup) {
			timeout = RLGroupTimeoutConfig.Get().timeoutSameGroupDurationSeconds;
		}
		int now = RLGroupTimeoutConfig.Get().CurrentTime();
		return timeout - now + timeoutStartTimestamp;
	}
	
	bool AllTimeoutsExpired(int maxTimeout) {
		int now = RLGroupTimeoutConfig.CurrentTime();
		return maxTimeout - now + timeoutStartTimestamp <= 0;
	}
	
}