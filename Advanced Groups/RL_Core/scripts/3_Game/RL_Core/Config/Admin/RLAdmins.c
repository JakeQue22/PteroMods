/*
class RLAdmins : RLConfigLoader<RLAdmins_> {
	override void InitVars() {
		InitVarsInternal("Common", "Admins.json", RLConfigType.CONFIG, true, "admins.change");
	}
}
*/
// This file is used to define the admins for all RayLab mods.
// After adding your Steamid to the file, you can enable Admin mode by pressing `U`
// This enabled some interaction options to configure items like ATMs or Garages
// To access the Admin Menu, you need to have admin mode enabled and press `I`
// These are the default buttons used, which can be changed in the DayZ Controls
class RLAdmins /*: RLConfigBase*/ {

	static const string GROUP_OWNER = "Owner";
	static const string GROUP_ADMIN = "Admin";
	static const string GROUP_MODERATOR = "Moderator";
	static const string GROUP_SUPPORT = "Support";
	private static bool requestedConfig = false;
	private static ref RLAdmins g_RLAdmins;
	private static const string CFG_PATH = "$profile:RayLab/Config/Common/Admins.json";
	
	const int CURRENT_VERSION = 2;
	
	private int version = CURRENT_VERSION; // Version for internal Version tracking
	
	private ref array<ref RLAdminsPlayer> admins = new array<ref RLAdminsPlayer>(); // List of Admins. Each admin needs their own Entry (`{... steamid ...}`)
	private ref array<ref RLAdminsGroup> groups = new array<ref RLAdminsGroup>(); // List of different Permission Groups / Ranks (Owner / Admin / Moderator / Supporter etc.)
	
	[NonSerialized()]
	private string mySteamid;
	[NonSerialized()]
	private bool active = false;
	[NonSerialized()]
	private ref TStringSet activeSteamids = new TStringSet();
	[NonSerialized()]
	bool needSave = false;
	[NonSerialized()]
	private ref ScriptInvoker activeToggle = new ScriptInvoker();
	[NonSerialized()]
	private ref array<PlayerIdentity> activeAdmins = new array<PlayerIdentity>();
		
	static RLAdmins Get() {
		if (!g_RLAdmins)
			g_RLAdmins = Load();
		return g_RLAdmins;
	}
	
	static bool Loaded() {
		return g_RLAdmins != null && g_RLAdmins.mySteamid != "";
	}
	
	static RLAdmins Load() {
		RLAdmins cfg = new RLAdmins();
		#ifndef NO_GUI
		return cfg;
		#endif
		RayLabConfigMover.CreateParentFolders(CFG_PATH);
		if (FileExist(CFG_PATH)) {
			JsonFileLoader<RLAdmins>.JsonLoadFile(CFG_PATH, cfg);
		} else {
			cfg = LoadDefault();
			cfg.Save();
		}
		cfg.RegisterPermission("adminmenu.open");
		cfg.RegisterPermission("currencies.change", false, false);
		cfg.Update();
		cfg.OnLoad();
		return cfg;
	}
	
	static RLAdmins LoadDefault() {
		RLAdmins cfg = new RLAdmins();
		cfg.InsertAdmin("76561198141097113", "", "", "Remove Me", true, {GROUP_OWNER});
		cfg.InsertAdmin("SteamiID Here", "", "", "Another Admin", false, {GROUP_ADMIN});
		cfg.InsertAdmin("I also need a Steamid", "Peter", "", "And a third one", false, {GROUP_SUPPORT});
		return cfg;
	}
	
	static void RequestConfig() {
		if (requestedConfig)
			return;
		GetGame().RPCSingleParam(null, RayLab_Core_RPCs.ADMIN_SYNC, new Param1<bool>(true), true);
		requestedConfig = true;
	}
	
	static void Delete() {
		if (g_RLAdmins)
			delete g_RLAdmins;
	}
	
	void ~RLAdmins() {
		requestedConfig = false;
	}
	
	void EnableAdminModeFor(PlayerIdentity identity) {
		activeSteamids.Insert(identity.GetPlainId());
		activeAdmins.Insert(identity);
	}
	
	void DisableAdminModeFor(PlayerIdentity identity) {
		int index = activeSteamids.Find(identity.GetPlainId());
		if (index >= 0)
			activeSteamids.Remove(index);
		activeAdmins.RemoveItem(identity);
	}
	
	void SendToActiveAdmins(ScriptRPC rpc, int type) {
		foreach (PlayerIdentity ident : activeAdmins) {
			if (ident)
				rpc.Send(null, type, true, ident);
		}
	}
	
	bool IsActive(PlayerIdentity identity) {
		if (!identity)
			return IsActive();
		return IsActive(identity.GetPlainId());
	}
	
	bool IsActive(string steamid = "") {
		return active || (activeSteamids.Find(steamid) != -1 && steamid != "");
	}
	
	bool IsActive(Man player) {
		if (!player)
			return false;
		return IsActive(player.GetIdentity());
	}
	
	bool CanEnableAdminMenu(string steamid = "") {
		return HasPermission("adminmenu.open", steamid);
	}
	
	void ToggleActive() {
		active = !active;
		RLLogger.Debug("Toggle Admin mode active: " + active, "AdminConfig");
		activeToggle.Invoke();
		GetGame().RPCSingleParam(null, RayLab_Core_RPCs.ADMIN_TOGGLE, new Param1<bool>(active), true);
	}
	
	ScriptInvoker GetActiveToggleEvent() {
		return activeToggle;
	}
	
	RLAdminsGroup RegisterGroup(string group) {
		RLAdminsGroup set_;
		if (FindGroup(group, set_))
			return set_;
		set_ = new RLAdminsGroup();
		set_.name = group;
		groups.Insert(set_);
		Save();
		return set_;
	}
	
	private bool FindGroup(string name, out RLAdminsGroup found) {
		foreach (RLAdminsGroup group : groups) {
			if (group && group.name == name) {
				found = group;
				return true;
			}
		}
		return false;
	}
	
	private void RegisterPermissionInternal(string permission, int level = 1, bool support = true, bool moderator = true, bool admin = true, bool owner = true) {
		RegisterPermissionIf(GROUP_OWNER, permission, level, owner);
		RegisterPermissionIf(GROUP_ADMIN, permission, level, admin);
		RegisterPermissionIf(GROUP_MODERATOR, permission, level, moderator);
		RegisterPermissionIf(GROUP_SUPPORT, permission, level, support);
	}
	
	private void RegisterPermissionIf(string group, string permission, int level, bool checked) {
		if (checked)
			RegisterPermissionInternal(group, permission, level);
		else
			RegisterPermissionInternal(group, permission, 0);
	}
	
	private void RegisterPermissionInternal(string group, string permission, int level = 1) {
		RLAdminsGroup groupPerms = RegisterGroup(group);
		if (!groupPerms.Contains(permission)) {
			groupPerms.Insert(permission, level);
			needSave = true;
		}
	}
	
	void RegisterPermission(string permission, bool support = true, bool moderator = true, bool admin = true, bool owner = true) {
		RegisterPermissionInternal(permission, 1, support, moderator, admin, owner);
	}
	
	void InsertAdmin(string steamid, string name, string chatname, string comment, bool grantAllPermissions, TStringArray permissionGroups = null) {
		RLAdminsPlayer player = new RLAdminsPlayer();
		player.comment = comment;
		player.ingameNameForPermissions = name;
		player.nameChatOnToggleOn = chatname;
		player.steamid = steamid;
		player.grantAllPermissions = grantAllPermissions;
		if (permissionGroups) {
			foreach (string str : permissionGroups)
				player.permissionGroups.Insert(str);
		}
		player.InitGrantedPermissions(groups);
		admins.Insert(player);
	}
	
	void OnLoad() {
		foreach (RLAdminsPlayer player : admins) {
			player.InitGrantedPermissions(groups);
		}
	}
	
	void Update() {
		if (CURRENT_VERSION == version)
			return;
		
		version = CURRENT_VERSION;
		Save();
	}
	
	void OnRegisterFinished() {
		if (needSave)
			Save();
	}
	
	void Save() {
		JsonFileLoader<RLAdmins>.JsonSaveFile(CFG_PATH, this);
		needSave = false;
		OnLoad();
	}
	
	bool LoadFromCtx(ParamsReadContext ctx) {
		if (!GetGame().IsClient())
			return false;
		int count = 0;
		if (!ctx.Read(mySteamid))
			return false;
		if (!ctx.Read(count))
			return false;
		RLLogger.Verbose("Trying to read " + count + " Admin entries from Server. My Steamid: " + mySteamid, "AdminConfig");
		admins.Clear();
		for (int i = 0; i < count; i++) {
			RLAdminsPlayer player = new RLAdminsPlayer();
			if (!player.ReadFromCtx(ctx))
				return false;
			admins.Insert(player);
		}
		return true;
	}
	
	void WriteToCtx(ParamsWriteContext ctx, PlayerIdentity requestor) {
		int count = 0;
		string steamid = "";
		if (!requestor) {
			ctx.Write(steamid);
			ctx.Write(count);
			return;
		}
		RLAdminsPlayer player = null;
		steamid = requestor.GetPlainId();
		ctx.Write(steamid);
		if (!FindPlayer(steamid, player)) {
			ctx.Write(count);
			return;
		}
		string name = requestor.GetName();
		if (player.ingameNameForPermissions == "" || player.ingameNameForPermissions == name) {
			count = 1;
			ctx.Write(count);
			player.WriteToCtx(ctx);
		} else {
			ctx.Write(count);
		}
	}

	RLAdminsPlayer FindPlayerBySteamID(string steamid) {
		foreach (RLAdminsPlayer player : admins) {
			if (player && player.steamid == steamid) {
				return player;
			}
		}
		return NULL;
	}
	
	bool FindPlayer(string steamid, out RLAdminsPlayer found) {
		foreach (RLAdminsPlayer player : admins) {
			if (player && player.steamid == steamid) {
				found = player;
				return true;
			}
		}
		return false;
	}
	
	bool HasPermission(string permission, Man player, bool cannotUseGrantAllPermissions = false) {
		if (player)
			return HasPermission(permission, player.GetIdentity(), cannotUseGrantAllPermissions);
		return HasPermission(permission, "", cannotUseGrantAllPermissions);
		
	}
	
	bool HasPermission(string permission, PlayerIdentity player, bool cannotUseGrantAllPermissions = false) {
		if (player)
			return HasPermission(permission, player.GetPlainId(), cannotUseGrantAllPermissions);
		return HasPermission(permission, "", cannotUseGrantAllPermissions);
	}
	
	bool HasPermission(string permission, string steamid = "", bool cannotUseGrantAllPermissions = false) {
		RLAdminsPlayer player = null;
		if (!GetPermissionPlayer(player, steamid))
			return false;
		return player.HasPermission(permission, cannotUseGrantAllPermissions);
	}
	
	bool GetPermissionPlayer(out RLAdminsPlayer player, string steamid) {
		if (!GetSteamid(steamid))
			return false;
		if (!FindPlayer(steamid, player))
			return false;
		return true;
	}
	
	bool IsAllPermissionsGranted(string steamid = "") {
		RLAdminsPlayer player = null;
		if (!GetPermissionPlayer(player, steamid))
			return false;
		return player.grantAllPermissions;
	}
	
	bool OverwriteValue(string permission, out int changed, int value, string steamid = "") {
		if (HasPermission(permission, steamid)) {
			changed = value;
			return true;
		}
		return false;
	}
	
	bool OverwriteValue(string permission, out string changed, string value, string steamid = "") {
		if (HasPermission(permission, steamid)) {
			changed = value;
			return true;
		}
		return false;
	}
	
	TStringArray FindAdminsWithPermission(string permission) {
		TStringArray arr = new TStringArray();
		foreach (RLAdminsPlayer player : admins) {
			if (player.HasPermission(permission))
				arr.Insert(player.steamid);
		}
		return arr;
	}
	
	array<PlayerIdentity> FindAdminsWithPermissionOnline(string permission) {
		TStringArray adminsWithPerm = FindAdminsWithPermission(permission);
		array<PlayerIdentity> players = new array<PlayerIdentity>();
		GetGame().GetPlayerIndentities(players);
		array<PlayerIdentity> list = new array<PlayerIdentity>();
		foreach (PlayerIdentity ident : players) {
			if (adminsWithPerm.Find(ident.GetPlainId()) != -1)
				list.Insert(ident);
		}
		return list;
	}
	
	void SendRPCToAdminsWithPermission(string permission, ScriptRPC rpc, int type) {
		array<PlayerIdentity> list = FindAdminsWithPermissionOnline(permission);
		foreach (PlayerIdentity online : list) {
			if (!online)
				continue;
			rpc.Send(null, type, true, online);
		}
	}
	
	private bool GetSteamid(out string steamid) {
		if (steamid == "" || GetGame().IsClient()) {
			if (GetGame().IsServer()) {
				RLLogger.Error("Cannot Check permissions in offline mode or without player Steamid !", "AdminConfig");
				Error("Cannot Check permissions in offline mode or without player Steamid !");
				return false;
			}
			steamid = GetMySteamid();
		}
		return true;
	}
	
	string GetMySteamid() {
		return mySteamid;
	}
	
}
