class RLColorManager {

	static ref RLColorManager g_RLColorManager;
	
	ref array<ref Param2<string, int>> colors = new array<ref Param2<string, int>>();
	string changedColor = "";
	
	static ref ScriptInvoker Event_OnColorChange = new ScriptInvoker();
	
	static RLColorManager Get() {
		if (!g_RLColorManager) {
			g_RLColorManager = Load();
		}
		return g_RLColorManager;
	}
	
	static RLColorManager Load() {
		RayLabConfigMover.MoveFile(RLGroupConstants.SAVE_PREFIX_OLD + RLGroupConstants.SAVE_SUFFIX_COLOR_MANAGER, RLGroupConstants.CONFIG_FOLDER);
		RayLabConfigMover.CreateFolders(RLGroupConstants.CONFIG_FOLDER);
		RLColorManager mgr = new RLColorManager();
		array<ref Param2<string, int>> colorss = new array<ref Param2<string, int>>();
		if (FileExist(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_COLOR_MANAGER)) {
			JsonFileLoader<array<ref Param2<string, int>>>.JsonLoadFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_COLOR_MANAGER, colorss);
		}
		mgr.SetDefaultColors();
		mgr.ReplaceColors(colorss);
		mgr.Save();
		return mgr;
	}
	
	static void Reload() {
		g_RLColorManager = Load();
		InvokeOnChanged();
	}
	
	void Save() {
		array<ref Param2<string, int>> colorssave = new array<ref Param2<string, int>>();
		array<ref Param2<string, int>> colorss = new array<ref Param2<string, int>>();
		if (FileExist(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_COLOR_MANAGER)) {
			JsonFileLoader<array<ref Param2<string, int>>>.JsonLoadFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_COLOR_MANAGER, colorss);
		}
		// Add all Current Colors to the Array
		foreach (Param2<string, int> color : colors) {
			colorssave.Insert(color);
		}
		// Go through all Colors that were saved in the config to not delete entries from other servers.
		foreach (Param2<string, int> oldColor : colorss) {
			bool found = false;
			// Check if the Color is already present and ignore them
			foreach (Param2<string, int> setColor : colorssave) {
				if (oldColor.param1 == setColor.param1) {
					found = true;
					break;
				}
			}
			// if color was not found, add it to the list
			if (!found)
				colorssave.Insert(oldColor);
		}
		RayLabConfigMover.CreateFolders(RLGroupConstants.CONFIG_FOLDER);
		JsonFileLoader<array<ref Param2<string, int>>>.JsonSaveFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_COLOR_MANAGER, colorssave);
	}
	
	private void ReplaceColors(array<ref Param2<string, int>> colors_) {
		foreach (Param2<string, int> color : colors_) {
			SetColor(color.param1, color.param2, false);
		}
	}
	
	void ResetAll() {
		colors.Clear();
		SetDefaultColors();
		InvokeOnChanged();
	}
	
	static void InvokeOnChanged() {
		Event_OnColorChange.Invoke();
	}
	
	int GetColor(string colorStr, int defaultColor = -1) {
		if (changedColor == colorStr) {
			SetColor(colorStr, defaultColor);
			changedColor = "";
		}
		foreach (Param2<string, int> color : colors) {
			if (color && color.param1 == colorStr)
				return color.param2;
		}
		colors.Insert(new Param2<string, int>(colorStr, defaultColor));
		return defaultColor;
	}
	
	void SetColor(string colorStr, int colorARGB, bool add = true) {
		foreach (Param2<string, int> color : colors) {
			if (color && color.param1 == colorStr) {
				color.param2 = colorARGB;
				return;
			}
		}
		if (add)
			colors.Insert(new Param2<string, int>(colorStr, colorARGB));
	}
	
	void SetDefaultColors() {
		GetColor("Player 3D Marker", ARGB(255, 255, 255, 255));
		GetColor("Own Player Map Marker", ARGB(255, 255, 51, 51));
		GetColor("Player Online", ARGB(255, 0, 255, 0));
		GetColor("Player Offline", ARGB(255, 255, 0, 0));
		GetColor("Ping 3D Marker", ARGB(255, 255, 255, 0));
		GetColor("Compass", ARGB(255, 255, 255, 255));
		GetColor("Compass Line", ARGB(255, 255, 0, 0));
		GetColor("Playerlist entry full health", ARGB(255, 255, 255, 255));
		GetColor("Playerlist entry zero health", ARGB(255, 255, 0, 0));
		GetColor("Playerlist entry border", ARGB(255, 255, 255, 255));
	}
	
	void ResetColorToDefault(string colorStr) {
		changedColor = colorStr;
		SetDefaultColors();
	}
	
	TStringArray GetColorStrings() {
		TStringArray arr = new TStringArray();
		foreach (Param2<string, int> color : colors) {
			arr.Insert(color.param1);
		}
		return arr;
	}

}