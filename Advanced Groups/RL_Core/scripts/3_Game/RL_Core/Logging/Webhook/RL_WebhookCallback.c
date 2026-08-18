class RL_WebhookCallback : RestCallback{

	string type, message, webhookName;
	
	void RL_WebhookCallback(string messageType, string sentMessage, string name) {
		this.type = messageType;
		this.message = sentMessage;
		this.webhookName = name;
	}
	
	override void OnError( int errorCode ) {
		if (errorCode == ERestResultState.EREST_ERROR_APPERROR) {
			return; // Everything fine
		}
		RLLogger.Error("Error while sending the Discord Webhook + " + webhookName + " ! Response Code: " + errorCode + " MessageType: " + type + " JSON: " + message, "RLWebhook");
	}
	
	override void OnTimeout() {
		RLLogger.Error("Error while sending the Discord Webhook " + webhookName + " ! Timeout. Message: " + type, "RLWebhook");
	}
	
	override void OnSuccess( string data, int dataSize ) {
		RLLogger.Debug("Webhook " + webhookName + " was successfully sent. Message: " + type, "RLWebhook");
	}
	
	override void OnFileCreated( string fileName, int dataSize ) {
		RLLogger.Fatal("A file should not have been created Webhook: " + webhookName + " ! Filename: " + fileName + " Message: " + type, "RLWebhook");
	}
	
}