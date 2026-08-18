class RLGroupUI : UIScriptedMenu {
	
	const int TOP_BUTTON_COUNT = 7;
	const float TOP_BUTTON_GAP_PERCENT = 0.005;
	const int TOP_BUTTON_HEIGHT_PX = 35;
	const int TOP_BUTTON_ACTIVE_COUNT = 3;

	bool typing = false;
	bool initialized = false, initializedRest = false;
	
	MapWidget mapWidget;
	Widget mapNotFound;
	ref RLMapMarkerManager mapMarkerManager;
	TextWidget txt_pos_x, txt_pos_y;
	ref Timer positionUpdateTimer = new Timer();
	
	Widget leftPanel, fullPanel, topPanel;
	ref array<ref RLGroupPage> pages = new array<ref RLGroupPage>();
	RLGroupPage currentPage;
	ref RLAddMarkerPopup addPopup;
	Widget groupButton = null;
	CheckBoxWidget chckbx_dragMarkers;
	Widget mapLegend, mapLegendParent;
	
	TextWidget mapNotFoundText;
	ImageWidget mapNotFoundImage;
	
	void RLGroupUI() {
		RLLayoutConfig.Event_StreamerModeChanged.Insert(OnStreamerModeChange);
		RLColorManager.Event_OnColorChange.Insert(OnColorChange);
		RLConfigManager.Get().GetEventOnConfigReceived(RL_NoBuildConfig).Insert(OnBuildZonesChange);
		RLWidgetUtils.Event_OnScreenSizeChanged.Insert(OnSizeChange);
	}
	void ~RLGroupUI() {
		if (RLLayoutConfig.Event_StreamerModeChanged)
			RLLayoutConfig.Event_StreamerModeChanged.Remove(OnStreamerModeChange);
		if (RLColorManager.Event_OnColorChange)
			RLColorManager.Event_OnColorChange.Remove(OnColorChange);
		if (RLGroupPage.topButtons)
			RLGroupPage.topButtons.Clear();
		foreach (RLGroupPage page : pages) {
			delete page;
		}
		pages.Clear();
		RLConfigManager.Get().GetEventOnConfigReceived(RL_NoBuildConfig).Remove(OnBuildZonesChange);
		RLWidgetUtils.Event_OnScreenSizeChanged.Insert(OnSizeChange);
	}
	
	void OnStreamerModeChange(bool enabled) {
		RLLogger.Debug("OnStreamerModeChange RLGroupUI: " + enabled, "AdvancedGroups");
		ImageWidget serverLogo = ImageWidget.Cast(layoutRoot.FindAnyWidget("logo"));
		if (!enabled) {
			RLAppearanceConfig.Get.LoadLogo(serverLogo);
		} else if (serverLogo)
			serverLogo.Show(false);
	}

	void OnColorChange() {
		AddMapMarker();
	}
	
	void ReInitAll() {
		RLDataSerializer data = new RLDataSerializer();
		StoreAllWidgetData(data);
		initialized = false;
		initializedRest = false;
		HideMenu();
		ShowMenu();
		RestoreAllWidgetData(data);
	}
	
	void ShowMenu() {
		GetGame().GetUIManager().ShowScriptedMenu(this, null);
		InitPageRest();
		OnStreamerModeChange(RLLayoutConfig.Get().streamerModeEnabled);
	}
	
	void HideMenu() {
		GetGame().GetUIManager().HideScriptedMenu(this);
	}
	
	void StoreAllWidgetData(RLDataSerializer data) {
		int current = GetCurrentPage();
		data.Write(new Param1<int>(current));
		foreach (RLGroupPage page : pages) {
			page.StoreAllWidgetData(data);
		}
		addPopup.StoreAllWidgetData(data);
	}
	
	void RestoreAllWidgetData(RLDataSerializer data) {
		Param1<int> currentParam = Param1<int>.Cast(data.Read());
		SetCurrentPage(currentParam.param1);
		foreach (RLGroupPage page : pages) {
			page.RestoreAllWidgetData(data);
		}
		addPopup.RestoreAllWidgetData(data);
	}
	
	override Widget Init() {
		if (initialized)
			return layoutRoot;
		super.Init();
		layoutRoot = RLLayoutManager.Get().CreateLayout("GroupUI", "RayLab_Groups/gui/layouts/mapmenu/mapmenu_default.layout");
		RLLogger.Debug("Created Root Layout ? " + (layoutRoot != null), "AdvancedGroups");
		if (!layoutRoot)
			return null;
		ConnectClassWidgetVariables(this, layoutRoot, {"groupButton"}, {"mapWidget", "Map", "mapNotFound", "MapNotFound"});
		RLLogger.Debug("Found Left Panel ? " + (leftPanel != null) + " Found Full Panel ? " + (fullPanel != null) + " Found Top Panel ? " + (topPanel != null), "AdvancedGroups");
		if (!leftPanel || !fullPanel || !topPanel)
			return null;
		if (RLGroupMainConfig.Get.enableUIPlayerPosition) {
			txt_pos_x.Show(true);
			txt_pos_y.Show(true);
			positionUpdateTimer.Run(0.1, this, "UpdateUIPosition", null, true);
		} else {
			txt_pos_x.Show(false);
			txt_pos_y.Show(false);
			positionUpdateTimer.Stop();
		}
		
		initialized = true;
		return layoutRoot;
	}
	
	void UpdateUIPosition() {
		Man player = GetGame().GetPlayer();
		if (!player) {
			txt_pos_x.SetText("");
			txt_pos_y.SetText("");
		} else {
			vector pos = player.GetPosition();
			txt_pos_x.SetText("X: " + FloatToString(pos[0], true));
			txt_pos_y.SetText("Z: " + FloatToString(pos[2], true));
		}
	}
	
	string FloatToString(float f, bool addPoint) {
		string txt = "" + f;
		int index = txt.IndexOf(".");
		if (addPoint) {
			if (index > 0)
				txt = txt.Substring(0, index + 2);
			else
				txt = txt + ".0";
		} else {
			if (index > 0)
				txt = txt.Substring(0, index);
		}
		return txt;
	}
	
	void InitPageRest() {
		if (initializedRest)
			return;
		RLLogger.Debug("InitPageRest", "AdvancedGroups");
		mapMarkerManager = new RLMapMarkerManager(mapWidget);
		SetServerLogo();
		InitPages();
		ChangePageTo(pages.Get(GetDefaultPageNum()));
		RLLogger.Debug("Init Page Rest", "AdvancedGroups");
		initializedRest = true;
	}
	
	void OnBuildZonesChange() {
		AddMapMarker();
	}
	
	int GetDefaultPageNum() {
		return 0;
	}
	
	
	void SetServerLogo() {
		ImageWidget serverLogo = ImageWidget.Cast(layoutRoot.FindAnyWidget("logo"));
		RLAppearanceConfig.Get.LoadLogo(serverLogo);
	}
	
	void CreateMapLegend() {
		if (mapLegend == null)
			return;
		RLLogger.Debug("Creating Map Legend ...", "AdvancedGroups");
		Widget child = mapLegend.GetChildren();
		while (child) {
			child.Unlink();
			child = mapLegend.GetChildren();
		}
		RLLogger.Debug("Show Map Legende ? " + RLGroupMainConfig.Get.enableMapLegend, "AdvancedGroups");
		if (RLGroupMainConfig.Get.enableMapLegend) {
			mapLegend.Show(true);
			mapLegendParent.Show(true);
			
			int i = 0;
			float w,h;
			if (RLGroupMainConfig.Get.mapLegendTitle != "") {
				TextWidget legendTitle = TextWidget.Cast(RLLayoutManager.Get().CreateLayout("MapLegendTitle", "RayLab_Groups/gui/layouts/mapmenu/map_legend_title.layout", mapLegend));
				legendTitle.SetText(RLGroupMainConfig.Get.mapLegendTitle);
				legendTitle.GetScreenSize(w,h);
				legendTitle.SetPos(0, 5);
				mapLegend.SetSize(1, 5 + h + 5);
				i++;
			}
			foreach (MapLegendItem item : RLGroupMainConfig.Get.mapLegend) {
				RLLogger.Verbose("Adding Map Legend Item: " + item.iconPath + " " + item.name, "AdvancedGroups");
				item.CreateWidget(mapLegend, i);
				i++;
			}
			mapLegend.GetScreenSize(w,h);
			mapLegendParent.SetSize(w,h);
			RLLogger.Verbose("W:" +w + " H:" + h, "AdvancedGroups");
		} else {
			mapLegend.Show(false);
			mapLegendParent.Show(false);
		}
	}
	
	bool HasMapInInventory(PlayerBase player) {
		return RL_PlayerBase_Utils.HasItemsInInventory(player, RLGroupMainConfig.Get.mapItems);
	}
	
	bool HasTranceiverInInventory(PlayerBase player) {
		return RL_PlayerBase_Utils.HasItemsInInventory(player, RLGroupMainConfig.Get.playerMarkerItems);
	}
	
	void OpenGroupPage() {
		RLLogger.Verbose("Group Page is being opened. Button: " + groupButton, "AdvancedGroups");
		if (groupButton)
			SetCurrentPage(groupButton);
	}
	
	void InitPages() {
		RLGroupPage.topButtons.Clear();
		foreach (RLGroupPage page : pages) {
			delete page;
		}
		pages.Clear();
		
		addPopup = new RLAddMarkerPopup();
		addPopup.Init(this);//*/
		
		InitPage(new RLInfoPage());
		InitPage(new RLGroupCreatePage());
		InitPage(new RLGroupManagePage());
		InitPage(new RLMarkerListPage());
		InitPage(new RLClientSettingsPage());
		CreateCustomPages();
	}
	
	void InitPage(RLGroupPage page) {
		pages.Insert(page);
		page.InitPage(this);
	}
	
	RLGroupPage GetPageByName(string name) {
		foreach (RLGroupPage page : pages) {
			if (page.buttonname == name)
				return page;
		}
		return null;
	}
	
	void CreateCustomPages() {
		
	}
	
	int GetCurrentPage() {
		return pages.Find(currentPage);
	}
	
	bool SetCurrentPage(int index) {
		RLGroupPage page = pages.Get(index);
		if (!page)
			return false;
		return SetCurrentPage(page.buttonWidget);
	}
	
	bool SetCurrentPage(Widget buttonClicked) {
		if (!buttonClicked)
			return false;
		foreach (RLGroupPage page : pages) {
			if (page.OnTopButtonClicked(buttonClicked)) {
				ChangePageTo(page);
				return true;
			}
		}
		return false;
	}
	
	void ReloadCurrentPage() {
		Widget btn = currentPage.buttonWidget;
		SetCurrentPage(btn);
	}
	
	void ChangePageTo(RLGroupPage page) {
		RLGroupPage oldPage = currentPage;
		currentPage = page;
		if (oldPage)
			oldPage.OnHide();
		page.OnShow();
	}
	
	void OnMarkerChanged() {
		if (currentPage)
			currentPage.OnMarkerChanged();
	}
	
	override bool OnMouseEnter(Widget w, int x, int y) {
		if (super.OnMouseEnter(w, x, y))
			return true;
		typing = (w && EditBoxWidget.Cast(w));
		if (currentPage && currentPage.OnMouseEnter(w, x, y))
			return true;
		return false;
	}
	
	override bool OnMouseLeave(Widget w, Widget enterW, int x, int y) {
		if (super.OnMouseLeave(w, enterW, x, y))
			return true;
		if (currentPage && currentPage.OnMouseLeave(w, x, y))
			return true;
		return false;
	}
	
	override bool OnClick(Widget w, int x, int y, int button) {
		RLLogger.Debug("Group On Click: " + w + " " + button, "AdvancedGroups");
		if (super.OnClick(w, x, y, button))
			return true;
		if (SetCurrentPage(w))
			return true;
		if (currentPage && currentPage.OnClick(w))
			return true;
		if (addPopup.OnClick(w))
			return true;
		if (w == chckbx_dragMarkers) {
			mapMarkerManager.SetDragable(chckbx_dragMarkers.IsChecked());
		}
		return false;
	}
	
	override bool OnChange(Widget w, int x, int y, bool finished) {
		if (super.OnChange(w, x, y, finished))
			return true;
		if (currentPage && currentPage.OnChange(w))
			return true;
		if (addPopup.OnChange(w))
			return true;
		return false;
	}
	
	override bool OnItemSelected(Widget w, int x, int y, int row, int  column,	int  oldRow, int  oldColumn) {
		if (super.OnItemSelected(w, x, y, row, column, oldRow, oldColumn))
			return true;
		if (currentPage && currentPage.OnItemSelected(w, row, column))
			return true;
		return false;
	}
	
	override bool OnDoubleClick(Widget w, int x, int y, int button) {
		if (super.OnDoubleClick(w, x, y, button))
			return true;
		if (w == mapWidget) {
			if (RLGroupMainConfig.Get.disableMarkerPlacement && !RLAdmins.Get().IsActive())
				return true;
			if (button == 0) {
				addPopup.ShowPopup(x, y, false, null);
			} else if (button == 1) {
				vector mousePos = Vector(x + 10,y + 10,0);
				vector mapPos = mapWidget.ScreenToMap(mousePos);
				RLMarker marker = addPopup.FindMarkerInRadius(mapPos);
				RLLogger.Debug("Edit Marker: " + marker, "AdvancedGroups");
				if (marker)
					addPopup.ShowPopup(x, y, true, marker);
			}
			return true;
		}
		if (currentPage && currentPage.OnDoubleClick(w))
			return true;
		return false;
	}
	
	
	void OnGroupChanged() {
		if (currentPage && !currentPage.IsInherited(RLAdminPage)) {
			ReloadCurrentPage();
		}
		RLLogger.Info("UI On Group Changed", "AdvancedGroups");
		foreach (RLGroupPage page : pages)
			page.OnGroupChanged();
		if (addPopup)
			addPopup.OnGroupChanged();
		AddMapMarker();
		UpdateMarkerListLater();
	}
	
	override void Update(float timeslice) {
		super.Update(timeslice);
		if (GetUApi() && GetUApi().GetInputByName("UAUIBack").LocalPress()) {
			//initialized = false;
			HideMenu();
		}
		if (currentPage)
			currentPage.OnUpdateFrame();
		if (addPopup)
			addPopup.OnUpdateFrame();
		if (mapMarkerManager)
			mapMarkerManager.UpdateFrame();
	}
	
	override void OnShow() {
		RLLogger.Debug("OnShow RLGroupUI", "AdvancedGroups");
		if (!initialized) {
			HideMenu();
			return;
		}
		super.OnShow();
		RLMarker.hideAllMarkers = true;
		PPEffects.SetBlurMenu(0.7);
		Mission mission = GetGame().GetMission();
		if (mission) {
			mission.PlayerControlDisable( INPUT_EXCLUDE_ALL );
			mission.GetHud().ShowHudUI(false);
			mission.GetHud().ShowQuickbarUI(false);
		}
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(UpdateAll, 1000, true);
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(CheckNewMarkers, 100, true);
		if (currentPage)
			currentPage.OnShow();
		AddMapMarker();
		AddCustomMarkersOnMapOpen(mapWidget, mapMarkerManager);
		AddCustomMarkersOnMapOpen();
		UpdateButtonVisibility();
		CreateMapLegend();
		CenterMapOnPlayer();
	}
	
	void UpdateButtonVisibility() {
		foreach (RLGroupPage page : pages) {
			page.UpdateButtonDisplay();
		}
	}
	
	bool IsMapVisible() {
		if (RLGroupMainConfig.Get.mapRequireItem) {
			bool hasMap = HasMapInInventory(PlayerBase.Cast(GetGame().GetPlayer()));
			RLLogger.Debug("Map Requires an Item. Has Item ? " + hasMap, "AdvancedGroups");
			return hasMap;
		}
		return true;
	}
	
	bool IsOtherPlayersVisible() {
		return !RLGroupMainConfig.Get.requireItemToSeeGroupMembers || HasTranceiverInInventory(PlayerBase.Cast(GetGame().GetPlayer()));
	}
	
	override void OnHide() {
		if (!initialized)
			return;
		super.OnHide();
		RLMarker.hideAllMarkers = false;
		PPEffects.SetBlurMenu(0);
		Mission mission = GetGame().GetMission();
		if (mission) {
			mission.PlayerControlEnable(false);
			mission.GetHud().ShowHudUI(true);
			mission.GetHud().ShowQuickbarUI(true);
		}
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Remove(UpdateAll);
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Remove(CheckNewMarkers);
		if (currentPage)
			currentPage.OnHide();
		if (mapMarkerManager)
			mapMarkerManager.ClearMarkers();
	}
	
	void UpdateAll() {
		if (currentPage)
			currentPage.OnUpdateSlow();
	}
	
	int lastGroupMarkerCount = 0, lastServerMarkerHash = 0, lastPrivateMarkerCount = 0, lastPlayerMarkerCount = 0, lastCustomMarkerCount = 0;
	bool lastNoBuildEnabled = false;
	
	void AddMapMarker() {
		AddMapMarker(mapWidget, mapMarkerManager, true);
	}
	void AddMapMarker(MapWidget mapWidget_, RLMapMarkerManager mapMarkerMgr, bool addPlayer) {
		if (!mapMarkerMgr)
			return;
		mapMarkerMgr.ClearMarkers();
		AddGroupMarkers(mapMarkerMgr);
		AddServerMarkers(mapMarkerMgr);
		AddPrivateMarkers(mapMarkerMgr);
		if (addPlayer)
			AddPlayerMarker(mapMarkerMgr);
		AddCustomMarkers(mapWidget_, mapMarkerMgr);
		AddCustomMarkers();
		if (RLMarkerVisibilityManager.Get().showNoBuildZones && RL_NoBuildConfig.Get && RL_NoBuildConfig.Get.enabled && RL_NoBuildConfig.Get.displayOnMap)
			AddNoBuildZones(mapMarkerMgr);
		if (currentPage)
			currentPage.AddMarkers(mapMarkerMgr);
		mapMarkerMgr.CutAllCircles();
		if (chckbx_dragMarkers)
			mapMarkerMgr.SetDragable(chckbx_dragMarkers.IsChecked());
	}
	
	void AddNoBuildZones(RLMapMarkerManager mapMarkerMgr) {
		if (!RL_NoBuildConfig.Get || !RL_NoBuildConfig.Get.enabled || (currentPage && currentPage.IsInherited(RLNoBuildZonesPage)))
			return;
		int color = RL_NoBuildConfig.Get.GetCircleColor();
		foreach (RL_NoBuildEntry entry : RL_NoBuildConfig.Get.zones) {
			mapMarkerMgr.AddCircleNonScaling(Vector(entry.x,0,entry.y), entry.r, color, 55544, true);
		}
	}
	
	void CheckNewMarkers() {
		if (NeedMarkerRefresh())
			AddMapMarker();
	}
	
	bool NeedMarkerRefresh() {
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		bool need = false;
		bool noBuild = (RLMarkerVisibilityManager.Get().showNoBuildZones && RL_NoBuildConfig.Get.enabled);
		if (noBuild != lastNoBuildEnabled) {
			lastNoBuildEnabled = noBuild;
			need = true;
		}
		int groupMarkers = 0;
		if (pb && pb.GetRLGroup()) {
			RLGroup grp = pb.GetRLGroup();
			groupMarkers += grp.markers.Count();
			groupMarkers += grp.pings.Count();
			groupMarkers += grp.members.Count();
		}
		if (groupMarkers != lastGroupMarkerCount) {
			lastGroupMarkerCount = groupMarkers;
			need = true;
		}
		RLStaticMarkerManagerClient mgr = RLStaticMarkerManagerClient.Get();
		int staticHash = 0;
		foreach (RLServerMarker markServ : mgr.staticMarkers) {
			staticHash += markServ.CalcHash();
		}
		RLLogger.Verbose("Server Marker Hash: " + lastServerMarkerHash + " " + staticHash + " Count: " + mgr.staticMarkers.Count(), "AdvancedGroups");
		if (staticHash != lastServerMarkerHash) {
			lastServerMarkerHash = staticHash;
			need = true;
		}
		RLPrivateMarkerManager mgrc = RLPrivateMarkerManager.Get();
		int clientMarkers = mgrc.privateMarkers.Count();
		if (clientMarkers != lastPrivateMarkerCount) {
			lastPrivateMarkerCount = clientMarkers;
			need = true;
		}
		RLGroupMainConfig_ cfg = RLGroupMainConfig.Get;
		if (cfg && cfg.canSeeOwnPlayerOnMap && lastPlayerMarkerCount != 1) {
			lastPlayerMarkerCount = 1;
			need = true;
		} else if (cfg && !cfg.canSeeOwnPlayerOnMap && lastPlayerMarkerCount != 0) {
			lastPlayerMarkerCount = 0;
			need = true;
		}
		return need;
	}
	
	void UpdateMarkerListLater() {
		RLMarkerListPage listPage = RLMarkerListPage.Cast(GetPageByName("#rl_page_markers"));
		if (listPage) {
			GetGame().GetCallQueue(CALL_CATEGORY_GUI).CallLater(listPage.markerListManger.UpdateEntries, 500, false, this, true);
			GetGame().GetCallQueue(CALL_CATEGORY_GUI).CallLater(listPage.markerListManger.UpdateEntries, 1000, false, this, true);
		}
	}
	
	void AddCustomMarkersOnMapOpen() {
	}
	void AddCustomMarkersOnMapOpen(MapWidget mapWidget_, RLMapMarkerManager mapMarkerMgr) {
		mapWidget_.ClearUserMarks();
	}
	
	void AddCustomMarkers() {
	}
	void AddCustomMarkers(MapWidget mapWidget_, RLMapMarkerManager mapMarkerMgr) {
	}
	
	void AddPlayerMarker(RLMapMarkerManager mapMarkerMgr) {
		RLGroupMainConfig_ cfg = RLGroupMainConfig.Get;
		if (!GetGame().GetPlayer() || ! cfg || !cfg.canSeeOwnPlayerOnMap)
			return;
		mapMarkerMgr.AddMarkerObject(GetGame().GetPlayer(), "Me", RLColorManager.Get().GetColor("Own Player Map Marker"), RLMarkerVisibilityManager.Get().GetPlayerMarkerIcon());
	}
	
	void CenterMapOnPlayer() {
		RLGroupMainConfig_ cfg = RLGroupMainConfig.Get;
		if (!GetGame().GetPlayer() || ! cfg || !cfg.canSeeOwnPlayerOnMap)
			return;
		if (!RLMarkerVisibilityManager.Get().centerMapOnPlayer)
			return;
		vector position = GetGame().GetPlayer().GetPosition();
		mapWidget.SetScale(0.2);
		mapWidget.SetMapPos(position);
	}
	
	void AddGroupMarkers(RLMapMarkerManager mapMarkerMgr) {
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup())
			return;
		RLGroup grp = pb.GetRLGroup();
		if (!grp)
			return;
		foreach (RLMarker marker : grp.markers) {
			mapMarkerMgr.AddMarker(marker);
		}
		foreach (RLMarker marker2 : grp.pings) {
			mapMarkerMgr.AddMarker(marker2);
		}
		if (IsOtherPlayersVisible()) {
			foreach (RLGroupMember member : grp.members) {
				mapMarkerMgr.AddMarker(member);
			}
		}
	}
	
	bool AddServerMarkers(RLMapMarkerManager mapMarkerMgr) {
		RLStaticMarkerManagerClient mgr = RLStaticMarkerManagerClient.Get();
		foreach (RLServerMarker marker : mgr.staticMarkers) {
			mapMarkerMgr.AddMarker(marker);
		}
		return true;
	}
	
	bool AddPrivateMarkers(RLMapMarkerManager mapMarkerMgr) {
		RLPrivateMarkerManager mgr = RLPrivateMarkerManager.Get();
		foreach (RLMarker marker : mgr.privateMarkers) {
			mapMarkerMgr.AddMarker(marker);
		}
		return true;
	}
	
	void OnSizeChange() {
		foreach (RLGroupPage page : pages) {
			page.OnSizeChange();
		}
	}
	
	override bool OnDrag(Widget w, int x, int y) {
		if (mapMarkerManager)
			mapMarkerManager.OnDragStart(w);
		RLLogger.Debug("OnDrag: " + w, "AdvancedGroups");
		return true;
	}
	
	override bool OnDragging(Widget w, int x, int y, Widget reciever) {
		RLLogger.Debug("OnDragging: " + w, "AdvancedGroups");
		return true;
	}
	
	override bool OnDraggingOver(Widget w, int x, int y, Widget reciever) {
		RLLogger.Debug("OnDraggingOver: " + w, "AdvancedGroups");
		return true;
	}
	
	override bool OnDrop(Widget w, int x, int y, Widget reciever) {
		RLLogger.Debug("OnDrop: " + w + " at: " + x + "," + y, "AdvancedGroups");
		vector worldpos = mapWidget.ScreenToMap(Vector(x + 10,y + 10,0));
		worldpos[1] = GetGame().SurfaceY(worldpos[0], worldpos[2]);
		if (mapMarkerManager) {
			RLMarker marker = mapMarkerManager.FindMarkerByMainWidget(w);
			if (marker) {
				marker.SetPosition(worldpos);
				marker.SendPositionToServer();
			}
			mapMarkerManager.OnDragStop(w);
			mapMarkerManager.UpdateFrame(true);
		}
		return true;
	}
}