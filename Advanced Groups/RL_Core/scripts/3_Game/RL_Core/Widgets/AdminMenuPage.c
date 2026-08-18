class RLAdmin_Menu_Page : ScriptedWidgetEventHandler {
	
	ButtonWidget buttonWidget = null;
	Widget layoutRoot;
	ref RLLinkedVarHandler linked;

	void Init(Widget parent) {
		linked = new RLLinkedVarHandler(this);
		layoutRoot = RLLayoutManager.Get().CreateLayout("AdminMenu_" + GetButtonName(), GetLayoutPath(), parent);
		ConnectClassWidgetVariables(this, layoutRoot, {"buttonWidget"}, null, GetLayoutPath());
		InitWidgets();
		RegisterAllLinkedVars();
	}
	
	void RegisterAllLinkedVars();
	
	void CloseMenu() {
		GetGame().GetUIManager().CloseAll();
	}
	// Optional Overwrites
	void InitWidgets();
	void OnUpdateFrame();
	bool CanShowButton() {return true;}
	void OnShow();
	void OnHide();
	bool DisableMenuBackground();
	void OnRPC(PlayerIdentity sender, Object target, int rpc_type, ParamsReadContext ctx);
	
	// Required Overwrites
	string GetButtonName();
	string GetPageShowPermission();
	string GetLayoutPath();
	
	bool HasShowPermissions() {
		return GetPageShowPermission().Length() == 0 || RLAdmins.Get().HasPermission(GetPageShowPermission());
	}
	void UpdateButtonVisibility() {
		buttonWidget.Show(CanShowButton() && HasShowPermissions());
	}
	
	void ApplyWidgetPermission(string widgetName, string permission) {
		Widget w = layoutRoot.FindAnyWidget(widgetName);
		ApplyWidgetPermission(w , permission);
	}
	
	void ApplyWidgetPermission(Widget w, string permission) {
		if (!w)
			return;
		w.Show(RLAdmins.Get().HasPermission(permission));
	}
	
	override bool OnChange(Widget w, int x, int y, bool finished) {
		if (super.OnChange(w, x, y, finished))
			return true;
		return linked.OnVarChange(w);
	}
	
	override bool OnClick(Widget w, int x, int y, int button) {
		if (super.OnClick(w, x, y, button))
			return true;
		
		return linked.OnVarChange(w);
	}
	
	override bool OnKeyPress(Widget w, int x, int y, int key) {
		return super.OnKeyPress(w, x, y, key);
	}
	
	override bool OnKeyDown(Widget w, int x, int y, int key) {
		bool ok = super.OnKeyDown(w, x, y, key);
		return linked.OnKeyDown(w, x, y, key) || ok;
	}
	
	void KeyDown(int key) {
		linked.OnVarChange(GetFocus());
	}
	
	override bool OnItemSelected(Widget w, int x, int y, int row, int column, int oldRow, int oldColumn) {
		if (super.OnItemSelected(w, x, y, row, column, oldRow, oldColumn))
			return true;
		
		return linked.OnVarChange(w);
	}
	
}
