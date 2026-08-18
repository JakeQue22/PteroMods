class RL_Webhook {

	bool enabled = false; // Enable / Disable that messages should be sent to the webhook
	string description; // Description to keep track of the Webhooks (only visible from the config)
	string webhookURL = ""; // URL of the Webhook when copying it from the Discord Channel (https://discord.com/api/webhooks/...)
	ref TStringArray webhookMessages = new TStringArray(); // List of Messages, which should be sent to this webhook. Use the names from below to send only specific messages to have the option for multiple webhooks with different purpose like chat logging and admin logging etc.
	ref map<string, ref RL_Webhook_Message> overwrittenMessages = new map<string, ref RL_Webhook_Message>(); // overwrite any message for example to not include any information, which should not be visible for public channels. An Entry will have the same structure like one entry in the `webhookMessages` list
	
	void SendMessage(string type, string message, TStringArray arguments) {
		if (!enabled) {
			RLLogger.Verbose("Disabled Webhook: " + description, "RLWebhook");
			return;
		}
		if (webhookMessages.Find(type) == -1) {
			RLLogger.Verbose("Message " + type + " not registered for Webhook: " + description, "RLWebhook");
			return;
		}
		RL_Webhook_Message overwrittenMessage;
		if (overwrittenMessages && overwrittenMessages.Find(type, overwrittenMessage) && overwrittenMessage && overwrittenMessage.IsValid()) {
			message = overwrittenMessage.GetFinalMessage(arguments);
		}
		RestApi api = GetRestApi();
		RestContext ctx = api.GetRestContext(webhookURL);
		ctx.SetHeader("application/json");
		RLLogger.Verbose("Sending Webhook message: " + message, "RLWebhook");
		ctx.POST(new RL_WebhookCallback(type, message, description), "", message);
	}
	
	bool ValidateWebhookMessages(JsonSerializer serializer) {
		bool ok = true;
		foreach (string name, RL_Webhook_Message message : overwrittenMessages) {
			ok = message.ValidateRLWebhookMessage(serializer, name + "#" + description) && ok;
		}
		return ok;
	}

}
