class RLGroupMember : RLMarker {

	static const string DISABLED_STEAMID_PREFIX = "d_";
	
	string steamid;
	int permissionGroup;
	bool online = false;
	float health;
	[NonSerialized()]
	int lastPBCheck = 0;
	[NonSerialized()]
	PlayerBase clientPBFound;
	[NonSerialized()]
	string hashedId = "";
	bool tempMember = false;
	
	void FindPlayerBase() {
		foreach (Man man : RLPlayers.players) {
			DayZPlayer player = DayZPlayer.Cast(man);
			if (player && player.IsAlive() && player.GetIdentity()) {
				string hashid = player.GetIdentity().GetPlainId();
				if (hashid == steamid) {
					clientPBFound = player;
					break;
				}
			}
		}
	}
	
	RLGroupPermission GetPermission() {
		return RLGroupPermissions.Get.FindPermissionGroupByUID(permissionGroup);
	}
	
	bool IsTemporaryMember() {
		return tempMember;
	}
	
	bool IsDisabled() {
		return steamid.IndexOf(DISABLED_STEAMID_PREFIX) == 0;
	}
	
	string GetOriginalSteamid() {
		if (IsDisabled())
			return steamid.Substring(DISABLED_STEAMID_PREFIX.Length(), steamid.Length() - DISABLED_STEAMID_PREFIX.Length());
		return steamid;
	}
	
	// Set this player as disabled for allowing secondary groups. Returns true if the state changed from the previous state
	bool SetDisabled(bool disabled) {
		if (disabled == IsDisabled())
			return false;
		if (disabled) {
			SetSteamid(DISABLED_STEAMID_PREFIX + steamid);
		} else {
			SetSteamid(steamid.Substring(DISABLED_STEAMID_PREFIX.Length(), steamid.Length() - DISABLED_STEAMID_PREFIX.Length()));
		}
		return true;
	}

	void SetSteamid(string steamid_) {
		this.steamid = steamid_;
		clientPBFound = null;
		FindPlayerBase();
	}
	
	override void SetPosition(vector pos) {
		if (!IsValidPlayerBase())
			super.SetPosition(pos);
	}
	
	vector GetMarkerWorldPos() {
		if (RLLayoutConfig.Get().playerMarkerPosIndex == 0) {
			vector vec = "0 0 0";
			MiscGameplayFunctions.GetHeadBonePos(clientPBFound, vec);
			vec = vec + "0 0.2 0";
			return vec;
		} else if (RLLayoutConfig.Get().playerMarkerPosIndex == 1) {
			return RL_PlayerBase_Utils.GetHeadPosition(clientPBFound);
		}
		return clientPBFound.GetPosition();
	}
	
	override bool ShouldCenterWidget() {
		return true;
	}
	
	override bool ShowMarker3D() {
		if (!super.ShowMarker3D())
			return false;
		if (!mainWidget || !RLUtils.IsClientPlayerAlive())
			return false;
		//RLLogger.Debug("Marker Show was ok. My Steamid: " + MissionBaseWorld.mySteamid + " This Steamid: " + steamid + " Name: " + name + " Online: " + online);
		return ShowPlayerMarker() && ClientPlayerHasRadio();
	}
	
	static bool ClientPlayerHasRadio() {
		if (!RLGroupMainConfig.Get.requireItemToSeeGroupMembers)
			return true;
		return RL_PlayerBase_Utils.HasItemsInInventory(PlayerBase.Cast(GetGame().GetPlayer()), RLGroupMainConfig.Get.playerMarkerItems);
	}
	
	bool ShowPlayerMarker() {
		if (RLAdmins.Get().GetMySteamid() == steamid)
			return false;
		if (online)
			return true;
		return RLGroupMainConfig.Get.offlinePlayer3dMarkerDistance > dist;
	}
	
	override string GetIcon() {
		return RLMarkerVisibilityManager.Get().GetPlayerMarkerIcon();
	}
	
	override bool ShowDistance3D() {
		if (!super.ShowDistance3D())
			return false;
		return (RLLayoutConfig.Get().playerMarkerStyleIndex & 0x04) == 0;
	}
	
	override void UpdateMarkerSlow() {
		super.UpdateMarkerSlow();
		int index = RLLayoutConfig.Get().playerMarkerStyleIndex;
		if (nameWidget)
			nameWidget.Show((index & 0x02) == 0);
		if (iconWidget) {
			iconWidget.Show((index & 0x01) == 0);
			string icon_ = RLMarkerVisibilityManager.Get().GetPlayerMarkerIcon();
			int size = RLMarkerVisibilityManager.Get().playerSize;
			iconWidget.LoadImageFile(0, icon_);
			iconWidget.SetSize(size, size);
		}
	}
	
	override bool ShowMarkerMapOrGPS(bool isMap) {
		if (!super.ShowMarkerMapOrGPS(isMap))
			return false;
		//RLLogger.Debug("Marker Show was ok. My Steamid: " + MissionBaseWorld.mySteamid + " This Steamid: " + steamid + " Name: " + name + " Online: " + online);
		if (RLAdmins.Get().GetMySteamid() == steamid)
			return false;
		return ClientPlayerHasRadio();
	}
	
	override bool UpdateMarkerClient() {
		if (!super.UpdateMarkerClient())
			return false;
		int now = GetGame().GetTime();
		if (now - lastPBCheck > 1000) { // Always look for new Playerbase to see if that fixes invisible Tags related to Cars and Helicopters
			FindPlayerBase();
			lastPBCheck = now;
		}
		if (IsValidPlayerBase()) {
			position = GetMarkerWorldPos();
		}
		return true;
	}
	
	override void OnMarkerRPCClient(int type_, ParamsReadContext ctx) {
		super.OnMarkerRPCClient(type_, ctx);
		if (type_ == RLGroupRPCs.HEALTH) {
			float health_ = 0;
			if (!ctx.Read(health_))
				return;
			SetHealth(health_);
		} else if (type_ == RLGroupRPCs.PERMISSION) {
			int perm = 0;
			if (!ctx.Read(perm))
				return;
			SetPermission(perm);
		} else if (type_ == RLGroupRPCs.ONLINE) {
			bool online_ = 0;
			string hashedId_;
			if (!ctx.Read(online_))
				return;
			if (!ctx.Read(hashedId_))
				return;
			SetOnline(online_, hashedId_);
		} else if (type_ == RLGroupRPCs.STEAMID) {
			string steamid_;
			if (!ctx.Read(steamid_))
				return;
			SetSteamid(steamid_);
		}
	}
	
	void SetPermission(int perm) {
		permissionGroup = perm;
	}
	
	string GetRankString() {
		RLGroupPermission perm = RLGroupPermissions.Get.FindPermissionGroupByUID(permissionGroup);
		if (!perm)
			return "Unknown";
		return perm.permName;
	}
	
	void SetOnline(bool on, string hashedId_) {
		online = on;
		this.hashedId = hashedId_;
		if (GetGame().IsClient())
			SetColor();
	}
	
	void SetHealth(float health_) {
		this.health = health_;
	}
	
	bool IsValidPlayerBase() {
		if (!clientPBFound || !clientPBFound.IsAlive())
			return false;
		return true;
	}
	
	bool CreateMember(PlayerBase pb) {
		if (!pb)
			return false;
		PlayerIdentity ident = pb.GetIdentity();
		if (!ident)
			return false;
		this.steamid = ident.GetPlainId();
		this.hashedId = ident.GetId();
		this.SetupMarker(RLMarkerType.GROUP_PLAYER_MARKER, ident.GetName(), "", pb.GetPosition());
		return true;
	}
	
	override int GetColorARGB() {
		if (online) {
			return RLColorManager.Get().GetColor("Player Online");
		} else {
			return RLColorManager.Get().GetColor("Player Offline");
		}
	}
	
	override int Get3DColorARGB() {
		if (online) {
			return RLColorManager.Get().GetColor("Player 3D Marker");
		} else {
			return super.Get3DColorARGB();
		}
	}
	
	override bool ReadFromCtx(ParamsReadContext ctx) {
		if (!super.ReadFromCtx(ctx))
			return false;
		if (!ctx.Read(steamid))
			return false;
		if (!ctx.Read(hashedId))
			return false;
		if (!ctx.Read(permissionGroup))
			return false;
		if (!ctx.Read(currentSubgroup))
			return false;
		if (!ctx.Read(online))
			return false;
		if (!ctx.Read(health))
			return false;
		return true;
	}
	
	override void WriteToCtx(ParamsWriteContext ctx, bool steamids_ = true, bool positions_ = true) {
		super.WriteToCtx(ctx, steamids_, positions_);
		if (steamids_)
			ctx.Write(steamid);
		else
			ctx.Write("");
		ctx.Write(hashedId);
		ctx.Write(permissionGroup);
		ctx.Write(currentSubgroup);
		ctx.Write(online);
		ctx.Write(health);
	}
}