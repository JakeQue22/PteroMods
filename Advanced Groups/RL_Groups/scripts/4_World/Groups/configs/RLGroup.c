class RLGroup {

	static int CONFIG_VERSION = 1;
	int configVersion = 0;
	string name, shortname;
	int level;
	int maxPlayers;
	int subGroupSize;
	int subGroupCount;
	int lastActivity = -1;
	int creationDate = -1;
	int markerLimit = 0;
	int plotpoleLimit = 0;
	bool hasATMAccount = false;
	bool showTagInChat = true;
	bool hasCustomChatColor = false;
	int chatColorR, chatColorG, chatColorB;
	int ATMBalance = 0;
	ref array<ref RLGroupMember> members = new array<ref RLGroupMember>();
	ref array<ref RLMarker> markers = new array<ref RLMarker>();
	[NonSerialized()]
	ref array<ref RLMarker> pings = new array<ref RLMarker>();
	[NonSerialized()]
	int groupUpgradeCost = 0;
	
	void OnLoadServer() {
		if (configVersion != CONFIG_VERSION) {
			if (configVersion < 1) {
				showTagInChat = true;
			}
		}
		configVersion = CONFIG_VERSION;
	}
	
	void ~RLGroup() {
		if (!GetGame() || GetGame().IsServer())
			return;
		while (members.Get(0)) {
			delete members.Get(0);
			members.Remove(0);
		}
		while (markers.Get(0)) {
			delete markers.Get(0);
			markers.Remove(0);
		}
		while (pings.Get(0)) {
			delete pings.Get(0);
			pings.Remove(0);
		}
	}
	
	int GetTagHash() {
		return RLStringTools.ToLowerString(shortname).Hash();
	}
	
	void InitMarkers() {
		foreach (RLMarker marker : markers) {
			if (marker)
				marker.InitMarker();
		}
		foreach (RLGroupMember member : members) {
			if (member)
				member.InitMarker();
		}
		foreach (RLMarker ping : pings) {
			if (ping)
				ping.InitMarker();
		}
	}
	
	void RemoveMarkersForAdminPage() {
		foreach (RLMarker marker : markers) {
			if (marker)
				marker.RemoveFromAllList();
		}
		foreach (RLGroupMember member : members) {
			if (member)
				member.RemoveFromAllList();
		}
		foreach (RLMarker ping : pings) {
			if (ping)
				ping.RemoveFromAllList();
		}
	}
	
	void OnRPCClient(int type, ParamsReadContext ctx) {
		if (type == RLGroupRPCs.ADD) {
			RLMarker marker = new RLMarker();
			if (!marker.ReadFromCtx(ctx)) {
				RLLogger.Debug("Failed to receive new Marker from Server !", "AdvancedGroups");
				return;
			}
			AddMarkerLocal(marker);
		} else if (type == RLGroupRPCs.REMOVE) {
			int uid;
			if (!ctx.Read(uid)) {
				RLLogger.Debug("Failed to receive new Marker ID from Server !", "AdvancedGroups");
				return;
			}
			RLMarker mark = FindAnyMarkerByUID(uid);
			if (mark) {
				RemoveMarkerLocal(mark);
			}
		} else if (type == RLGroupRPCs.CHANGE_TAG_VISIBILITY) {
			bool enabled;
			if (!ctx.Read(enabled)) {
				RLLogger.Debug("Failed to receive Tag Visibility from Server !", "AdvancedGroups");
				return;
			}
			showTagInChat = enabled;
		} else if (type == RLGroupRPCs.ADD_CLIENT) {
			RLGroupMember member = new RLGroupMember();
			if (!member.ReadFromCtx(ctx)) {
				RLLogger.Debug("Failed to receive new Member from Server !", "AdvancedGroups");
				return;
			}
			AddMarkerLocal(member);
		} else if (type == RLGroupRPCs.UPGRADE) {
			int level1, maxPlayers1, subGroupCount1, subGroupSize1, maxMarkers1, plotpoleLimit1, upgradeCost1;
			if (!ctx.Read(level1) || !ctx.Read(maxPlayers1) || !ctx.Read(subGroupCount1) || !ctx.Read(subGroupSize1) || !ctx.Read(maxMarkers1) || !ctx.Read(plotpoleLimit1) || !ctx.Read(upgradeCost1)) {
				RLLogger.Debug("Failed to Read Upgrade Info", "AdvancedGroups");
				return;
			}
			level = level1;
			maxPlayers = maxPlayers1;
			subGroupCount = subGroupCount1;
			subGroupSize = subGroupSize1;
			markerLimit = maxMarkers1;
			plotpoleLimit = plotpoleLimit1;
			groupUpgradeCost = upgradeCost1;
			MissionBaseWorld mission = MissionBaseWorld.Cast(GetGame().GetMission());
			if (mission)
				mission.OnGroupChanged();
		} else if (type == RLGroupRPCs.ATM_ACCOUNT_CHANGED) {
			bool hasAcc;
			if (!ctx.Read(hasAcc)) {
				RLLogger.Debug("Failed to get HasGroupATMAccount info !", "AdvancedGroups");
				return;
			}
			RLLogger.Debug("Successfully read HasGroupATMAccount. Now: " + hasAcc, "AdvancedGroups");
			this.hasATMAccount = hasAcc;
			mission = MissionBaseWorld.Cast(GetGame().GetMission());
			if (mission)
				mission.OnGroupChanged();
		} else if (type == RLGroupRPCs.ATM_BALANCE_CHANGED) {
			int money;
			if (!ctx.Read(money)) {
				RLLogger.Debug("Failed to get money info !", "AdvancedGroups");
				return;
			}
			RLLogger.Debug("Successfully read money. Now: " + money, "AdvancedGroups");
			this.ATMBalance = money;
			mission = MissionBaseWorld.Cast(GetGame().GetMission());
			if (mission)
				mission.OnGroupChanged();
		} else if (type > RLGroupRPCs.START_MARKER_RPC) {
			int uid2 = 0;
			if (!ctx.Read(uid2))
				return;
			RLLogger.Debug("Group RPC for MArker: " + type + " UID: " + uid2, "AdvancedGroups");
			RLMarker marker2 = FindAnyMarkerByUID(uid2);
			if (marker2)
				marker2.OnMarkerRPCClient(type, ctx);
		}
	}
	
	void KickPlayerClient(string steamid) {
		PromoteDemoteKickHelpter(steamid, RLGroupRPCs.KICK);
	}
	
	void DemotePlayerClient(string steamid) {
		PromoteDemoteKickHelpter(steamid, RLGroupRPCs.DEMOTE);
	}
	
	void PromotePlayerClient(string steamid) {
		PromoteDemoteKickHelpter(steamid, RLGroupRPCs.PROMOTE);
	}
	
	void PromoteDemoteKickHelpter(string steamid, int type) {
		ScriptRPC rpc = CreateRPCCall(type);
		rpc.Write(steamid);
		SendRPCToServer(rpc);
	}
	
	void LeaveGroupClient() {
		ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.LEAVE);
		SendRPCToServer(rpc);
	}
	
	void UpgradeGroupClient() {
		ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.UPGRADE);
		SendRPCToServer(rpc);
	}
	
	int GetUpgradeCost() {
		return groupUpgradeCost;
	}
	
	RLGroupMember GetMemberBySteamid(string steamid) {
		foreach (RLGroupMember member : members) {
			if (member.steamid == steamid)
				return member;
		}
		return null;
	}
	
	RLGroupMember GetDisabledMemberBySteamid(string steamid) {
		foreach (RLGroupMember member : members) {
			if (member.steamid == RLGroupMember.DISABLED_STEAMID_PREFIX + steamid)
				return member;
		}
		return null;
	}
	
	array<PlayerBase> GetOnlineMembersPlayerCharacters() {
		return null;
	}
	
	bool IsMember(string steamid) {
		return GetMemberBySteamid(steamid) != null;
	}
	
	bool AddMember(PlayerBase player) {
		if (player.GetRLGroup() != null)
			return false;
		return true;
	}
	
	void RemoveMember(PlayerBase player) {
		if (!player || !player.GetIdentity())
			return;
		string steamid = player.GetIdentity().GetPlainId();
		RemoveMember(steamid);
	}
	
	void RemoveMember(string steamid) {
		RLGroupMember member = GetMemberBySteamid(steamid);
		if (member == null)
			return;
		RemoveMember(member);
	}
	
	void RemoveMember(RLGroupMember member) {
		int uidd = member.uid;
		for (int i = 0; i < members.Count(); i++) {
			RLGroupMember mem = members.Get(i);
			if (!mem || mem.uid == uidd) {
				members.Remove(i);
				i--;
				if (mem)
					delete mem;
			}
		}
	}
	
	string GetLastActiveDate() {
		return RLDate.Init(lastActivity).ToFormattedString();
	}
	
	string GetCreatedDate() {
		return RLDate.Init(creationDate).ToFormattedString();
	}
	
	int GetMemberCount() {
		return members.Count();
	}
	
	string GetMemberString(int index, int column) {
		return members.Get(index).name;
	}
	
	int GetSubgroupMemberCount(int subGroup) {
		return GetSubgroupMembers(subGroup).Count();
	}
	
	void JoinSubgroupRequest(int grp) {
		ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.JOIN_SUBGROUP);
		rpc.Write(grp);
		SendRPCToServer(rpc);
	}
	
	void MoveSubgroupRequest(int markerUid, int grp) {
		ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.MOVE_SUBGROUP);
		rpc.Write(markerUid);
		rpc.Write(grp);
		SendRPCToServer(rpc);
	}
	
	bool IsPersistent() {
		return false;
	}
	
	array<ref RLGroupMember> GetSubgroupMembers(int subGroup) {
		if (!RLGroupMainConfig.Get.enableSubGroups)
			return members;
		array<ref RLGroupMember> memb = new array<ref RLGroupMember>();
		foreach (RLGroupMember member : members) {
			if (member.currentSubgroup == subGroup)
				memb.Insert(member);
		}
		return memb;
	}
	
	void WriteToCtx(ParamsWriteContext ctx, bool steamids_ = true, bool markers_ = true, bool positions_ = true) {
		ctx.Write(name);
		ctx.Write(shortname);
		ctx.Write(members.Count());
		RLLogger.Verbose("Writing Group to RPC. Members: " + members.Count() + " Markers: " + markers.Count() + " Pings: " + pings.Count() + " See Steamids: " + steamids_ + " See Markers: " + markers_ + " See Player Positions: " + positions_, "AdvancedGroups");
		foreach (RLGroupMember member : members) {
			member.WriteToCtx(ctx, steamids_, positions_);
		}
		if (markers_) {
			RLLogger.Verbose("Writing " + markers.Count() + " markers", "AdvancedGroups");
			ctx.Write(markers.Count());
			foreach (RLMarker marker : markers) {
				marker.WriteToCtx(ctx);
			}
			RLLogger.Verbose("Writing " + pings.Count() + " pings", "AdvancedGroups");
			ctx.Write(pings.Count());
			foreach (RLMarker ping : pings) {
				ping.WriteToCtx(ctx);
			}
		} else {
			RLLogger.Verbose("Writing no markers", "AdvancedGroups");
			ctx.Write(0);
			ctx.Write(0);
		}
		ctx.Write(level);
		ctx.Write(maxPlayers);
		ctx.Write(subGroupSize);
		ctx.Write(subGroupCount);
		ctx.Write(markerLimit);
		ctx.Write(plotpoleLimit);
		ctx.Write(showTagInChat);
		ctx.Write(creationDate);
		ctx.Write(lastActivity);
		ctx.Write(hasATMAccount);
		ctx.Write(ATMBalance);
		ctx.Write(hasCustomChatColor);
		ctx.Write(chatColorR);
		ctx.Write(chatColorG);
		ctx.Write(chatColorB);
		ctx.Write(groupUpgradeCost);
	}
	
	bool ReadFromCtx(ParamsReadContext ctx) {
		if (!ctx.Read(name))
			return false;
		if (!ctx.Read(shortname))
			return false;
		int count = 0;
		if (!ctx.Read(count))
			return false;
		for (int i = 0; i < count; i++) {
			RLGroupMember member = new RLGroupMember();
			if (!member.ReadFromCtx(ctx))
				return false;
			members.Insert(member);
		}
		count = 0;
		if (!ctx.Read(count))
			return false;
		for (i = 0; i < count; i++) {
			RLMarker marker = new RLMarker();
			if (!marker.ReadFromCtx(ctx))
				return false;
			markers.Insert(marker);
		}
		count = 0;
		if (!ctx.Read(count))
			return false;
		for (i = 0; i < count; i++) {
			marker = new RLMarker();
			if (!marker.ReadFromCtx(ctx))
				return false;
			pings.Insert(marker);
		}
		if (!ctx.Read(level))
			return false;
		if (!ctx.Read(maxPlayers))
			return false;
		if (!ctx.Read(subGroupSize))
			return false;
		if (!ctx.Read(subGroupCount))
			return false;
		if (!ctx.Read(markerLimit))
			return false;
		if (!ctx.Read(plotpoleLimit))
			return false;
		if (!ctx.Read(showTagInChat))
			return false;
		if (!ctx.Read(creationDate))
			return false;
		if (!ctx.Read(lastActivity))
			return false;
		if (!ctx.Read(hasATMAccount))
			return false;
		if (!ctx.Read(ATMBalance))
			return false;
		if (!ctx.Read(hasCustomChatColor))
			return false;
		if (!ctx.Read(chatColorR))
			return false;
		if (!ctx.Read(chatColorG))
			return false;
		if (!ctx.Read(chatColorB))
			return false;
		if (!ctx.Read(groupUpgradeCost))
			return false;
		return true;
	}
	
	void AddMarker(RLMarker marker) {
		if (markers.Count() >= markerLimit && marker.type != RLMarkerType.GROUP_PING) {
			SendErrorNotificationLOCAL("#rl_message_markerLimitReached");
			return;
		}
		RLLogger.Debug("Sending Add Marker request to Server ...", "AdvancedGroups");
		ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.ADD);
		marker.WriteToCtx(rpc);
		SendRPCToServer(rpc);
	}
	
	void ClearPing() {
		ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.CLEAR_PING);
		SendRPCToServer(rpc);
	}
	
	void SendErrorNotificationLOCAL(string message, float show_time = 4) {
		NotificationSystem.AddNotificationExtended(show_time, "#rl_message_groupSystem", message, "set:ccgui_enforce image:MapDestroyed");
	}
	
	void SendInfoNotificationLOCAL(string message, float show_time = 4) {
		NotificationSystem.AddNotificationExtended(show_time, "#rl_message_groupSystem", message, "set:ccgui_enforce image:HudUserMarker");
	}
	
	void RemoveMarker(RLMarker marker) {
		ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.REMOVE);
		rpc.Write(marker.uid);
		SendRPCToServer(rpc);
	}
	
	void SendRPCToServer(ScriptRPC rpc) {
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb)
			return;
		rpc.Send(pb, RLGroupRPCs.GROUP_RPC, true);
	}
	
	ScriptRPC CreateRPCCall(int type) {
		ScriptRPC rpc = new ScriptRPC();
		rpc.Write(type);
		return rpc;
	}
	
	void SendChatTagVisibilityRequest(bool visible) {
		ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.CHANGE_TAG_VISIBILITY);
		rpc.Write(visible);
		SendRPCToServer(rpc);
	}
	
	void SendPlayerInviteClient(string steamid) {
		ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.INVITE);
		rpc.Write(steamid);
		SendRPCToServer(rpc);
	}
	
	void AddMarkerLocal(RLMarker marker) {
		if (marker.type == RLMarkerType.GROUP_PING) {
			pings.Insert(marker);
			marker.parentGroup = this;
			marker.InitMarker();
		} else if (marker.type == RLMarkerType.GROUP_MARKER) {
			markers.Insert(marker);
			marker.parentGroup = this;
			marker.InitMarker();
		} else if (marker.type == RLMarkerType.GROUP_PLAYER_MARKER) {
			RLGroupMember member = RLGroupMember.Cast(marker);
			if (!member)
				return;
			members.Insert(member);
			member.parentGroup = this;
			member.InitMarker();
		} else {
			RLLogger.Debug("Trying to add Marker to Group which is no Group Marker type. Type Got: " + marker.type, "AdvancedGroups");
		}
		
	}
	
	void RemoveMarkerLocal(RLMarker marker) {
		if (marker.type == RLMarkerType.GROUP_PING) {
			for (int i = 0; i < pings.Count(); i++) {
				RLMarker mark = pings.Get(i);
				if (mark && mark.uid == marker.uid) {
					pings.RemoveOrdered(i);
					i--;
					delete mark;
					if (!marker)
						break;
				}
			}
		} else if (marker.type == RLMarkerType.GROUP_MARKER) {
			for (i = 0; i < markers.Count(); i++) {
				mark = markers.Get(i);
				if (mark && mark.uid == marker.uid) {
					markers.Remove(i);
					i--;
					delete mark;
					if (!marker)
						break;
				}
			}
		} else if (marker.type == RLMarkerType.GROUP_PLAYER_MARKER) {
			RLGroupMember member = RLGroupMember.Cast(marker);
			if (!member)
				return;
			RemoveMember(member);
		} else {
			RLLogger.Debug("Trying to remove Marker form Group which is no Group Marker type. Type Got: " + marker.type, "AdvancedGroups");
		}
	}
	
	RLMarker FindMarkerByUID(int uid) {
		foreach (RLMarker marker : markers) {
			if (marker.uid == uid)
				return marker;
		}
		return null;
	}
	
	RLMarker FindPingMarkerByUID(int uid) {
		foreach (RLMarker marker : pings) {
			if (marker.uid == uid)
				return marker;
		}
		return null;
	}
	
	RLGroupMember FindMemberByUID(int uid) {
		foreach (RLGroupMember member : members) {
			if (member.uid == uid)
				return member;
		}
		return null;
	}
	
	RLMarker FindAnyMarkerByUID(int uid) {
		RLLogger.Debug("Finding any Marker with UID: " + uid + " Markers: " + markers.Count() + " Members: " + members.Count() + " Pings: " + pings.Count(), "AdvancedGroups");
		RLMarker marker = FindMarkerByUID(uid);
		if (!marker)
			marker = FindMemberByUID(uid);
		if (!marker)
			marker = FindPingMarkerByUID(uid);
		RLLogger.Debug("Found Marker: " + marker, "AdvancedGroups");
		return marker;
	}
	
	bool FindNearestMarker(vector position, out RLMarker markero, out float distance) {
		if (markers.Count() == 0)
			return false;
		float bestDist = 0;
		RLMarker bestMarker = null;
		foreach (RLMarker marker : markers) {
			if (!bestMarker || vector.Distance(position, marker.position) < bestDist) {
				bestMarker = marker;
				bestDist = vector.Distance(position, marker.position) < bestDist;
			}
		}
		if (bestMarker) {
			markero = bestMarker;
			distance = bestDist;
			return true;
		}
		return false;
	}
	
	RLMarker AddGroupMarker(string name_, vector position, string icon, int color, string creatorId = "Server") {
		return null; // Implemented Serverside
	}
	
	bool RemoveGroupMarker(int uid) {
		return false;
	}
	
	bool RemoveGroupMarker(RLMarker marker) {
		return false;
	}
	
	void SendATMBalanceChanged() {
		ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.ATM_BALANCE_CHANGED);
		rpc.Write(this.ATMBalance);
		SendRPCToGroupMembers(rpc);
	}
	
	void SendATMAccountChanged() {
		ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.ATM_ACCOUNT_CHANGED);
		rpc.Write(this.hasATMAccount);
		SendRPCToGroupMembers(rpc);
	}
	
	void SendRPCToGroupMembers(ScriptRPC rpc, int otherRPCType = -1) {}
	void InitNumbers() {}

}