class RLWarningPopup : ScriptedWidgetEventHandler {
	
	static ref RLWarningPopup g_RLWarningPopup;

	private Widget warningMessage;
	private MultilineTextWidget warningText;
	private ButtonWidget cancelWarn, confirmWarn;
	private ref ScriptCaller confirmCaller, cancelCaller;
	
	private void RLWarningPopup() {
	}
	
	void ~RLWarningPopup() {
		if (warningMessage) {
			warningMessage.Unlink();
			delete warningMessage;
		}
	}
	
	static RLWarningPopup Get() {
		if (!g_RLWarningPopup) {
			g_RLWarningPopup = new RLWarningPopup();
			g_RLWarningPopup.Init();
		}
		return g_RLWarningPopup;
	}
	
	static void Delete() {
		if (g_RLWarningPopup)
			delete g_RLWarningPopup;
	}
	
	void Init() {
		warningMessage = RLLayoutManager.Get().CreateLayout("WarningMessage", "", null);
		
		ConnectClassWidgetVariables(this, warningMessage);
		
		warningMessage.SetHandler(this);
		Hide();
	}
	
	void Show(string buttonText, string text, ScriptCaller onConfirm = null, ScriptCaller onCancel = null) {
		if (!warningMessage)
			Init();
		if (!warningMessage) {
			Error("Could not initialize Warning Popup Widget");
			return;
		}
		warningMessage.Show(true);
		warningText.SetText(text);
		confirmWarn.SetText(buttonText);
		confirmWarn.Show(buttonText.Length() > 0);
		confirmCaller = onConfirm;
		cancelCaller = onCancel;
	}
	
	bool IsVisible() {
		return warningMessage && warningMessage.IsVisible();
	}
	
	void Hide() {
		if (warningMessage)
			warningMessage.Show(false);
	}
	
	static void OnFrame() {
		if (g_RLWarningPopup)
			g_RLWarningPopup.OnFrameLocal();
	}
	
	void OnFrameLocal() {
		if (IsVisible() && GetUApi() && GetUApi().GetInputByName("UAUIBack").LocalPress()) {
			GetUApi().GetInputByName("UAUIBack").Supress();
			Hide();
		}
	}
	
	override bool OnClick(Widget w, int x, int y, int button) {
		if (super.OnClick(w, x, y, button)) {
			return true;
		}
		if (w == cancelWarn) {
			if (cancelCaller)
				cancelCaller.Invoke();
			Hide();
			return true;
		} else if (w == confirmWarn) {
			if (confirmCaller)
				confirmCaller.Invoke();
			Hide();
			return true;
		}
		return false;
	}
	
}