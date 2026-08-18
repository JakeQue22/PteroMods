#ifndef RL_DISABLE_CHAT
class ChatConfig {
	
	const static int VERSION = 6;
	int currentVersion = VERSION;
	
	bool displayGroupTagsInfrontOfName = true;
	bool forceDisplayGroupTagsInChat = false;
	bool enableMuteVote = false;
	int muteVoteMinPlayers = 20;
	int muteVoteMuteTimeMins = 10;
	float muteVotePercentile = 0.6;
	ref array<ref ChannelCfg> channels = new array<ref ChannelCfg>();
	ref RLColorConfig colorBattleyeMessage = RLColorConfig.Init(255,255,0,0);
	ref array<ref PrefixGroup> prefixGroups = new array<ref PrefixGroup>();
	ref TStringArray badWords = new TStringArray();
	bool enabledBadWordsCensor = false;
	bool blockBadWordContainingMessages = false;
	string badWordsBlockedMessage = "Your Message contains Bad Words !";
	int badWordsMuteTime = 0;
	
	void SendChatList(PlayerIdentity ident = null) {
		ScriptRPC rpc = new ScriptRPC();
		rpc.Write(channels.Count());
		foreach (ChannelCfg cfg : channels) {
			cfg.WriteToCtx(rpc);
		}
		GetBattleyeColor();
		colorBattleyeMessage.WriteToCtx(rpc);
		rpc.Send(null, RLGroupRPCs.RL_GLOBAL_CHANNELS, true, ident);
	}
	
	void WriteToCtx(ParamsWriteContext ctx) {
		ctx.Write(displayGroupTagsInfrontOfName);
		ctx.Write(forceDisplayGroupTagsInChat);
		ctx.Write(enableMuteVote);
		ctx.Write(muteVoteMinPlayers);
		ctx.Write(muteVoteMuteTimeMins);
		ctx.Write(muteVotePercentile);
		int count = channels.Count();
		ctx.Write(count);
		for (int i = 0; i < count; i++) {
			channels.Get(i).WriteToCtx(ctx);
		}
		colorBattleyeMessage.WriteToCtx(ctx);
		count = prefixGroups.Count();
		ctx.Write(count);
		for (i = 0; i < count; i++) {
			prefixGroups.Get(i).WriteToCtx(ctx);
		}
		ctx.Write(badWords);
		ctx.Write(enabledBadWordsCensor);
		ctx.Write(blockBadWordContainingMessages);
		ctx.Write(badWordsBlockedMessage);
		ctx.Write(badWordsMuteTime);
	}
	
	bool ReadFromCtx(ParamsReadContext ctx) {
		if (!ctx.Read(displayGroupTagsInfrontOfName))
			return false;
		if (!ctx.Read(forceDisplayGroupTagsInChat))
			return false;
		if (!ctx.Read(enableMuteVote))
			return false;
		if (!ctx.Read(muteVoteMinPlayers))
			return false;
		if (!ctx.Read(muteVoteMuteTimeMins))
			return false;
		if (!ctx.Read(muteVotePercentile))
			return false;
		int count = 0;
		if (!ctx.Read(count))
			return false;
		channels.Clear();
		for (int i = 0; i < count; i++) {
			ChannelCfg cfg = new ChannelCfg();
			if (!cfg.ReadFromCtx(ctx))
				return false;
			channels.Insert(cfg);
		}
		colorBattleyeMessage = new RLColorConfig();
		if (!colorBattleyeMessage.ReadFromCtx(ctx))
			return false;
		count = 0;
		if (!ctx.Read(count))
			return false;
		prefixGroups.Clear();
		for (i = 0; i < count; i++) {
			PrefixGroup prefix = new PrefixGroup();
			if (!prefix.ReadFromCtx(ctx))
				return false;
			prefixGroups.Insert(prefix);
		}
		if (!ctx.Read(badWords))
			return false;
		if (!ctx.Read(enabledBadWordsCensor))
			return false;
		if (!ctx.Read(blockBadWordContainingMessages))
			return false;
		if (!ctx.Read(badWordsBlockedMessage))
			return false;
		if (!ctx.Read(badWordsMuteTime))
			return false;
		return true;
	}
	
	void SaveConfig() {
		RayLabConfigMover.CreateFolders(RLGroupConstants.CONFIG_FOLDER);
		JsonFileLoader<ChatConfig>.JsonSaveFile(RLGroupConstants.CONFIG_FOLDER + "ChatConfig.json", this);
	}
	
	void CreateNewChannel(string text) {
		channels.Insert(ChannelCfg.Init("New Channel", 0, 255, 120, true, false, false, false, true, "", ""));
	}
	
	void RemoveCurrentChannel(int index) {
		channels.RemoveOrdered(index);
	}
	
	int GetChannelCount() {
		return channels.Count();
	}
	
	string GetChannelName(int index, int column) {
		return channels.Get(index).channelName;
	}
	
	void CreateNewPrefix(string text) {
		prefixGroups.Insert(PrefixGroup.Init("[PREFIX] ", 255, 0, 255, ""));
	}
	
	void RemoveCurrentPrefix(int index) {
		prefixGroups.RemoveOrdered(index);
	}
	
	int GetPrefixGroupCount() {
		return prefixGroups.Count();
	}
	
	string GetPrefixGroup(int index, int column) {
		return prefixGroups.Get(index).prefix;
	}
	
	PrefixGroup GetPrefixForSteamid(string steamid) {
		foreach (PrefixGroup grp : prefixGroups) {
			if (grp.IsMember(steamid))
				return grp;
		}
		return null;
	}
	
	ChannelCfg FindChannelByName(string name) {
		string lowerName = "" + name;
		lowerName.ToLower();
		foreach (ChannelCfg cfg : channels) {
			string lowerChannelName = "" + cfg.channelName;
			lowerChannelName.ToLower();
			if (lowerChannelName == lowerName)
				return cfg;
		}
		return null;
	}
	
	int GetBattleyeColor() {
		if (!colorBattleyeMessage) {
			colorBattleyeMessage = RLColorConfig.Init(255,255,0,0);
			SaveConfig();
		}
		return colorBattleyeMessage.GetColorARGB();
	}
	
	static ref ChatConfig g_ChatConfig;
	static ChatConfig Get() {
		if (!g_ChatConfig) {
			g_ChatConfig = Load();
		}
		return g_ChatConfig;
	}
	
	static ChatConfig Load() {
		ChatConfig cfg = new ChatConfig();
		RayLabConfigMover.MoveFile(RLGroupConstants.SAVE_PREFIX_OLD + "ChatConfig.json", RLGroupConstants.CONFIG_FOLDER);
		if (!FileExist(RLGroupConstants.CONFIG_FOLDER + "ChatConfig.json")) {
			cfg.prefixGroups.Insert(PrefixGroup.Init("[Owner] ", 255, 0, 255, "chat.prefix.owner"));
			cfg.prefixGroups.Insert(PrefixGroup.Init("[Admin] ", 255, 0, 0, "chat.prefix.admin"));
			cfg.prefixGroups.Insert(PrefixGroup.Init("[VIP] ", 255, 255, 0, "chat.prefix.vip").AddMember("76561198141097113").AddMember("More Steamids"));
			cfg.badWords = new TStringArray();
			cfg.badWords.Insert("nigger");
			cfg.badWords.Insert("nazi");
			cfg.enableMuteVote = false;
			cfg.muteVoteMinPlayers = 20;
			cfg.muteVoteMuteTimeMins = 10;
			cfg.muteVotePercentile = 0.6;
			cfg.badWordsMuteTime = 10;
			cfg.blockBadWordContainingMessages = false;
			cfg.colorBattleyeMessage = RLColorConfig.Init(255,255,0,0);
			cfg.enabledBadWordsCensor = false;
			cfg.badWordsBlockedMessage = "Your Message contains Bad Words !";
			cfg.channels.Insert(ChannelCfg.Init("Direct", 255, 255, 255, false, false, true, false, true, "", ""));
			cfg.channels.Insert(ChannelCfg.Init("Global", 3, 180, 252, true, false, false, true, true, "", ""));
			cfg.channels.Insert(ChannelCfg.Init("Group", 3, 252, 15, false, true, false, false, true, "", ""));
			cfg.channels.Insert(ChannelCfg.Init("Admin", 255, 252, 15, false, true, false, false, true, "channel.admin.use", "channel.admin.use"));
			
			cfg.SaveConfig();
		} else {
			JsonFileLoader<ChatConfig>.JsonLoadFile(RLGroupConstants.CONFIG_FOLDER + "ChatConfig.json", cfg);
		}
		cfg.UpdateVersion();
		cfg.RegisterPermissions();
		return cfg;
	}
	
	void RegisterPermissions() {
		foreach (ChannelCfg channel : channels) {
			if (channel.writeChannelPermission != "")
				RLAdmins.Get().RegisterPermission(channel.writeChannelPermission);
			if (channel.readChannelPermission != "")
				RLAdmins.Get().RegisterPermission(channel.readChannelPermission);
		}
		RLAdmins.Get().OnRegisterFinished();
	}
	
	bool UpdateVersion() {
		if (currentVersion == ChatConfig.VERSION) {
			return false;
		}
		if (currentVersion <= 0) {
			badWords.Insert("nigger");
			badWords.Insert("nazi");
		}
		if (currentVersion <= 1) {
			colorBattleyeMessage = RLColorConfig.Init(255, 255,0,0);
		}
		if (currentVersion < 6) {
			foreach (ChannelCfg channel : channels) {
				channel.canReceiveMessagesFromPlayers = true;
			}
			channels.Insert(ChannelCfg.Init("Admin", 255, 252, 15, false, true, false, false, true, "channel.admin.use", "channel.admin.use"));
		}
		currentVersion = ChatConfig.VERSION;
		SaveConfig();
		return true;
	}
	
	static bool HasWritableChannel(array<ref ChannelCfg> channels_) {
		if (!channels_ || channels_.Count() == 0)
			return false;
		foreach (ChannelCfg channel : channels_) {
			if (channel.canReceiveMessagesFromPlayers && (channel.writeChannelPermission == "" || RLAdmins.Get().HasPermission(channel.writeChannelPermission)))
				return true;
		}
		return false;
	}
	
	static void Reload() {
		g_ChatConfig = Load();
		g_ChatConfig.SendChatList();
	}
}
class PrefixGroup {

	string prefix;
	int colorR, colorG, colorB;
	ref TStringArray members = new TStringArray();
	string permissionToApplyGroup = "";
	
	int GetColor() {
		return ARGB(255, colorR, colorG, colorB);
	}
	
	bool IsMember(string steamid) {
		return members.Find(steamid) != -1 || permissionToApplyGroup != "" && RLAdmins.Get().HasPermission(permissionToApplyGroup, steamid, true);
	}
	
	static PrefixGroup Init(string prefix_, int colorR_, int colorG_, int colorB_, string permission) {
		PrefixGroup grp = new PrefixGroup();
		grp.prefix = prefix_;
		grp.colorR = colorR_;
		grp.colorG = colorG_;
		grp.colorB = colorB_;
		grp.permissionToApplyGroup = permission;
		return grp;
	}
	
	PrefixGroup AddMember(string member) {
		members.Insert(member);
		return this;
	}
	
	void WriteToCtx(ParamsWriteContext ctx) {
		ctx.Write(prefix);
		ctx.Write(colorR);
		ctx.Write(colorG);
		ctx.Write(colorB);
		ctx.Write(members);
		ctx.Write(permissionToApplyGroup);
	}
	
	bool ReadFromCtx(ParamsReadContext ctx) {
		if (!ctx.Read(prefix))
			return false;
		if (!ctx.Read(colorR))
			return false;
		if (!ctx.Read(colorG))
			return false;
		if (!ctx.Read(colorB))
			return false;
		if (!ctx.Read(members))
			return false;
		if (!ctx.Read(permissionToApplyGroup))
			return false;
		return true;
	}
}
class ChannelCfg {
	string channelName = "New Channel";
	ref RLColorConfig channelColor = new RLColorConfig();
	bool canReceiveMessagesFromPlayers = true;
	string writeChannelPermission = "";
	string readChannelPermission = "";
	bool globalChannel = true;
	bool groupChannel = true;
	bool directChannel = true;
	bool defaultChannel = false;
	[NonSerialized()]
	bool muted = false;
	
	static ChannelCfg Init(string name, int r, int g, int b, bool globalC, bool groupC, bool directC, bool def, bool playersCanWrite, string readPerm, string writePerm) {
		ChannelCfg cfg = new ChannelCfg();
		cfg.channelName = name;
		cfg.channelColor = RLColorConfig.Init(255, r,g,b);
		cfg.globalChannel = globalC;
		cfg.groupChannel = groupC;
		cfg.directChannel = directC;
		cfg.defaultChannel = def;
		cfg.canReceiveMessagesFromPlayers = playersCanWrite;
		cfg.writeChannelPermission = writePerm;
		cfg.readChannelPermission = readPerm;
		return cfg;
	}
	
	void WriteToCtx(ParamsWriteContext ctx) {
		ctx.Write(channelName);
		channelColor.WriteToCtx(ctx);
		ctx.Write(globalChannel);
		ctx.Write(groupChannel);
		ctx.Write(directChannel);
		ctx.Write(defaultChannel);
		ctx.Write(muted);
		ctx.Write(canReceiveMessagesFromPlayers);
		ctx.Write(writeChannelPermission);
		ctx.Write(readChannelPermission);
	}
	
	bool ReadFromCtx(ParamsReadContext ctx) {
		if (!ctx.Read(channelName))
			return false;
		channelColor = new RLColorConfig();
		if (!channelColor.ReadFromCtx(ctx))
			return false;
		if (!ctx.Read(globalChannel))
			return false;
		if (!ctx.Read(groupChannel))
			return false;
		if (!ctx.Read(directChannel))
			return false;
		if (!ctx.Read(defaultChannel))
			return false;
		if (!ctx.Read(muted))
			return false;
		if (!ctx.Read(canReceiveMessagesFromPlayers))
			return false;
		if (!ctx.Read(writeChannelPermission))
			return false;
		if (!ctx.Read(readChannelPermission))
			return false;
		return true;
	}
}
#endif