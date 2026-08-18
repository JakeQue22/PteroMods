/*class RLGroupUI : UIScriptedMenu {

	const int TOP_BUTTON_COUNT = 6;
	const int TOP_BUTTON_ACTIVE_COUNT = 3;

	bool typing = false;
	bool initialized = false, initializedRest = false;
	
	MapWidget mapWidget;
	ref RLMapMarkerManager mapMarkerManager;
	
	Widget leftPanel, fullPanel, topPanel;
	ref array<ref RLGroupPage> pages = new array<ref RLGroupPage>();
	RLGroupPage currentPage;
	ref RLAddMarkerPopup addPopup;
	Widget groupButton = null;
	CheckBoxWidget chckbx_dragMarkers;
	
	string initializedLayout = "";
	
	override Widget Init() {
		if (initialized)
			return layoutRoot;
		//super.Init();
		//initializedLayout = GetMapLayout();
		initializedLayout = "RayLab_Groups/gui/layouts/mapmenu/mapmenu_default.layout";
		if (!RL_GUI_Workaround.CreateWidgetSafe(initializedLayout, null, "mapmenu", layoutRoot)) {
			RLLogger.Debug("Workaround Failed for Main Map " + layoutRoot, "AdvancedGroups");
			return null;
		}
		RLLogger.Debug("Created Root Layout ? " + (layoutRoot != null) + " Path: " + initializedLayout, "AdvancedGroups");
		if (!layoutRoot)
			return null;
		leftPanel = layoutRoot.FindAnyWidget("leftPanel");
		fullPanel = layoutRoot.FindAnyWidget("fullPanel");
		topPanel = layoutRoot.FindAnyWidget("topPanel");
		RLLogger.Debug("Found Left Panel ? " + (leftPanel != null) + " Found Full Panel ? " + (fullPanel != null) + " Found Top Panel ? " + (topPanel != null), "AdvancedGroups");
		if (!leftPanel || !fullPanel || !topPanel)
			return null;
		
		mapWidget = MapWidget.Cast(layoutRoot.FindAnyWidget("Map"));
		chckbx_dragMarkers = CheckBoxWidget.Cast(layoutRoot.FindAnyWidget("chckbx_dragMarkers"));
		initialized = true;
		return layoutRoot;
	}
	
	void OpenGroupPage() {}
	void OnGroupChanged() {}
	RLGroupPage GetPageByName(string name) {return null;}
	
	void ShowMenu() {
		GetGame().GetUIManager().ShowScriptedMenu(this, null);
	}
	
	void HideMenu() {
		GetGame().GetUIManager().HideScriptedMenu(this);
	}

}