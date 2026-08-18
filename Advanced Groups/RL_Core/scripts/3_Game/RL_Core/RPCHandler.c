modded class DayZGame {

	ref map<int, ref ScriptInvoker> rl_registeredRPCs = new map<int, ref ScriptInvoker>();
	ref array<ref RL_RPCHandler_Base> rl_rpcHandlerInstanced = new array<ref RL_RPCHandler_Base>();
	
	// deprecated. Will be removed in future versions. Use the RegisterRPC(type, ScriptCaller) function of the RL_RPCHandler_Base class
	ScriptInvoker RLRegisterRPC(int type) {
		ScriptInvoker invoker;
		if (rl_registeredRPCs.Find(type, invoker))
			return invoker;
		invoker = new ScriptInvoker();
		rl_registeredRPCs.Insert(type, invoker);
		return invoker;
	}
	
	override void OnRPC(PlayerIdentity sender, Object target, int rpc_type, ParamsReadContext ctx) {
		super.OnRPC(sender, target, rpc_type, ctx);
		ScriptInvoker invoker;
		if (rl_registeredRPCs.Find(rpc_type, invoker))
			invoker.Invoke(sender, target, rpc_type, ctx);
		foreach (RL_RPCHandler_Base handler : rl_rpcHandlerInstanced) {
			handler.OnRPC(sender, target, rpc_type, ctx);
		}
	}
	
	void ClearRegisteredRPCs() {
		rl_registeredRPCs.Clear();
		foreach (RL_RPCHandler_Base handler : rl_rpcHandlerInstanced) {
			delete handler;
		}
		rl_rpcHandlerInstanced.Clear();
	}
	
	void InitRegisteredRPCHandlersRL() {
		ClearRegisteredRPCs();
		foreach (typename registered : g_RL_RegisteredRPCHandlers) {
			Class created = registered.Spawn();
			RL_RPCHandler_Base handler;
			if (!Class.CastTo(handler, created)) {
				Error("Registed RPC Handler was not of type RL_RPCHandler_Base. Got: " + registered.ToString());
				continue;
			}
			rl_rpcHandlerInstanced.Insert(handler);
		}
	}
	
	void ExecuteInitRPCs() {
		foreach (RL_RPCHandler_Base handler2 : rl_rpcHandlerInstanced) {
			handler2.ExecuteInitRPCs();
		}
	}
	
	RL_RPCHandler_Base RLGetRPCHandler(string type) {
		foreach (RL_RPCHandler_Base handler : rl_rpcHandlerInstanced) {
			if (handler.ClassName() == type)
				return handler;
		}
		return null;
	}
	
}
static ref set<typename> g_RL_RegisteredRPCHandlers = new set<typename>();

RL_RPCHandler_Base RegisterRLRPCHandler(typename classname, RLRPCHandlerType type) {
	
	#ifdef SERVER // Set when Server
	if (type == RLRPCHandlerType.CLIENT)
		return null;
	#else
	if (type == RLRPCHandlerType.SERVER)
		return null;
	#endif
	
	g_RL_RegisteredRPCHandlers.Insert(classname);
	return null;
}

enum RLRPCHandlerType {

	CLIENT, SERVER, BOTH
	
}

class RL_RPCHandler_Base {
	
	private ref map<int, ref RL_RPCHandler_Config> registeredRpcs = new map<int, ref RL_RPCHandler_Config>();
	
	protected PlayerIdentity sender;
	protected string steamid;
	protected Object target;
	protected int rpc_type;
	protected ParamsReadContext ctx;
	private Man man;
	protected ref ScriptRPC rpc = new ScriptRPC();

	void RegisterRPC(int rpc_type_1, int rpc_type_2, int rpc_type_3, int rpc_type_4, int rpc_type_5, ScriptCaller function) {
		RegisterRPC(rpc_type_1, function);
		RegisterRPC(rpc_type_2, function);
		RegisterRPC(rpc_type_3, function);
		RegisterRPC(rpc_type_4, function);
		RegisterRPC(rpc_type_5, function);
	}

	void RegisterRPC(int rpc_type_1, int rpc_type_2, int rpc_type_3, int rpc_type_4, ScriptCaller function) {
		RegisterRPC(rpc_type_1, function);
		RegisterRPC(rpc_type_2, function);
		RegisterRPC(rpc_type_3, function);
		RegisterRPC(rpc_type_4, function);
	}


	void RegisterRPC(int rpc_type_1, int rpc_type_2, int rpc_type_3, ScriptCaller function) {
		RegisterRPC(rpc_type_1, function);
		RegisterRPC(rpc_type_2, function);
		RegisterRPC(rpc_type_3, function);
	}

	void RegisterRPC(int rpc_type_1, int rpc_type_2, ScriptCaller function) {
		RegisterRPC(rpc_type_1, function);
		RegisterRPC(rpc_type_2, function);
	}
	
	void RegisterRPC(int rpc_type_, ScriptCaller function) {
		RL_RPCHandler_Config cfg;
		if (!registeredRpcs.Find(rpc_type_, cfg)) {
			cfg = new RL_RPCHandler_Config();
			registeredRpcs.Insert(rpc_type_, cfg);
		}
		cfg.AddCaller(function);
	}
	
	bool HasPermission(string perm) {
		return RLAdmins.Get().HasPermission(perm, sender);
	}
	
	void ExecuteInitRPCs();
	void PostRPC();
	
	void OnRPC(PlayerIdentity sender_, Object target_, int rpc_type_, ParamsReadContext ctx_) {
		#ifdef SERVER
		//Print("On RPC Server: " + rpc_type_ + " by " + sender_ + " to " + target_);
		if (!sender_)
			return;
		steamid = sender_.GetPlainId();
		#else
		//Print("On RPC Client: " + rpc_type_);
		steamid = "";
		#endif
		RL_RPCHandler_Config config;
		if (!registeredRpcs.Find(rpc_type_, config))
			return;
		//Print("Found config for RPC. Handlers: " + config.registeredRpcs.Count() + " Config: " + config);
		sender = sender_;
		target = target_;
		rpc_type = rpc_type_;
		ctx = ctx_;
		LinkVariable();
		config.Invoke();
		PostRPC();
	}
	
	void SendErrorNotification(PlayerIdentity to, string message, int show_time = 4) {
		NotificationSystem.SendNotificationToPlayerIdentityExtended(to, show_time, GetNotificationTitle(), message, RLIconConfig.Get.error);
	}
	
	void SendWarningNotification(PlayerIdentity to, string message, int show_time = 4) {
		NotificationSystem.SendNotificationToPlayerIdentityExtended(to, show_time, GetNotificationTitle(), message, RLIconConfig.Get.warning);
	}
	
	void SendInfoNotification(PlayerIdentity to, string message, int show_time = 4) {
		NotificationSystem.SendNotificationToPlayerIdentityExtended(to, show_time, GetNotificationTitle(), message, RLIconConfig.Get.info);
	}
	
	void SendErrorNotification(string message, int show_time = 4) {
		SendErrorNotification(sender, message, show_time);
	}
	
	void SendWarningNotification(string message, int show_time = 4) {
		SendWarningNotification(sender, message, show_time);
	}
	
	void SendInfoNotification(string message, int show_time = 4) {
		SendInfoNotification(sender, message, show_time);
	}
	
	string GetNotificationTitle() {}
	
	void LinkVariable() {
		rpc.Reset();
		man = null;
	}
	
	protected Man GetMan() {
		if (man)
			return man;
		#ifdef SERVER
		man = sender.GetPlayer();
		#else
		man = GetGame().GetPlayer();
		#endif
		return man;
	}
	
}
class RL_RPCHandler_Config {

	ref array<ref ScriptCaller> registeredRpcs = new array<ref ScriptCaller>();
	
	void AddCaller(ScriptCaller function) {
		registeredRpcs.Insert(function);
	}
	
	void Invoke() {
		foreach (ScriptCaller caller : registeredRpcs) {
			caller.Invoke();
		}
	}
	
}