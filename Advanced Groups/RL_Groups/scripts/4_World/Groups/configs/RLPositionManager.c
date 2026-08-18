typedef Param3<string, ref vector, int> RLWidgetPosition;

class RLPositionManager {

	static ref RLPositionManager g_RLPositionManager;
	
	ref array<ref RLWidgetPosition> positions = new array<ref RLWidgetPosition>();
	string changedPosition = "";
	
	static ref ScriptInvoker Event_OnPositionChange = new ScriptInvoker();
	
	static RLPositionManager Get() {
		if (!g_RLPositionManager) {
			if (RLGroupMainConfig.Get) {
				g_RLPositionManager = Load();
				InvokeOnChanged();
			} else {
				return new RLPositionManager();
			}
		}
		return g_RLPositionManager;
	}
	
	static RLPositionManager Load() {
		RLLogger.Debug("Loading Position Manager ...", "AdvancedGroups");
		
		RayLabConfigMover.MoveFile(RLGroupConstants.SAVE_PREFIX_OLD + RLGroupConstants.SAVE_SUFFIX_POSITION_MANAGER, RLGroupConstants.CONFIG_FOLDER);
		RLPositionManager mgr = new RLPositionManager();
		array<ref RLWidgetPosition> positionss = new array<ref RLWidgetPosition>();
		if (FileExist(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_POSITION_MANAGER)) {
			JsonFileLoader<array<ref RLWidgetPosition>>.JsonLoadFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_POSITION_MANAGER, positionss);
		}
		RLLogger.Debug("Loaded Positions: " + positionss.Count(), "AdvancedGroups");
		mgr.SetDefaultPositions();
		mgr.ReplacePositions(positionss);
		mgr.Save();
		return mgr;
	}
	
	static void Reload() {
		g_RLPositionManager = Load();
		InvokeOnChanged();
	}
	
	void Save() {
		array<ref RLWidgetPosition> positionssave = new array<ref RLWidgetPosition>();
		array<ref RLWidgetPosition> positionss = new array<ref RLWidgetPosition>();
		if (FileExist(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_POSITION_MANAGER)) {
			JsonFileLoader<array<ref RLWidgetPosition>>.JsonLoadFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_POSITION_MANAGER, positionss);
		}
		// Add all Current Position to the Array
		foreach (RLWidgetPosition pos : positions) {
			if (pos)
				positionssave.Insert(pos);
		}
		// Go through all Positions that were saved in the config to not delete entries from other servers.
		foreach (RLWidgetPosition oldPos : positionss) {
			bool found = false;
			// Check if the Position is already present and ignore them
			foreach (RLWidgetPosition setPos : positionssave) {
				if (oldPos.param1 == setPos.param1) {
					found = true;
					break;
				}
			}
			// if pos was not found, add it to the list
			if (!found && oldPos)
				positionssave.Insert(oldPos);
		}
		RayLabConfigMover.CreateFolders(RLGroupConstants.CONFIG_FOLDER);
		JsonFileLoader<array<ref RLWidgetPosition>>.JsonSaveFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_POSITION_MANAGER, positionssave);
	}
	
	private void ReplacePositions(array<ref RLWidgetPosition> positionss) {
		foreach (RLWidgetPosition pos : positionss) {
			SetPosition(pos.param1, pos.param2, false);
			SetIndex(pos.param1, pos.param3, false);
		}
	}
	
	void ResetAll() {
		positions.Clear();
		SetDefaultPositions();
		InvokeOnChanged();
	}
	
	static void InvokeOnChanged() {
		Event_OnPositionChange.Invoke();
	}
	
	vector GetPosition(string posStr, vector defaultPos = vector.Zero, int defaultIndex = 0, bool insert = true) {
		RLLogger.Debug("Position Manager: " + posStr + " " + defaultPos + " " + defaultIndex + " " + insert, "AdvancedGroups");
		if (!insert)
			return vector.Zero;
		if (changedPosition == posStr) {
			SetPosition(posStr, defaultPos);
			SetIndex(posStr, defaultIndex);
			changedPosition = "";
		}
		foreach (RLWidgetPosition pos : positions) {
			if (pos && pos.param1 == posStr)
				return pos.param2;
		}
		RLLogger.Debug("Inserting New pos", "AdvancedGroups");
		positions.Insert(new RLWidgetPosition(posStr, defaultPos, defaultIndex));
		return defaultPos;
	}
	
	int GetIndex(string posStr) {
		foreach (RLWidgetPosition pos : positions) {
			if (pos && pos.param1 == posStr)
				return pos.param3;
		}
		return 0;
	}
	
	void SetPosition(string posStr, vector pos, bool add = true) {
		foreach (RLWidgetPosition poss : positions) {
			if (poss && poss.param1 == posStr) {
				poss.param2 = pos;
				return;
			}
		}
		if (add)
			positions.Insert(new RLWidgetPosition(posStr, pos, 0));
	}
	
	void SetIndex(string posStr, int index, bool add = true) {
		foreach (RLWidgetPosition poss : positions) {
			if (poss && poss.param1 == posStr) {
				poss.param3 = index;
				return;
			}
		}
		if (add)
			positions.Insert(new RLWidgetPosition(posStr, vector.Zero, index));
	}
	
	void SetDefaultPositions() {
		RLLogger.Debug("Getting default Layout Positions", "AdvancedGroups");
		GetPosition("PlayerList", Vector(0.02, 0.02, 0), 0, RLGroupMainConfig.Get.enablePlayerList);
		GetPosition("Minimap", Vector(0.97, 0.83, 0), 8, RLGroupMainConfig.Get.enableGPS);
		#ifndef RL_DISABLE_CHAT
		GetPosition("Chat", Vector(0.05, 0.85, 0), 6);
		#endif
	}
	
	void ResetPositionToDefault(string posStr) {
		changedPosition = posStr;
		SetDefaultPositions();
	}
	
	TStringArray GetPositionStrings() {
		TStringArray arr = new TStringArray();
		foreach (RLWidgetPosition pos : positions) {
			arr.Insert(pos.param1);
		}
		return arr;
	}

}