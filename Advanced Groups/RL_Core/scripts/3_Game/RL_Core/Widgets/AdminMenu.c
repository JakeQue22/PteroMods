
class RLAdmin_Menu : UIScriptedMenu {
	
	static string lastOpenedPage = "";
	
	Widget pageWidgets;
	Widget buttonWidgets;
	ButtonWidget btn_close_admin;
	RLAdmin_Menu_Page activePage;
	ref array<ref RLAdmin_Menu_Page> pages = new array<ref RLAdmin_Menu_Page>();

	override Widget Init() {
		Print("Init Admin Widget");
		layoutRoot = RLLayoutManager.Get().CreateLayout("AdminMenu");
		ConnectClassWidgetVariables(this, layoutRoot);
		
		return layoutRoot;
	}
	
	void RegisterPage(typename pageType) {
		foreach (RLAdmin_Menu_Page page : pages) {
			if (page.ClassName() == pageType.ToString())
				return;
		}
		
		page = RLAdmin_Menu_Page.Cast(pageType.Spawn());
		if (!page) {
			RLLogger.Fatal("Could not create Admin Menu Page: " + pageType, "AdminConfig");
			return;
		}
		pages.Insert(page);
		page.Init(pageWidgets);
		ButtonWidget btn = ButtonWidget.Cast(RLLayoutManager.Get().CreateLayout("AdminMenuButton", "", buttonWidgets));
		btn.SetText(page.GetButtonName());
		page.buttonWidget = btn;
		page.layoutRoot.Show(false);
		UpdateAllButtonVisibilities();
	}
	
	RLAdmin_Menu_Page GetPage(typename page) {
		foreach (RLAdmin_Menu_Page _page : pages) {
			if (_page.IsInherited(page))
				return _page;
		}
		return null;
	}
	
	override void Update(float timeslice) {
		super.Update(timeslice);
		if (GetUApi() && GetUApi().GetInputByName("UAUIBack").LocalPress()) {
			CloseMe();
		}
		if (activePage)
			activePage.OnUpdateFrame();
	}
	
	override void OnShow() {
		super.OnShow();
		PPEffects.SetBlurMenu(0.7);
		GetGame().GetMission().GetHud().ShowHudUI(false);
		GetGame().GetMission().GetHud().ShowQuickbarUI(false);
		Mission mission = GetGame().GetMission();
		if (mission)
			mission.PlayerControlDisable( INPUT_EXCLUDE_ALL );
		UpdateAllButtonVisibilities();
		SetPageActive("");
	}
	
	override void OnHide() {
		super.OnHide();
		PPEffects.SetBlurMenu(0);
		if (GetGame() && GetGame().GetMission() && GetGame().GetMission().GetHud()) {
			GetGame().GetMission().GetHud().ShowHudUI(true);
			GetGame().GetMission().GetHud().ShowQuickbarUI(true);
			Mission mission = GetGame().GetMission();
			if (mission)
				mission.PlayerControlEnable(false);
		}
		if (activePage) {
			activePage.OnHide();
			activePage.layoutRoot.Show(false);
		}
	}
	
	RLAdmin_Menu_Page SetPageActive(string buttonName) {
		if (buttonName == "") {
			buttonName = lastOpenedPage;
			RLLogger.Debug("Setting Active Page to last Opened Page: " + lastOpenedPage, "Core");
		}
		foreach (RLAdmin_Menu_Page page : pages) {
			if ((page.GetButtonName() == buttonName || activePage == null && buttonName == "") && SetPageActive(page))
				return page;
		}
		return null;
	}
	
	RLAdmin_Menu_Page SetPageActive(typename page) {
		return SetPageActive(GetPage(page));
	}
	
	RLAdmin_Menu_Page SetPageActive(RLAdmin_Menu_Page page) {
		if (page == null)
			return page;
		if (!page.HasShowPermissions())
			return null;
		if (activePage) {
			activePage.OnHide();
			activePage.layoutRoot.Show(false);
			activePage.buttonWidget.FindAnyWidget("btnColor").SetColor(ARGB(255,255,255,255));
		}
		activePage = page;
		if (activePage) {
			activePage.layoutRoot.Show(true);
			activePage.OnShow();
			if (activePage.DisableMenuBackground()) {
				PPEffects.SetBlurMenu(0);
				layoutRoot.SetColor(ARGB(0,0,0,0));
			} else {
				PPEffects.SetBlurMenu(0.7);
				layoutRoot.SetColor(ARGB(100,129,129,129));
			}
			if (activePage.CanShowButton())
				lastOpenedPage = activePage.GetButtonName();
			RLLogger.Debug("Currently Opened Page: " + activePage.GetButtonName() + " Last Opened now: " + lastOpenedPage, "Core");
			activePage.buttonWidget.FindAnyWidget("btnColor").SetColor(ARGB(255,0,255,0));
		}
		UpdateAllButtonVisibilities();
		return activePage;
	}
	
	void UpdateAllButtonVisibilities() {
		foreach (RLAdmin_Menu_Page page : pages) {
			page.UpdateButtonVisibility();
		}
	}
	
	void CloseMe() {
		GetGame().GetUIManager().HideScriptedMenu(this);
		delete this;
	}
	
	void OnRPC(PlayerIdentity sender, Object target, int rpc_type, ParamsReadContext ctx) {
		if (activePage)
			activePage.OnRPC(sender, target, rpc_type, ctx);
	}
	
	int mapDownTime, mapDownX, mapDownY;
	
	override bool OnMouseButtonDown(Widget w, int x, int y, int button) {
		super.OnMouseButtonDown(w, x, y, button);
		if (w.IsInherited(MapWidget)) {
			Print("Map Widget was clicked");
			mapDownTime = GetGame().GetTime();
			mapDownX = x;
			mapDownY = y;
		}
		return false;
	}
	
	override bool OnMouseButtonUp(Widget w, int x, int y, int button) {
		super.OnMouseButtonUp(w, x, y, button);
		if (w.IsInherited(MapWidget)) {
			if (GetGame().GetTime() - mapDownTime < 300) {
				int dist = (x - mapDownX) * (x - mapDownX) + (y - mapDownY) * (y - mapDownY);
				Print("Distance between button down and up: " + dist);
				if (dist < 25) {
					mapDownTime = 0;
					return OnClick(w, x, y, button);
				}
			}
		}
		return false;
	}
	
	override bool OnClick(Widget w, int x, int y, int button) {
		RLLogger.Debug("On Click: " + w + " at " + x + " " + y + " button: " + button, "Core");
		if (w == btn_close_admin) {
			CloseMe();
		}
		foreach (RLAdmin_Menu_Page page : pages) {
			if (w == page.buttonWidget) {
				SetPageActive(page);
				return true;
			}
		}
		if (activePage && activePage.OnClick(w, x, y, button))
			return true;
		return false;
	}
	
	override bool OnDropReceived(Widget w, int x, int y, Widget reciever) {
		if (super.OnDropReceived(w, x, y, reciever))
			return true;
		return activePage && activePage.OnDropReceived(w, x, y, reciever);
	}
	
	override bool OnChange(Widget w, int x, int y, bool finished) {
		if (super.OnChange(w, x, y, finished))
			return true;
		return activePage && activePage.OnChange(w, x, y, finished);
	}
	
	override bool OnItemSelected(Widget w, int x, int y, int row, int  column,	int  oldRow, int  oldColumn) {
		if (super.OnItemSelected(w, x, y, row, column, oldRow, oldColumn))
			return true;
		return activePage && activePage.OnItemSelected(w, x, y, row, column, oldRow, oldColumn);
	}
	
	override bool OnMouseLeave(Widget w, Widget enterW, int x, int y) {
		if (super.OnMouseLeave(w, enterW, x, y))
			return true;
		return activePage && activePage.OnMouseLeave(w, enterW, x, y);
	}
	
	override bool OnMouseEnter(Widget w, int x, int y) {
		if (super.OnMouseEnter(w, x, y))
			return true;
		return activePage && activePage.OnMouseEnter(w, x, y);
	}
	
	override bool OnMouseWheel(Widget w, int x, int y, int wheel) {
		if (super.OnMouseWheel(w, x, y, wheel))
			return true;
		return activePage && activePage.OnMouseWheel(w, x, y, wheel);
	}
	
	override bool OnFocus(Widget w, int x, int y) {
		if (super.OnFocus(w, x, y))
			return true;
		return activePage && activePage.OnFocus(w, x, y);
	}
	
	override bool OnFocusLost(Widget w, int x, int y) {
		if (super.OnFocusLost(w, x, y))
			return true;
		return activePage && activePage.OnFocusLost(w, x, y);
	}
	
	void KeyDown(int key) {
		if (activePage)
			activePage.KeyDown(key);
	}
	
	override bool OnKeyPress(Widget w, int x, int y, int key) {
		Print("On Key Press: " +w + " Key: " + key + " Name: " + EnumTools.EnumToString(KeyCode, key));
		if (super.OnKeyPress(w, x, y, key))
			return true;
		return activePage && activePage.OnKeyPress(w, x, y, key);
	}
	
	override bool OnKeyDown(Widget w, int x, int y, int key) {
		Print("On Key Down: " +w + " Key: " + key + " Name: " + EnumTools.EnumToString(KeyCode, key));
		bool ok = super.OnKeyDown(w, x, y, key);
		return (activePage && activePage.OnKeyDown(w, x, y, key)) || ok;
	}
	
}