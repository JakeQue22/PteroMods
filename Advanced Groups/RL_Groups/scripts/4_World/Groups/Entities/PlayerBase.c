modded class PlayerBase {
	
	static ref array<PlayerBase> rl_player_list = new array<PlayerBase>();
	int identityIdHash = 0;
	int steamidHash = 0;
	int lastSteamidHash = 0;
	private ref RLSafezoneMarker safezoneMarker;
	
	void PlayerBase() {
		if (!rl_player_list)
			rl_player_list = new array<PlayerBase>();
		if (GetGame() && GetGame().IsClient() && rl_player_list) {
			rl_player_list.Insert(this);
			RLLogger.Debug("PlayerBase Added: " + this.GetIdentity().GetName() + " to List. List Size: " + rl_player_list.Count(), "AdvancedGroups");
		}
		RegisterNetSyncVariableInt("identityIdHash");
		RegisterNetSyncVariableInt("steamidHash");
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Call(ReinitSafezoneMarker);
	}

	override void OnVariablesSynchronized() {
		super.OnVariablesSynchronized();
		if (lastSteamidHash != steamidHash) {
			ReinitSafezoneMarker();
		}
	}
	
	static void ReinitAllSafezoneMarkers() {
		if (!GetGame().IsClient())
			return;
		foreach (PlayerBase pb : rl_player_list) {
			pb.ReinitSafezoneMarker();
		}
	}
	
	void ReinitSafezoneMarker() {
		lastSteamidHash = steamidHash;
		if (safezoneMarker) {
			delete safezoneMarker;
		}
		if (GetGame().IsClient() && GetIdentity() && GetIdentity().GetName().Length() > 0) {
			safezoneMarker = RLSafezoneMarker.CreateSafezoneMarker(this);
		}
	}
	
	void ~PlayerBase() {
		if (GetGame() && GetGame().IsClient() && rl_player_list) {
				rl_player_list.RemoveItem(this);
				RLLogger.Debug("PlayerBase Remove: " + this.GetIdentity().GetName() + " to List. List Size: " + rl_player_list.Count(), "AdvancedGroups");
		}
		if (safezoneMarker) {
			delete safezoneMarker;
		}
	}

	ref RLGroup rlgroup;
	ref RLGroupMember memberCache;
	
	RLGroup GetRLGroup() {
		return rlgroup;
	}
	
	bool IsInMyRLGroup(bool mustBeVisible = false) {
		PlayerBase me = PlayerBase.Cast(GetGame().GetPlayer());
		if (!me)
			return true;
		if (!me.GetRLGroup() || me == this)
			return false;
		foreach (RLGroupMember member : me.GetRLGroup().members) {
			if (member.clientPBFound == this) {
				if (!mustBeVisible || member.IsMainWidgetVisible())
					return true;
			}
		}
		
		return false;
	}
	
	RLGroupPermission GetPermission() {
		RLGroupMember member = GetMyGroupMarker();
		if (!member)
			return null;
		return RLGroupPermissions.Get.FindPermissionGroupByUID(member.permissionGroup);
	}
	
	RLGroupMember GetMyGroupMarker(string steamid = "") {
		if (!GetRLGroup()) {
			RLLogger.Debug("No Group Found", "AdvancedGroups");
			return null;
		}
		if (memberCache)
			return memberCache;
		if (steamid == "")
			steamid = GetMySteamId();
		RLGroupMember member = GetRLGroup().GetMemberBySteamid(steamid);
		memberCache = member;
		RLLogger.Debug("Steamid: " + steamid + " Member: " + member, "AdvancedGroups");
		return member;
	}
	
	int GetMySubGroup() {
		RLGroupMember memb = GetMyGroupMarker();
		if (memb)
			return memb.currentSubgroup;
		return -1;
	}
	
	string GetMySteamId() {
		if (GetGame().IsServer()) {
			if (!GetIdentity())
				return "";
			return GetIdentity().GetPlainId();
		} else {
			return RLAdmins.Get().GetMySteamid();
		}
	}
	
	void SetRLGroup(RLGroup grp) {
		if (GetGame().IsClient()) {
			if (rlgroup)
				delete rlgroup;
		}
		rlgroup = grp;
		OnGroupChanged();
	}
	
	void OnGroupChanged() {
		memberCache = null;
		MissionBaseWorld mission = MissionBaseWorld.Cast(GetGame().GetMission());
		if (mission)
			mission.OnGroupChanged();
	}
	
	void AddSimpleClientMarker(string name, string icon, vector position, int color, string creatorId = "Server") {
		if (!GetGame().IsServer() || !GetIdentity())
			return;
		ScriptRPC rpc = new ScriptRPC();
		rpc.Write(name);
		rpc.Write(icon);
		rpc.Write(position);
		rpc.Write(color);
		rpc.Write(creatorId);
		rpc.Send(null, RLGroupRPCs.GROUP_ADD_CLIENT_MARKER, true, GetIdentity());
	}
	
	void OnTeamkilled(PlayerBase killer, EntityAI source) {
		// This can be overwritten by any mod to see when a player was teamkilled to give them some kind of penalty. The method is called serverside only and called on the victim PlayerBase object
	}
	
	override void OnRPC(PlayerIdentity sender, int rpc_type, ParamsReadContext ctx) {
		super.OnRPC(sender, rpc_type, ctx);
		if (rpc_type == RLGroupRPCs.GROUP_SYNC) {

			bool has = false;
			if (!ctx.Read(has))
				return;
			if (!has) {
				SetRLGroup(null);
				return;
			}
			RLGroup grp = new RLGroup();
			if (!grp.ReadFromCtx(ctx)) {
				RLLogger.Error("Failed to receive Group from Server !", "AdvancedGroups");
				return;
			}
			RLLogger.Debug("Successfully received Group from Server", "AdvancedGroups");
			SetRLGroup(grp);
			grp.InitMarkers();
		} else if (rpc_type == RLGroupRPCs.GROUP_INVITE) {
			Param1<string> shortnameParam;
			if (!ctx.Read(shortnameParam)) {
				RLLogger.Error("Failed to receive Shortname of Group Invite !", "AdvancedGroups");
				return;
			}
			MissionBaseWorld mission = MissionBaseWorld.Cast(GetGame().GetMission());
			mission.lastInvite = shortnameParam.param1;
			RLLogger.Debug("Recevied Invite to Group " + mission.lastInvite, "AdvancedGroups");
			mission.OnInviteReceived();
		}
	}
	
	override void SetActionsRemoteTarget( out TInputActionMap InputActionMap) {
		super.SetActionsRemoteTarget(InputActionMap);
		//AddAction(ActionInvitePlayerToGroup, InputActionMap);
	}

}