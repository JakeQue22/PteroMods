class RLGroupPage {
	
	static ref map<int, ButtonWidget> topButtons = new map<int, ButtonWidget>();
	
	ref RLGroupUI parent;
	string buttonname;
	bool fullsized = false;
	int pageID, pageSubID;
	bool over_map = false;
	int last_map_x = -1, last_map_y = -1;
	
	ButtonWidget buttonWidget;
	ref Widget rootWidget;
	
	void ~RLGroupPage() {
		if (rootWidget) {
			rootWidget.Unlink();
		}
		if (buttonWidget) {
			buttonWidget.Unlink();
		}
	}
	
	bool InitPage(RLGroupUI parentUI) {
		return false;
	}
	
	void StoreAllWidgetData(RLDataSerializer data) {
		topButtons.Clear();
	}
	
	void RestoreAllWidgetData(RLDataSerializer data) {
		
	}
	
	bool CanDisplayButton() {
		return true;
	}
	
	bool InitPage(RLGroupUI parentUI, int pageID_, int pageSubID_, string name, bool fullsize) {
		parent = parentUI;
		buttonname = name;
		fullsized = fullsize;
		pageID = pageID_;
		pageSubID = pageSubID_;
		return InitWidgets();
	}
	
	string GetLayoutPath() {
		return RLLayoutManager.Get().GetLayoutPath("Map Page " + pageID + " " + pageSubID);
	}
	
	bool InitWidgets() {
		string layoutPath = GetLayoutPath();
		Widget parentWidget;
		if (fullsized) {
			parentWidget = parent.fullPanel;
		} else {
			parentWidget = parent.leftPanel;
		}
		RLLogger.Debug("Creating Layout: " + layoutPath, "AdvancedGroups");
		rootWidget = GetGame().GetWorkspace().CreateWidgets(layoutPath, parentWidget);
		RLLogger.Debug("Widget: " + rootWidget, "AdvancedGroups");
		rootWidget.Show(false);
		InitMainWidget();
		
		if (!topButtons.Contains(pageID)) {
			layoutPath = RLLayoutManager.Get().GetLayoutPathWithDefault("Map Top Button", "RayLab_Groups/gui/layouts/mapmenu/topButton_default.layout");
			RLLogger.Debug("Creating new Top Button for PageID: " + pageID + " with Layout " + layoutPath, "AdvancedGroups");
			Widget btnWid = GetGame().GetWorkspace().CreateWidgets(layoutPath, parent.topPanel);
			buttonWidget = ButtonWidget.Cast(btnWid);
			if (buttonWidget) {
				buttonWidget.SetText(buttonname);
				topButtons.Insert(pageID, buttonWidget);
				float width = (1.0 - (RLGroupUI.TOP_BUTTON_COUNT - 1) * RLGroupUI.TOP_BUTTON_GAP_PERCENT) / RLGroupUI.TOP_BUTTON_COUNT;
				float height = RLGroupUI.TOP_BUTTON_HEIGHT_PX;
				float posX = (width + RLGroupUI.TOP_BUTTON_GAP_PERCENT) * pageID;
				float posY = 0;
				RLLogger.Debug("Top Button Width: " + width + " Height: " + height + " posX: " + posX + " posY: " + posY + " PageID: " + pageID, "AdvancedGroups");
				buttonWidget.SetSize(width, height);
				buttonWidget.SetPos(posX, posY);
			}
		} else {
			RLLogger.Debug("Getting Top Button for PageID: " + pageID + " with Layout " + layoutPath, "AdvancedGroups");
			buttonWidget = topButtons.Get(pageID);
		}
		UpdateButtonDisplay();
		return true;
	}
	
	void UpdateButtonDisplay() {
		if (buttonWidget) {
			buttonWidget.Show(CanDisplayButton());
		}
	}
	
	bool OnTopButtonClicked(Widget w) {
		return (w == buttonWidget) && CanDisplayButton();
	}
	
	bool OnClick(Widget w) {
		return false;
	}
	
	bool OnChange(Widget w) {
		return false;
	}
	
	bool OnItemSelected(Widget w, int row, int column) {
		return false;
	}
	
	bool OnDoubleClick(Widget w) {
		return false;
	}
	
	bool OnMouseEnter(Widget w, int x, int y) {
		if (w == parent.mapWidget) {
			over_map = true;
		}
		return false;
	}
	
	bool OnMouseLeave(Widget w, int x, int y) {
		if (w == parent.mapWidget) {
			over_map = false;
		}
		return false;
	}
	
	void OnGroupChanged() {
		
	}
	
	void InitMainWidget() {
		
	}
	
	void OnShow() {
		if (rootWidget)
			rootWidget.Show(true);
		parent.leftPanel.Show(!fullsized);
		bool mapVisible = parent.IsMapVisible();
		parent.mapWidget.Show(!fullsized && mapVisible);
		parent.mapNotFound.Show(!fullsized && !mapVisible);
		parent.mapNotFoundImage.LoadImageFile(0, RLGroupMainConfig.Get.mapNotFoundImage);
		parent.mapNotFoundText.SetText(RLGroupMainConfig.Get.mapNotFoundText);
		parent.fullPanel.Show(fullsized);
	}
	
	void OnHide() {
		if (rootWidget)
			rootWidget.Show(false);
	}
	
	void OnUpdateFrame() {
		if (over_map && GetMouseState(MouseState.LEFT) < 0 && last_map_x == -1 && last_map_y == -1) {
			GetMousePos(last_map_x, last_map_y);
		} else if (over_map && GetMouseState(MouseState.LEFT) >= 0) {
			int x, y;
			GetMousePos(x,y);
			if (x == last_map_x && y == last_map_y) {
				OnClick(parent.mapWidget);
			}
			last_map_y = -1;
			last_map_x = -1;
		}
	}
	
	void AddMarkers(RLMapMarkerManager mapMarkerManager) {}
	void OnUpdateSlow() {}
	void OnMarkerChanged() {}
	void OnSizeChange() {}
}
