class RLGroupManager {
	
	static ref TStringArray illegalFilenames = new TStringArray();
	static ref TStringArray illegalFilenamesNum = new TStringArray();

	static ref RLGroupManager g_RLGroupManager;
	
	static RLGroupManager Get() {
		if (!g_RLGroupManager) {
			RLConfigManager.Get().LoadImmediate("RLGroupPermissions_");
			RLConfigManager.Get().LoadImmediate("RLTerritoryConfig_");
			g_RLGroupManager = Load();
			
		}
		return g_RLGroupManager;
	}
	
	static void Delete() {
		if (g_RLGroupManager)
			delete g_RLGroupManager;
	}
	
	static RLGroupManager Load() {
		RLGroupManager groupMgr = new RLGroupManager;
		RayLabConfigMover.MoveFolder(RLGroupConstants.SAVE_PREFIX_OLD + RLGroupConstants.SAVE_SUFFIX_GROUPS_FOLDER, RLGroupConstants.DATA_FOLDER + RLGroupConstants.SAVE_SUFFIX_GROUPS_FOLDER);
		RayLabConfigMover.MoveFolder(RLGroupConstants.SAVE_PREFIX_OLD + RLGroupConstants.SAVE_SUFFIX_GROUPSDELETED_FOLDER, RLGroupConstants.DATA_FOLDER + RLGroupConstants.SAVE_SUFFIX_GROUPSDELETED_FOLDER);
		
		RayLabConfigMover.CreateFolders(RLGroupConstants.DATA_FOLDER + RLGroupConstants.SAVE_SUFFIX_GROUPS_FOLDER);
		RayLabConfigMover.CreateFolders(RLGroupConstants.DATA_FOLDER + RLGroupConstants.SAVE_SUFFIX_GROUPSDELETED_FOLDER);
		
		groupMgr.LoadAllGroups();
		RLLogger.Debug("Loaded RLGroupManager", "AdvancedGroups");
		illegalFilenames.Clear();
		illegalFilenames.Insert("CON");
		illegalFilenames.Insert("PRN");
		illegalFilenames.Insert("AUX");
		illegalFilenames.Insert("NUL");
		illegalFilenamesNum.Clear();
		illegalFilenamesNum.Insert("COM");
		illegalFilenamesNum.Insert("LPT");
		illegalFilenames.Insert("LST");
		return groupMgr;
	}
	
	RLGroup GetGroupByHash(int hash) {
		return null;
	}
	
	int GetInitialLevel(string shortname) {
		return 0;
	}
	
	void LoadAllGroups() {}
	
	bool GroupTagTaken(string tag, RLGroup exception = null) { return false; }
	
	array<ref RLGroup> GetAllGroups() {return new array<ref RLGroup>();}
	void SaveGroup(RLGroup grp) {}
	void DeleteGroup(RLGroup group) {}
	RLGroup GetPlayersGroup(string steamid) {return null;}
	RLGroup GetGroupByShortName(string shortname) {return null;}
	RLGroup GetGroupByName(string name) {return null;}
	RLGroup GetPlayersOriginalGroup(string steamid) {return null;}
}