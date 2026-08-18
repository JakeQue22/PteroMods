#ifndef RL_DISABLE_CHAT
class MuteConfig {
	
	ref array<ref Param3<string, int, string>> mutedPlayers = new array<ref Param3<string, int, string>>();
	[NonSerialized()]
	ref TStringArray mutedPlayersSteamids = new TStringArray();

	static ref MuteConfig g_MuteConfig;
	
	static MuteConfig Get() {
		if (!g_MuteConfig) {
			g_MuteConfig = Load();
		}
		return g_MuteConfig;
	}
	
	static MuteConfig Load() {
		MuteConfig cfg = new MuteConfig();
		RayLabConfigMover.MoveFile(RLGroupConstants.SAVE_PREFIX_OLD + "MuteConfig.json", RLGroupConstants.CONFIG_FOLDER);
		if (!FileExist(RLGroupConstants.CONFIG_FOLDER + "MuteConfig.json")) {
			cfg.Save();
		} else {
			JsonFileLoader<MuteConfig>.JsonLoadFile(RLGroupConstants.CONFIG_FOLDER + "MuteConfig.json", cfg);
		}
		return cfg;
	}
	
	void Save() {
		RayLabConfigMover.CreateFolders(RLGroupConstants.CONFIG_FOLDER);
		JsonFileLoader<MuteConfig>.JsonSaveFile(RLGroupConstants.CONFIG_FOLDER + "MuteConfig.json", this);
	}
	
	void UpdateList() {
		int timeNow = RLDate.Init(true).GetTimestamp();
		int before = mutedPlayers.Count();
		mutedPlayersSteamids.Clear();
		for (int i = 0; i < mutedPlayers.Count(); i++) {
			Param3<string, int, string> muteParams = mutedPlayers.Get(i);
			if (!muteParams) {
				mutedPlayers.Remove(i);
				i--;
				continue;
			}
			if (timeNow >= muteParams.param2) {
				PlayerIdentity ident = RLUtils.GetPlayerIdentityById(muteParams.param1);
				if (ident)
					NotificationSystem.SendNotificationToPlayerIdentityExtended(ident, 4.0, "Mute", "You are no longer Muted", RLIconConfig.Get.info);
				RL_Webhook_Manager.Get.SendMessage("PlayerUnmute", {muteParams.param3, muteParams.param1, "Timeout"});
				mutedPlayers.Remove(i);
				i--;
				continue;
			}
			mutedPlayersSteamids.Insert(muteParams.param1);
		}
		if (before != mutedPlayers.Count()) {
			Save();
		}
		RLLogger.Debug("Mute Update from " + before + " to " + mutedPlayers.Count() + " Muted Players Now: " + timeNow, "AdvancedGroups");
	}
	
	int GetMutedCount() {
		return mutedPlayers.Count();
	}
	
	string GetMutedString(int index, int column) {
		if (column == 0)
			return mutedPlayers.Get(index).param3;
		if (column == 1)
			return mutedPlayers.Get(index).param1;
		if (column == 2)
			return RLDate.Init(mutedPlayers.Get(index).param2).ToDiffString();
		return "";
	}
	
	void WriteToCtx(ParamsWriteContext ctx) {
		int count = mutedPlayers.Count();
		ctx.Write(count);
		int now = RLDate.Init(true).GetTimestamp();
		for (int i = 0; i < count; i++) {
			Param3<string, int, string> mutedEntry = mutedPlayers.Get(i);
			ctx.Write(mutedEntry.param1);
			int duration = mutedEntry.param2 - now;
			ctx.Write(duration);
			ctx.Write(mutedEntry.param3);
		}
	}
	
	bool ReadFromCtx(ParamsReadContext ctx) {
		int count;
		if (!ctx.Read(count))
			return false;
		mutedPlayers.Clear();
		for (int i = 0; i < count; i++) {
			string steamid, name;
			int duration;
			if (!ctx.Read(steamid) || !ctx.Read(duration) || !ctx.Read(name))
				return false;
			Param3<string, int, string> mutedEntry = new Param3<string, int, string>(steamid, duration, name);
			mutedPlayers.Insert(mutedEntry);
		}
		return true;
	}
	
	void SendMuteList() {
		UpdateList();
		array<PlayerIdentity> identities = new array<PlayerIdentity>();
		GetGame().GetPlayerIndentities(identities);
		GetGame().RPCSingleParam(null, RLGroupRPCs.RL_GLOBAL_MUTELIST, new Param1<bool>(false), true); // Unmute All
		foreach (PlayerIdentity ident : identities) {
			if (!ident)
				continue;
			string steamid = ident.GetPlainId();
			if (mutedPlayersSteamids.Find(steamid) != -1) {
				RLLogger.Debug("Player Muted: " + steamid, "AdvancedGroups");
				SendMute(ident); // Mute other Players again
			}
		}
	}
	
	void MutePlayer(string name, string steamid, int minutes, string mutedBy) {
		int now = RLDate.Init(true).GetTimestamp() + minutes * 60;
		UnMutePlayer(steamid, "", false);
		mutedPlayers.Insert(new Param3<string, int, string>(steamid, now, name));
		SendMuteList();
		string timeStr = RLDate.Init(minutes*60).ToDiffString();
		RL_Webhook_Manager.Get.SendMessage("PlayerMute", {name, steamid, timeStr, mutedBy});
	}
	
	bool IsMuted(string steamid) {
		for (int i = 0; i < mutedPlayers.Count(); i++) {
			Param3<string, int, string> muteParams = mutedPlayers.Get(i);
			if (!muteParams) {
				mutedPlayers.Remove(i);
				i--;
				continue;
			} else if (muteParams.param1 == steamid) {
				return true;
			}
		}
		return false;
	}
	
	void UnMutePlayer(string steamid, string reason, bool sendWebhook = true) {
		for (int i = 0; i < mutedPlayers.Count(); i++) {
			Param3<string, int, string> muteParams = mutedPlayers.Get(i);
			if (!muteParams) {
				mutedPlayers.Remove(i);
				i--;
				continue;
			} else if (muteParams.param1 == steamid) {
				if (sendWebhook)
					RL_Webhook_Manager.Get.SendMessage("PlayerUnmute", {muteParams.param3, steamid, reason});
				mutedPlayers.Remove(i);
				SendMuteList();
				return;
			}
		}
		SendMuteList();
	}
	
	void SendMute(PlayerIdentity ident) {
		GetGame().RPCSingleParam(null, RLGroupRPCs.RL_GLOBAL_MUTELIST, new Param1<bool>(true), true, ident);
	}

}
#endif