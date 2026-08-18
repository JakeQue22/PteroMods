int rl_logger_init = RLLogger.Init();
class RLLogger {
	
	private static const int LOG_FATAL = 1;
	private static const int LOG_ERROR = 2;
	private static const int LOG_ADMIN = 3;
	private static const int LOG_INFO = 4;
	private static const int LOG_DEBUG = 5;
	private static const int LOG_VERBOSE = 6;

	private static const string LOG_CONFIG_PATH = "$profile:RayLab/Config/Common/Logger.json";
	
	private static FileHandle fileHandle;
	private static bool logFullTimestamp;
	private static int currentLogLevel;
	
	private static bool doLog;
	private static bool logToScriptlog;
	
	static int Init() {
		SetupLogger();
		return 1;
	}
	
	private static RLLoggerConfig LoadConfig() {
		RayLabConfigMover.CreateParentFolders(LOG_CONFIG_PATH);
		RLLoggerConfig cfg;
		if (!FileExist(LOG_CONFIG_PATH)) {
			cfg = new RLLoggerConfig();
			JsonFileLoader<RLLoggerConfig>.JsonSaveFile(LOG_CONFIG_PATH, cfg);
		} else {
			JsonFileLoader<RLLoggerConfig>.JsonLoadFile(LOG_CONFIG_PATH, cfg);
		}
		if (cfg.UpdateVersion()) {
			JsonFileLoader<RLLoggerConfig>.JsonSaveFile(LOG_CONFIG_PATH, cfg);
		}
			
		return cfg;
	}
	
	private static void SetupLogger() {
		RLLoggerConfig cfg = LoadConfig();
		currentLogLevel = cfg.logLevel;
		logFullTimestamp = cfg.logFullTimestamp;
		logToScriptlog = cfg.logToScriptlog;
		
		string timestamp = RLDate.Init(false).ToFormattedString();
		string fileTimestamp = timestamp + "";
		fileTimestamp.Replace(".", "_");
		fileTimestamp.Replace(":", "_");
		fileTimestamp.Replace(" ", "_");
		string filePath = cfg.logFolder + "RayLab_" + fileTimestamp + ".log";
		RayLabConfigMover.CreateParentFolders(filePath);
		fileHandle = OpenFile(filePath, FileMode.WRITE);
		if (fileHandle == 0) {
			Print("Could not Start RLLogger at: " + filePath);
		} else {
			Print("Started RLLogger at: " + filePath);
			FPrintln(fileHandle, "Logger Started at: " + timestamp);
			doLog = true; // Only Set DoLog to 1 after the File Handle was created successfully
		}
	}
	
	private static string GetTimestampString() {
		RLDate date = RLDate.Init(false);
		if (logFullTimestamp) {
			return date.ToFormattedString();
		} else {
			return date.ToFormattedStringShort();
		}
	}
	
	static string GetCallingMethodFromStack(int index = 0) {
		string stack;
		DumpStackString(stack);
		TStringArray lines = new TStringArray();
		stack.Split("\n", lines);
		if (lines.Count() > index + 2)
			return lines.Get(index + 2).Trim();
		return "Unknown Source";
	}
	
	static string GetCallingMethodNameFromStack(int index = 0) {
		string line = GetCallingMethodFromStack(index + 1);
		index = line.IndexOf("(");
		if (index == INDEX_NOT_FOUND)
			return line;
		return line.Substring(0, index);
	}
	
	static bool Verbose() {
		return IsLogged(LOG_VERBOSE);
	}
	
	static bool Debug() {
		return IsLogged(LOG_DEBUG);
	}
	
	static bool Info() {
		return IsLogged(LOG_INFO);
	}
	
	static bool Admin() {
		return IsLogged(LOG_ADMIN);
	}
	
	static bool Error() {
		return IsLogged(LOG_ERROR);
	}
	
	static bool Fatal() {
		return IsLogged(LOG_FATAL);
	}
	
	
	static void Verbose(string message, string mod) {
		Log(message, LOG_VERBOSE, mod);
	}
	
	static void Debug(string message, string mod) {
		Log(message, LOG_DEBUG, mod);
	}
	
	static void Info(string message, string mod) {
		Log(message, LOG_INFO, mod);
	}
	
	static void Admin(string message, string mod) {
		Log(message, LOG_ADMIN, mod);
	}
	
	static void Error(string message, string mod) {
		Log(message, LOG_ERROR, mod);
	}
	
	static void Fatal(string message, string mod) {
		Log(message, LOG_FATAL, mod);
	}
	
	private static bool IsLogged(int logLevel) {
		return logLevel <= currentLogLevel;
	}
	
	// Backup Log to the scriptlog for Clients and if the Server could not create a new FileHandle for the Log
	private static bool LogMessageBackup(string message, int logLevel, string mod) {
		if (!doLog && IsLogged(logLevel)) {
			Print("[" + mod + "] " + message);
			return true;
		}
		return false;
	}
	
	static string FormatPlayerIdentity(PlayerIdentity identity) {
		if (identity == null)
			return "NULL";
		return identity.GetName() + " (" + identity.GetPlainId() + ")";
	}
	
	static string FormatPlayerIdentity(Man player) {
		if (player == null)
			return "NULL";
		return FormatPlayerIdentity(player.GetIdentity());
	}
	
	private static void Log(string message, int logLevel, string mod) {
		if (LogMessageBackup(message, logLevel, mod)) {
			return;
		}
		if (!IsLogged(logLevel))
			return;
		string logLevelStr;
		if (logLevel == LOG_FATAL) {
			logLevelStr = "FATAL";
		} else if (logLevel == LOG_ERROR) {
			logLevelStr = "ERROR";
		} else if (logLevel == LOG_ADMIN) {
			logLevelStr = "ADMIN";
		} else if (logLevel == LOG_INFO) {
			logLevelStr = "INFO ";
		} else if (logLevel == LOG_DEBUG) {
			logLevelStr = "DEBUG";
		} else if (logLevel == LOG_VERBOSE) {
			logLevelStr = "VERB ";
		} else {
			logLevelStr = "Unknown";
		}
		string finalMessage = GetTimestampString() + " [" + logLevelStr + "] [" + mod + "] " + message;
		FPrintln(fileHandle, finalMessage);
		if (logToScriptlog) {
			Print("" + finalMessage);
		}
	}
	
}