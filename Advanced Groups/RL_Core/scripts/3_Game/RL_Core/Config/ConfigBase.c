class RLConfigBase : Managed {
	
	int GetCurrentVersion();
	void UpdateVersion();
	void LoadDefault();
	void JsonLoadVar(string path, bool forceValid, out bool overwriteTest) {overwriteTest = true;};
	void JsonSaveVar(string path, out bool overwriteTest) {overwriteTest = true;};
	bool OnLoad() {return false;} // Return if the config should be saved after invoking the method
	bool OnLoadMission() {return false;} // Return if the config should be saved after invoking the method
	void OnReceivedFromRPC(PlayerIdentity sender);
	
	void WriteExtraCtx(ParamsWriteContext ctx) {};
	bool ReadExtraCtx(ParamsReadContext ctx) {return true;}
	
	int version = GetCurrentVersion();
}