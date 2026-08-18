class RLLayoutManager {

	static ref RLLayoutManager g_RLLayoutManager;
	
	static RLLayoutManager Get() {
		if (!g_RLLayoutManager)
			g_RLLayoutManager = new RLLayoutManager();
		return g_RLLayoutManager;
	}
	
	Widget CreateLayout(string name, string defaultPath = "", Widget parent = null, bool immedUpdate = true) {
		string path = GetLayoutPathWithDefault(name, defaultPath);
		Widget w = GetGame().GetWorkspace().CreateWidgets(path, parent, immedUpdate);
		if (!w)
			RLLogger.Error("Could not create layout for " + name + " Path: " + path, "General");
		RLLogger.Verbose("Created Layout " + name + " with path " + path + " and parent " + parent + " is " + w, "General");
		return w;
	}
	
	// Register the layout overwrites like this
	/*
	modded class RLLayoutManager {
		
		override string GetLayoutPath(string name) {
			if (name == "LayoutA") return "Path/To/layout/a.layout";
			if (name == "LayoutB") return "Path/To/layout/b.layout";
			return super.GetLayoutPath(name);
		}
	
	}
	*/
		
	string GetLayoutPath(string name) {
		return GetLayoutPathOriginal(name);
	}
	
	// Do not overwrite
	string GetLayoutPathWithDefault(string name, string defaultPath) {
		string path = GetLayoutPath(name);
		if (path == "")
			return defaultPath;
		return path;
	}
	
	// Do not overwrite
	// This is only for the RayLab mod to get the path. All overwrites are handled through the GetLayoutPath(name) Method
	string GetLayoutPathOriginal(string name) {
		if (name == "AdminMenu") return "RayLab_Core/gui/layouts/adminMenu.layout";
		if (name == "AdminMenuButton") return "RayLab_Core/gui/layouts/adminButton.layout";
		if (name == "WarningMessage") return "RayLab_Core/gui/layouts/warningMessage.layout";
		if (name == "AdminMenu_Currencies") return "RayLab_Core/gui/layouts/currenciesAdmin.layout";
		if (name == "AdminMenu_Updates") return "RayLab_Core/gui/layouts/updates.layout";
		return "";
	}
	
}