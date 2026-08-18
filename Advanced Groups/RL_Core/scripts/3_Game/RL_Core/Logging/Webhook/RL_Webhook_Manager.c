class RL_Webhook_Manager : RLConfigLoader<RL_Webhook_Manager_> {
	
	override void InitVars() {
		InitVarsInternal("Common", "Webhooks.json", RLConfigType.CONFIG, false);
	}
	
}
class RL_Webhook_Manager_ : RLConfigBase {

	override int GetCurrentVersion() {
		return 2;
	}
	
	ref array<ref RL_Webhook> webhooks = new array<ref RL_Webhook>(); // List of all Webhooks
	ref map<string, ref RL_Webhook_Message> webhookMessages = new map<string, ref RL_Webhook_Message>(); // List of the raw Webhook messages with placeholders represented as `$$x$$` where x is a number. See below for information how to setup the message correctly.\nA complete list of all webhooks can be found [Here](/webhooblist.txt)
	[NonSerialized()]
	bool needSave = false;
	
	
	override bool OnLoad() {
		ValidateWebhookMessages();
		return false;
	}
	
	override void LoadDefault() {
		RL_Webhook example = new RL_Webhook();
		example.webhookURL = "https://discord.com/api/webhooks/....";
		example.enabled = false;
		example.description = "Test Webhook for demonstration";
		example.webhookMessages.Insert("GroupCreate");
		example.webhookMessages.Insert("GroupDelete");
		example.webhookMessages.Insert("GroupChat");
		example.webhookMessages.Insert("GlobalChat");
		example.webhookMessages.Insert("DirectChat");
		example.webhookMessages.Insert("ATMRobbingSuccess");
		webhooks.Insert(example);
		
		example = new RL_Webhook();
		example.webhookURL = "https://discord.com/api/webhooks/....";
		example.enabled = false;
		example.description = "Admin Webhook for demonstration";
		example.webhookMessages.Insert("GroupCreate");
		example.webhookMessages.Insert("GroupDelete");
		example.webhookMessages.Insert("GroupChat");
		example.webhookMessages.Insert("GlobalChat");
		example.webhookMessages.Insert("DirectChat");
		example.webhookMessages.Insert("ATMRobbingSuccess");
		example.overwrittenMessages.Insert("ATMRobbingSuccess", (new RL_Webhook_Message()).Init(""));
		webhooks.Insert(example);
	}
	
	override void UpdateVersion() {
		if (version < 2) {
			DeleteWebhook("GlobalChat");
			DeleteWebhook("GroupChat");
			DeleteWebhook("GroupDelete");
			DeleteWebhook("GroupCreate");
			DeleteWebhook("DirectChat");
		}
	}
	
	void AfterRegister() {
		if (needSave)
			RL_Webhook_Manager.Loader.Save();
	}
	
	void ValidateWebhookMessages() {
		bool ok = true;
		JsonSerializer serializer = new JsonSerializer();
		foreach (string name, RL_Webhook_Message message : webhookMessages) {
			ok = message.ValidateRLWebhookMessage(serializer, name) && ok;
		}
		foreach (RL_Webhook webhook : webhooks) {
			ok = webhook.ValidateWebhookMessages(serializer) && ok;
		}
		RLLogger.Info("All Webhooks Valid ? " + ok, "RLWebhook");
	}
	
	void SendMessage(string type, TStringArray arguments) {
		RLLogger.Verbose("Sending Webhook " + type + "...", "RLWebhook");
		RL_Webhook_Message found;
		if (webhookMessages.Find(type, found)) {
			if (!found.IsValid())
				return;
			string sendMessage = found.GetFinalMessage(arguments);
			if (GetRestApi() == null)
				CreateRestApi();
			foreach (RL_Webhook webhook : webhooks) {
				webhook.SendMessage(type, sendMessage, arguments);
			}
		} else {
			RLLogger.Error("Could not find Webhook Message: " + type, "RLWebhook");
		}
	}
	
	void RegisterWebhook(string name, RL_Webhook_Message defaultMessage) {
		if (!webhookMessages.Contains(name)) {
			webhookMessages.Insert(name, defaultMessage);
			needSave = true;
		}
	}
	
	void RegisterWebhook(string name, string message) {
		RegisterWebhook(name, (new RL_Webhook_Message()).Init(message));
	}
	
	private void DeleteWebhook(string name) {
		webhookMessages.Remove(name);
	}
	
}