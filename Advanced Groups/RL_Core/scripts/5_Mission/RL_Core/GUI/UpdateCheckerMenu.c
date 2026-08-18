class RLUpdateCheckerMenu : RLAdmin_Menu_Page {

	ref TStringArray messagesList = new TStringArray();
	
	RichTextWidget messages;
	
	override void OnShow() {
		super.OnShow();
		RLLogger.Info("Sending Update Check Request to server...", "Core");
		GetGame().RPCSingleParam(null, RayLab_Core_RPCs.UPDATE_CHECK_INFO, new Param1<bool>(true), true);
	}
	
	override void OnRPC(PlayerIdentity sender, Object target, int rpc_type, ParamsReadContext ctx) {
		if (rpc_type == RayLab_Core_RPCs.UPDATE_CHECK_INFO) {
			ctx.Read(messagesList);
			Print("Received update messages: " + messagesList);
			LoadMessages();
		}
	}
	
	void LoadMessages() {
		string all = "";
		foreach (string message : messagesList)
			all = all + message + "\n";
		messages.SetText(all);
	}
	
	override string GetButtonName() {
		return "Updates";
	}
	
	override string GetPageShowPermission() {
		return "";
	}
}