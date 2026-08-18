/*
class RLLoggerConfig : RLConfigLoader<RLLoggerConfig_> {
	override void InitVars() {
		InitVarsInternal("Common", "Logger.json", RLConfigType.CONFIG, true, "logger.change"); // (easy)
	}
}
*/

class RLLoggerConfig /*: RLConfigBase*/ {

	const static int CURRENT_VERSION = 1;
	int version = CURRENT_VERSION; // Version for internal Version tracking (Current Version: 1)
	int logLevel = 4; // Defines how much information will be logged (0 to 6) (none to everything). (`0 = None`, `1 = Fatal`, `2 = Error`, `3 = Admin`, `4 = Info` (default), `5 = Debug`, `6 = Verbose`)
	bool logFullTimestamp = false; // 0 to only log the time of the Day in the log. 1 to log the full timestamp including the date in the log
	string logFolder = "$profile:"; // Folder where the RayLab log will be saved to (default is the profiles folder to allow easy log rotation for the server managers)
	bool logToScriptlog = false; // Toggle if the log should be written to the scriptlog too. This does not disable the logging to the RayLab Log
	
	bool UpdateVersion() {
		if (version == CURRENT_VERSION)
			return false;
		if (version < 1) {
			logToScriptlog = false;
		}
		version = CURRENT_VERSION;
		return true;
	}
	
}