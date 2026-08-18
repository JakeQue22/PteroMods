class RLVersionUpdateCheckCallbackBase : RestCallback {
	static bool received = false;
	static bool error = false;
	static string errorMessage = "";
	static ref TStringArray oldVersions = new TStringArray();
	
	override void OnError( int errorCode ) {
		error = true;
		errorMessage = "Receive Error";
	}
	override void OnTimeout() {
		error = true;
		errorMessage = "Timeout";
	}
	override void OnSuccess( string data, int dataSize ) {
		received = true;
		JsonSerializer serial = new JsonSerializer();
   		string jsonError;
   		if (!serial.ReadFromString(oldVersions, data, jsonError)) {
   			error = true;
			errorMessage = "Json Error";
   			return;
   		}
	}
	override void OnFileCreated( string fileName, int dataSize ) {}
}