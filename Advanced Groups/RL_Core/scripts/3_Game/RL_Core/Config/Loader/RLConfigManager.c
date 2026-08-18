class RLConfigManager {
	
	private static ref RLConfigManager g_RLConfigManager;
	private static ref map<RLConfigPriority, ref set<typename>> loaders = new map<RLConfigPriority, ref set<typename>>();
	private static ref map<typename, RLConfigPriority> priorities = new map<typename, RLConfigPriority>();
	private ref ScriptInvoker Event_OnConfigReceived = new ScriptInvoker();
	private ref map<string, ref ScriptInvoker> onReceiveInvokers = new map<string, ref ScriptInvoker>();
	private static ref set<typename> preloaded = new set<typename>();
	private static bool loadedAllConfigs = false, missionInitialized = false;
	
	static RLConfigManager Get() {
		if (!g_RLConfigManager)
			g_RLConfigManager = new RLConfigManager();
		return g_RLConfigManager;
	}
	
	static void Delete() {
		if (g_RLConfigManager)
			delete g_RLConfigManager;
		loadedAllConfigs = false;
		missionInitialized = false;
	}

	private ref map<string, RLConfigLoaderBase> types = new map<string, RLConfigLoaderBase>();
	
	void ~RLConfigManager() {
		if (!types)
			return;
		foreach (string name_, RLConfigLoaderBase type : types) {
			if (type)
				delete type;
		}
		types.Clear();
	}
	
	ScriptInvoker GetEventOnConfigReceived() {
		return Event_OnConfigReceived;
	}
	
	ScriptInvoker GetEventOnConfigReceived(typename configName) {
		ScriptInvoker other;
		if (onReceiveInvokers.Find(configName.ToString(), other))
			return other;
		other = new ScriptInvoker();
		onReceiveInvokers.Insert(configName.ToString(), other);
		return other;
	}
	
	void OnConfigReceived(typename name) {
		string configName = GetLoaderTypename(name).ToString();
		Event_OnConfigReceived.Invoke(configName);
		ScriptInvoker other;
		if (onReceiveInvokers.Find(configName, other))
			other.Invoke();
	}
	
	void LoadImmediate(string configName) {
		if (loadedAllConfigs)
			return;
		typename loader = GetLoaderTypename(configName);
		if (preloaded.Find(loader) != -1 || !priorities.Contains(loader))
			return;
		Load(loader);
		preloaded.Insert(loader);
	}
	
	void RLConfigManager() {
		for (int prio = RLConfigPriority.LOWEST; prio <= RLConfigPriority.HIGHEST; prio++) {
			loaders.Insert(prio, new set<typename>());
		}
	}
	
	RLConfigManager RegisterConfigLoader(typename configName) {
		typename loaderTypename = GetLoaderTypename(configName);
		loaders.Get(RLConfigPriority.NORMAL).Insert(loaderTypename);
		priorities.Insert(loaderTypename, RLConfigPriority.NORMAL);
		RLLogger.Verbose("Registering config: " + configName.ToString() + ". Loader Class: " + loaderTypename, "Core");
		return this;
	}
	
	typename GetLoaderTypename(typename configName) {
		return GetLoaderTypename(configName.ToString());
	}
	
	typename GetLoaderTypename(string configName) {
		string loaderName = configName.Substring(0, configName.Length() - 1);
		typename loaderTypename = loaderName.ToType();
		return loaderTypename;
	}
	
	void SetConfigPriority(typename configName, RLConfigPriority newPrio) {
		typename loaderName = GetLoaderTypename(configName);
		RLConfigPriority old = priorities.Get(loaderName);
		if (old == newPrio)
			return;
		priorities.Set(loaderName, newPrio);
		loaders.Get(old).RemoveItem(loaderName);
		loaders.Get(newPrio).Insert(loaderName);
		RLLogger.Verbose("Changed Priority of " + loaderName.ToString() + " from " + EnumTools.EnumToString(RLConfigPriority, old) + " to " + EnumTools.EnumToString(RLConfigPriority, newPrio), "Core");
	}
	
	RLConfigLoaderBase GetByName(string name) {
		foreach (string name_, RLConfigLoaderBase type : types) {
			if (name == name_)
				return type;
		}
		return null;
	}
	
	void LoadAllConfigs() {
		for (int prio = RLConfigPriority.HIGHEST; prio >= RLConfigPriority.LOWEST; prio--) {
			LoadConfigsOf(prio);
		}
		loadedAllConfigs = true;
		preloaded.Clear();
		RLAdmins.Get().OnRegisterFinished();
	}
	
	void LoadConfigsOf(RLConfigPriority prio) {
		set<typename> loaderList = loaders.Get(prio);
		RLLogger.Verbose("Loading config: " + loaderList.Count() + " configs with Priority: " + EnumTools.EnumToString(RLConfigPriority, prio), "Core");
		foreach (typename loader : loaderList) {
			RLConfigLoaderBase initLoader;
			if (types.Find(loader.ToString() + "_", initLoader)) {
				if (missionInitialized)
					initLoader.InitMission();
				continue;
			}
			Load(loader);
		}
	}
	
	private void Load(typename loader) {
		if (!loader)
			return;
		RLConfigPriority prio = priorities.Get(loader);
		RLConfigLoaderBase loaderInstance = RLConfigLoaderBase.Cast(loader.Spawn());
		if (loaderInstance == null) {
			RLLogger.Fatal("Tried to create Config Loader for " + loader + ", but class does not inherit the RLConfigLoaderBase class !", "Core");
			return;
		}
		RLLogger.Verbose("Loading config: " + loader.ToString() + " Instance: " + loaderInstance + " Priority: " + EnumTools.EnumToString(RLConfigPriority, prio), "Core");
		loaderInstance.Load();
		RLLogger.Verbose("Finished loading of " + loader.ToString(), "Core");
		if (loaderInstance.GetChangePermission() != "")
			RLAdmins.Get().RegisterPermission(loaderInstance.GetChangePermission(), false, false);
		if (loaderInstance.GetReceivePermission() != "")
			RLAdmins.Get().RegisterPermission(loaderInstance.GetReceivePermission(), false, false);
		types.Insert(loader.ToString() + "_", loaderInstance);
	}
	
	void OnMissionInit() {
		foreach (string name, RLConfigLoaderBase loader : types) {
			loader.InitMission();
		}
		missionInitialized = true;
	}
	
}