class RL_Webhook_Message {

	string message;
	[NonSerialized()]
	bool valid = true;
	
	RL_Webhook_Message Init(string webhookMessage) {
		this.message = webhookMessage;
		return this;
	}
	
	string GetFinalMessage(TStringArray arguments) {
		int count = arguments.Count();
		string finalMessage = "" + message;
		RL_Webhook_Escape tmp = new RL_Webhook_Escape();
		JsonSerializer serializer = new JsonSerializer();
		string escaped;
		for (int i = 0; i < count; i++) {
			string x = arguments.Get(i) + "";
			int replaceCount = x.Replace("\\", "\\" + "\\");
			tmp.x = x;
			serializer.WriteToString(tmp, false, escaped);
			escaped = escaped.Substring(6, escaped.Length() - 8);
			finalMessage.Replace("$$" + (i + 1) + "$$", escaped);
		}
		return finalMessage;
	}
	
	bool IsValid() {
		return valid;
	}
	
	bool ValidateRLWebhookMessage(JsonSerializer serializer, string name) {
		RL_Webhook_Empty empty;
		string error = "None";
		string messageCopy = message + "";
		for (int i = 1; i < 15; i++) {
			messageCopy.Replace("$$" + i + "$$", "1");
		}
		if (!serializer.ReadFromString(empty, messageCopy, error)) {
			error.Replace("\n", "");
			error.Replace("\r", "");
			RLLogger.Fatal("Invalid Webhook Json for " + name + ". Error: " + error, "RLWebhook");
			valid = false;
			return false;
		}
		RLLogger.Debug("Webhook " + name + " is valid", "RLWebhook");
		valid = true;
		return true;
	}
	
}