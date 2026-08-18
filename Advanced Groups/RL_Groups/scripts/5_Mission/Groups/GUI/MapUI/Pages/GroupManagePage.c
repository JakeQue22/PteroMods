class RLGroupManagePage : RLGroupPage {
	
	EditBoxWidget searchbox;
	ButtonWidget buttonInvite;
	ButtonWidget btn_kick, btn_leave, btn_upgrade, btn_promote, btn_demote, btn_joinSubgroup;
	TextWidget txt_groupname;
	TextListboxWidget playerlist_online;
	Widget playerlist_members;
	private ref RLGroupManagerPlayerList playerListManager;

	void RLGroupManagePage() {
		RLLayoutConfig.Event_StreamerModeChanged.Insert(OnStreamerModeChange);
	}
	
	void ~RLGroupManagePage() {
		if (RLLayoutConfig.Event_StreamerModeChanged)
			RLLayoutConfig.Event_StreamerModeChanged.Remove(OnStreamerModeChange);
	}
	
	void OnStreamerModeChange(bool enabled) {
		playerlist_online.Show(!enabled);
		txt_groupname.Show(!enabled);
	}
	
	override bool InitPage(RLGroupUI parentUI) {
		bool worked = super.InitPage(parentUI, 1, 1, "#rl_page_group", false);
		if (buttonWidget)
			parentUI.groupButton = buttonWidget;
		return worked;
	}
	
	override void StoreAllWidgetData(RLDataSerializer data) {
		data.Write(new Param1<string>(searchbox.GetText()));
	}
	
	override void RestoreAllWidgetData(RLDataSerializer data) {
		Param1<string> searchParam = Param1<string>.Cast(data.Read());
		searchbox.SetText(searchParam.param1);
	}
	
	override bool OnTopButtonClicked(Widget w) {
		if (super.OnTopButtonClicked(w)) {
			PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
			if (!pb)
				return false;
			return pb.GetRLGroup() != null;
		}
		return false;
	}
	
	override bool CanDisplayButton() {
		return RLGroupMainConfig.Get.IsGroupCreationEnabled();
	}
	
	override void OnShow() {
		super.OnShow();
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (txt_groupname && pb)
			txt_groupname.SetText(pb.GetRLGroup().name + " (" + pb.GetRLGroup().members.Count() + "/" + pb.GetRLGroup().maxPlayers + ")");
		CreatePlayerListManager();
		FillOnlinePlayersList();
		FillMembersList();
		OnStreamerModeChange(RLLayoutConfig.Get().streamerModeEnabled);

	}
	
	override void OnUpdateSlow() {
		FillOnlinePlayersList();
	}
	
	override bool OnClick(Widget w) {
		PlayerBase pb;
		if (w == buttonInvite) {
			Param1<string> steamIdParam;
			int row = playerlist_online.GetSelectedRow();
			if (row < 0 || row >= playerlist_online.GetNumItems())
				return true;
			playerlist_online.GetItemData(row, 0, steamIdParam);
			if (!steamIdParam)
				return true;
			string steamid = steamIdParam.param1;
			pb = PlayerBase.Cast(GetGame().GetPlayer());
			if (!pb || !pb.GetRLGroup())
				return true;
			pb.GetRLGroup().SendPlayerInviteClient(steamid);
			return true;
		} else if (w == btn_joinSubgroup) {
			JoinSubGroup(GetSelectedSubGroup());
			return true;
		} else if (w == btn_leave) {
			RLWarningPopup.Get().Show("Leave", "#ag_warn_group_leave", ScriptCaller.Create(LeaveCurrentGroup));
			return true;
		} else if (w == btn_promote) {
			PromoteSelectedPlayer();
			return true;
		} else if (w == btn_demote) {
			DemoteSelectedPlayer();
			return true;
		} else if (w == btn_kick) {
			RLWarningPopup.Get().Show("Kick", "Are you sure you want to kick the player ?", ScriptCaller.Create(KickSelectedPlayer));
			return true;
		} else if (w == btn_upgrade) {
			pb = PlayerBase.Cast(GetGame().GetPlayer());
			if (!pb || !pb.GetRLGroup())
				return true;
			RLWarningPopup.Get().Show("Upgrade" , "Are you sure you want to upgrade the group for " + RLCurrencyConfig.Get.GetFormattedMoneyString(pb.GetRLGroup().GetUpgradeCost()), ScriptCaller.Create(UgradeGroup));
			return true;
		}
		return false;
	}
	
	void UgradeGroup() {
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup())
			return;
		pb.GetRLGroup().UpgradeGroupClient();
	}
	
	void LeaveCurrentGroup() {
		RLLogger.Verbose("Leaving current group...", "AdvancedGroups");
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup())
			return;
		pb.GetRLGroup().LeaveGroupClient();
	}
	
	override bool OnChange(Widget w) {
		if (w == searchbox) {
			FillOnlinePlayersList();
			return true;
		}
		return false;
	}
	override bool OnItemSelected(Widget w, int row, int column) {
		if (w == playerlist_online) {
			UpdateInviteButton();
			return true;
		}
		return false;
	}
	
	override void OnSizeChange() {
		if (playerListManager)
			playerListManager.OnSizeChange();
	}
	
	void UpdateInviteButton() {
		int row = playerlist_online.GetSelectedRow();
		if (row < 0 || row >= playerlist_online.GetNumItems()) {
			buttonInvite.Enable(false);
			return;
		}
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup()) {
			buttonInvite.Enable(true);
			return;
		}
		RLGroupPermission myPerm = pb.GetPermission();
		if (!myPerm || !myPerm.canInvite) {
			buttonInvite.Enable(false);
			return;
		}
		buttonInvite.Enable(true);
	}
	
	void UpdateGroupButtons() {
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!mission || !pb)
			return;
		RLGroupPermission myPerms = pb.GetPermission();
		if (!myPerms)
			return;
		btn_upgrade.Enable(myPerms.canUpgrade);
		int selected = playerListManager.GetSelectedRow();
		btn_joinSubgroup.Enable(false);
		btn_upgrade.Enable(pb.GetRLGroup().GetUpgradeCost() >= 0);
		if (selected < 0 || selected >= playerListManager.GetItemCount()) {
			//Print("UpdateGroupButtons Out of Range");
			btn_kick.Enable(false);
			btn_promote.Enable(false);
			btn_demote.Enable(false);
		} else {
			RLGroupMember member = playerListManager.GetItemData(selected);
			if (!member) {
				//Print("UpdateGroupButtons No valid Member: " + member);
				int mySubgroup = pb.GetMyGroupMarker().currentSubgroup;
				int groupId = -1;
				if (member)
					groupId = member.currentSubgroup;
				int count = pb.GetRLGroup().GetSubgroupMemberCount(groupId);
				btn_joinSubgroup.Enable(count < pb.GetRLGroup().subGroupSize && groupId != mySubgroup);
				btn_kick.Enable(false);
				btn_promote.Enable(false);
				btn_demote.Enable(false);
			} else if (member.steamid == RLAdmins.Get().GetMySteamid()) {
				//Print("UpdateGroupButtons Selected Me");
				btn_kick.Enable(false);
				btn_promote.Enable(false);
				btn_demote.Enable(false);
			} else {
				RLGroupPermission targetPerm = member.GetPermission();
				//Print("UpdateGroupButtons Checking Target: " + targetPerm.permName + " My Perm: " + myPerms.permName);
				btn_kick.Enable(myPerms.CanKick(targetPerm));
				btn_promote.Enable(myPerms.CanPromote(targetPerm));
				btn_demote.Enable(myPerms.CanDemote(targetPerm));
			}
		}
	}
	
	override void OnGroupChanged() {
		
	}
	
	void FillOnlinePlayersList() {
		RLLogger.Debug("Filling Online Players List...", "AdvancedGroups");
		if (!ClientData.m_PlayerList || !ClientData.m_PlayerList.m_PlayerList)
			return;
		array<ref SyncPlayer> list = ClientData.m_PlayerList.m_PlayerList;
		int items = playerlist_online.GetNumItems();
		int added = 0;
		for (int i = 0; i < list.Count(); i++) {
			SyncPlayer player = list.Get(i);
			string name = player.m_PlayerName;
			string steamid = player.m_UID;
			bool you = false;
			if (RLGroupMainConfig.Get.groupManagePageObfuscatePlayernames) {
				name = RLGroupMainConfig.Get.ObfuscatePlayerName(name, steamid.Hash());
				if (steamid == RLAdmins.Get().GetMySteamid()) {
					name = "YOU: " + name;
					you = true;
				}
			}
			Param1<string> param = new Param1<string>(steamid);
			if (IsSearched(name)) {
				if (you) {
					playerlist_online.AddItem(" " + name, param, 0, 0); // Begin
				} else if (items <= added) {
					playerlist_online.AddItem(" " + name, param, 0); // End after last Element
				} else {
					playerlist_online.SetItem(added, " " + name, param, 0); // Middle to last Element
				}
				added++;
			}
		}
		RLLogger.Debug("Added: " + added + " Items: " + items, "AdvancedGroups");
		while (added < playerlist_online.GetNumItems()) {
			playerlist_online.RemoveRow(added);
		}
		RLLogger.Debug("Left: " + playerlist_online.GetNumItems(), "AdvancedGroups");
	}
	
	bool IsSearched(string name) {
		string lowerName = name + "";
		lowerName.ToLower();
		string lowerSearch = searchbox.GetText();
		lowerSearch.ToLower();
		if (lowerSearch.Length() == 0)
			return true;
		return lowerName.IndexOf(lowerSearch) != -1;
	}
	
	void FillMembersList() {
		RLLogger.Debug("FillMembersList", "AdvancedGroups");
		
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup())
			return;
		RLGroup grp = pb.GetRLGroup();
		int lastSubgroup = 0;
		int subgroupMaxSize = grp.subGroupSize;
		array<ref RLGroupMember> sortedMembers = SortGroupMembers(grp);
		int index = 0;
		foreach (RLGroupMember member : sortedMembers) {
			string addName = "";
			Param3<string, ref RLGroupPermission, int> param;
			if (RLGroupMainConfig.Get.enableSubGroups) {
				while (member.currentSubgroup >= lastSubgroup) {
					param = new Param3<string, ref RLGroupPermission, int>("", null, lastSubgroup);
					int inCount = grp.GetSubgroupMemberCount(lastSubgroup);
					playerListManager.SetEntry(" " + RLGroupMainConfig.Get.subGroupNames.Get(lastSubgroup) + " (" + inCount + "/" + subgroupMaxSize + ")", index);
					lastSubgroup++;
					index++;
				}
				addName = "  ";
			}
			playerListManager.SetEntry(member, index);
			index++;
		}
		if (RLGroupMainConfig.Get.enableSubGroups) {
			while (grp.subGroupCount > lastSubgroup) {
				playerListManager.SetEntry(" " + RLGroupMainConfig.Get.subGroupNames.Get(lastSubgroup) + " (0/" + subgroupMaxSize + ")", index);
				lastSubgroup++;
				index++;
			}
		}
		playerListManager.RemoveLastEntries(index);
		UpdateGroupButtons();
	}
	
	array<ref RLGroupMember> SortGroupMembers(RLGroup grp) {
		if (!RLGroupMainConfig.Get.enableSubGroups)
			return grp.members;
		array<ref RLGroupMember> mem = new array<ref RLGroupMember>();
		int needed = grp.members.Count();
		int i = 0;
		while (mem.Count() < needed) {
			array<ref RLGroupMember> subgrpMembers = grp.GetSubgroupMembers(i++);
			foreach (RLGroupMember member : subgrpMembers)
				mem.Insert(member);
			if (i > RLGroupMainConfig.Get.subGroupNames.Count())
				break;
		}
		return mem;
	}
	
	void PromoteSelectedPlayer() {
		string steamid = GetSelectedPlayerSteamid();
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup())
			return;
		pb.GetRLGroup().PromotePlayerClient(steamid);
	}
	
	void DemoteSelectedPlayer() {
		string steamid = GetSelectedPlayerSteamid();
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup())
			return;
		pb.GetRLGroup().DemotePlayerClient(steamid);
	}
	
	void KickSelectedPlayer() {
		string steamid = GetSelectedPlayerSteamid();
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup())
			return;
		pb.GetRLGroup().KickPlayerClient(steamid);
	}
	
	void JoinSubGroup(int index) {
		if (index < 0)
			return;
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup() || pb.GetMySubGroup() == index)
			return;
		pb.GetRLGroup().JoinSubgroupRequest(index);
	}
	
	void MovePlayerToSubgroup(int markerUid, int index) {
		if (index < 0)
			return;
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup())
			return;
		pb.GetRLGroup().MoveSubgroupRequest(markerUid, index);
		
	}
	
	string GetSelectedPlayerSteamid() {
		int row = playerListManager.GetSelectedRow();
		if (row < 0 || row >= playerListManager.GetItemCount())
			return "";
		RLGroupMember member = playerListManager.GetItemData(row);
		Print("Selected Player: " + row + " is " + member);
		if (member)
			return member.steamid;
		return "";
	}
	
	int GetSelectedSubGroup() {
		int row = playerListManager.GetSelectedRow();
		if (row < 0 || row >= playerListManager.GetItemCount())
			return -1;
		return playerListManager.GetSubgroup(row);
	}
	
	override void OnUpdateFrame() {
		super.OnUpdateFrame();
		FillMembersList();
	}
	
	override void InitMainWidget() {
		RLLogger.Verbose("Init Main Widget for Group Manager Page", "AdvancedGroups");
		ConnectClassWidgetVariables(this, rootWidget, {"rootWidget", "buttonWidget"});
	}
	
	void CreatePlayerListManager() {
		if (playerListManager)
			playerListManager.ClearEntries();
		playerListManager = new RLGroupManagerPlayerList(playerlist_members, this);
	}
}