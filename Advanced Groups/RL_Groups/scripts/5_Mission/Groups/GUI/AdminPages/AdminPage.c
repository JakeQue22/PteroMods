class RLAdminPage : RLAdmin_Menu_Page {
	
	private ref array<ref RLGroup> groups = new array<ref RLGroup>();
	
	EditBoxWidget shortname, name, searchInput;
	TextWidget level, created, lastactive, playercount, markercount, steamid, rank;
	ButtonWidget btnRefresh, btnDemote, btnPromote, btnKick, btnToLeader, btnCopy, btnJoin, btnDelete, btnChangeGroupNames, btnLevelUp, btnLevelDown, btnApplyColor;
	TextListboxWidget groupsList, memberList;
	CheckBoxWidget chckbxShortnames, chckbxNames, chckbxMemberNames, chckbxSteamids, chckbx_show_territorry_flags, chckbx_hasCustomTag;
	SliderWidget sliderR, sliderG, sliderB;
	MapWidget mapWidget;
	Widget chatColorPreview;
	
	const string FLAG_POSITION_ICON = "RayLab_Groups/gui/icons/flag.paa";
	const int FLAG_POSITION_COLOR = ARGB(255,255,255,255);
	ref array<ref Param2<ref vector, string>> flagPositions = new array<ref Param2<ref vector, string>>();

	override void OnShow() {
		super.OnShow();
		RequestGroups();
	}
	override void OnHide() {
		super.OnHide();
		ClearGroups();
	}
	
	override void RegisterAllLinkedVars() {
		linked.RegisterLinkedList("groups", groupsList).SetSearchBar(searchInput, "IsGroupSearched", {chckbxShortnames, chckbxNames, chckbxMemberNames, chckbxSteamids}).SetFunctions("", "", "GetGroupsCount", "GetGroup");
		linked.RegisterLinkedVar("GetSelectedGroup().shortname", shortname).SetLoadOnly(true).SetReloadTrigger(groupsList);
		linked.RegisterLinkedVar("GetSelectedGroup().name", name).SetLoadOnly(true).SetReloadTrigger(groupsList);
		linked.RegisterLinkedVar("GetSelectedGroup().level", level).SetReloadTrigger(groupsList);
		linked.RegisterLinkedVar("GetSelectedGroup().GetLastActiveDate()", lastactive).SetReloadTrigger(groupsList);
		linked.RegisterLinkedVar("GetSelectedGroup().GetCreatedDate()", created).SetReloadTrigger(groupsList);
		linked.RegisterLinkedVar("GetSelectedGroup().GetPlayerCountString()", playercount).SetReloadTrigger(groupsList);
		linked.RegisterLinkedVar("GetSelectedGroup().GetMarkerCountString()", markercount).SetReloadTrigger(groupsList);
		linked.RegisterLinkedVar("GetSelectedGroup().hasCustomChatColor", chckbx_hasCustomTag).SetReloadTrigger(groupsList);
		linked.RegisterLinkedVar("GetSelectedGroup().chatColorR", sliderR).SetChangeTrigger("SetChatColorPreview()").SetReloadTrigger(groupsList);
		linked.RegisterLinkedVar("GetSelectedGroup().chatColorG", sliderG).SetChangeTrigger("SetChatColorPreview()").SetReloadTrigger(groupsList);
		linked.RegisterLinkedVar("GetSelectedGroup().chatColorB", sliderB).SetChangeTrigger("SetChatColorPreview()").SetReloadTrigger(groupsList);
		linked.RegisterLinkedList("GetSelectedGroup().members", memberList).SetFunctions("", "", "GetMemberCount", "GetMemberString").SetLoadFunctions("ClearMarkers", "OnGroupLoad").SetReloadTrigger(groupsList);
		linked.RegisterLinkedVar("GetSelectedGroupMember().steamid", steamid).SetReloadTrigger(memberList);
		linked.RegisterLinkedVar("GetSelectedGroupMember().GetRankString()", rank).SetReloadTrigger(memberList);
		
		ApplyWidgetPermission("btnDelete", "groups.delete");
		ApplyWidgetPermission("btnJoin", "groups.join");
		ApplyWidgetPermission("btnKick", "groups.kick");
		ApplyWidgetPermission("btnLevelUp", "groups.level.change");
		ApplyWidgetPermission("btnLevelDown", "groups.level.change");
		ApplyWidgetPermission("btnChangeGroupNames", "groups.name.change");
		ApplyWidgetPermission("btnDemote", "groups.ranks.change");
		ApplyWidgetPermission("btnPromote", "groups.ranks.change");
		ApplyWidgetPermission("btnToLeader", "groups.ranks.change");
		ApplyWidgetPermission("chckbx_hasCustomTag", "groups.tagcolor.change");
		ApplyWidgetPermission("btnApplyColor", "groups.tagcolor.change");
		ApplyWidgetPermission("sliderR", "groups.tagcolor.change");
		ApplyWidgetPermission("sliderG", "groups.tagcolor.change");
		ApplyWidgetPermission("sliderB", "groups.tagcolor.change");
	}
	
	override string GetLayoutPath() {
		return "RayLab_Groups/gui/layouts/admin/groupOverview.layout";
	}
	
	override string GetButtonName() {
		return "Groups";
	}
	
	override string GetPageShowPermission() {
		return "groups.view";
	}
	
	bool StringContainsLower(string str1, string other) {
		str1.ToLower();
		other.ToLower();
		return str1.Contains(other);
	}
	
	bool IsGroupSearched(int index, string text) {
		RLGroup grp = groups.Get(index);
		if (chckbxShortnames.IsChecked() && StringContainsLower(grp.shortname, text))
			return true;
		if (chckbxNames.IsChecked() && StringContainsLower(grp.name, text))
			return true;
		foreach (RLGroupMember member : grp.members) {
			if (chckbxMemberNames.IsChecked() && StringContainsLower(member.name, text)) {
				return true;
			}
			if (chckbxSteamids.IsChecked() && StringContainsLower(member.steamid, text)) {
				return true;
			}
		}
		return false;
	}
	
	int GetGroupsCount() {
		return groups.Count();
	}
	
	RLGroupMember GetSelectedGroupMember() {
		RLGroup selectedGroup = GetSelectedGroup();
		if (!selectedGroup)
			return null;
		int row = memberList.GetSelectedRow();
		if (row < 0 || row >= selectedGroup.members.Count())
			return null;
		return selectedGroup.members.Get(row);
	}
	
	RLGroup GetSelectedGroup() {
		int row = linked.SearchedIndexToListIndex(searchInput, groupsList.GetSelectedRow());
		if (row < 0 || row >= groups.Count())
			return null;
		return groups.Get(row);
	}
	
	string GetGroup(int index, int column) {
		return groups.Get(index).shortname + " (" + groups.Get(index).name + ")";
	}
	
	override void OnRPC(PlayerIdentity sender, Object target, int rpc_type, ParamsReadContext ctx) {
		if (rpc_type == RLGroupRPCs.GROUP_ADMIN_LIST) {
			OnAllGroupsReceived(ctx);
		} else if (rpc_type == RLGroupRPCs.GROUP_ADMIN_LIST_ADD) {
			OnAllGroupsReceivedAdd(ctx);
		} else if (rpc_type == RLGroupRPCs.GROUP_ADMIN_LIST_SINGLE) {
			OnGroupUpdateReceived(ctx);
		} else if (rpc_type == RLGroupRPCs.GROUP_ADMIN_DELETE) {
			OnGroupDeleteReceived(ctx);
		} else if (rpc_type == RLGroupRPCs.GROUP_ADMIN_FLAGS) {
			OnFlagpolesReceived(ctx);
		}
			
	}
	
	void OnFlagpolesReceived(ParamsReadContext ctx) {
		int count2;
		if (!ctx.Read(count2))
			return;
		array<ref Param2<ref vector, string>> arr = new array<ref Param2<ref vector, string>>();
		RLLogger.Debug("Reading " + count2 + " Flag Positions from the Server ...", "AdvancedGroups");
		for (int i2 = 0; i2 < count2; i2++) {
			bool exists = false;
			if (!ctx.Read(exists))
				return;
			if (!exists)
				continue;
			float x,y,z;
			string name2;
			if (!ctx.Read(x) || !ctx.Read(y) || !ctx.Read(z) || !ctx.Read(name2))
				return;
			arr.Insert(new Param2<ref vector, string>(Vector(x,y,z), name2));
		}
		RLLogger.Debug("Read " + arr.Count() + " valid Flag Positions", "AdvancedGroups");
		SetFlagPositions(arr);
		ShowTerritorryCheckbox();
	}
	
	void ShowTerritorryCheckbox() {
		chckbx_show_territorry_flags.Show(true);
	}
	
	void OnAllGroupsReceived(ParamsReadContext ctx) {
		RLLogger.Debug("OnAllGroupsReceived " + GetGame().GetTime(), "AdvancedGroups");
		ClearGroups();
		OnAllGroupsReceivedAdd(ctx);
	}
	
	void OnAllGroupsReceivedAdd(ParamsReadContext ctx) {
		RLLogger.Debug("OnAllGroupsReceived " + GetGame().GetTime(), "AdvancedGroups");
		if (!layoutRoot.IsVisible())
			return;
		int count = 0;
		if (!ctx.Read(count))
			return;
		for (int i = 0; i < count; i++) {
			RLGroup grp = new RLGroup();
			bool exists = false;
			if (!ctx.Read(exists))
				return;
			if (!exists)
				continue;
			if (!grp.ReadFromCtx(ctx))
				return;
			groups.Insert(grp);
			grp.RemoveMarkersForAdminPage();
		}
		linked.TriggerLoad("groups");
	}
	
	void ClearGroups() {
		foreach (RLGroup grp : groups) {
			delete grp;
		}
		groups.Clear();
	}
	
	void OnGroupUpdateReceived(ParamsReadContext ctx) {
		RLLogger.Debug("OnGroupUpdateReceived " + GetGame().GetTime(), "AdvancedGroups");
		RLGroup grp = new RLGroup();
		string oldShortname;
		if (!ctx.Read(oldShortname))
			return;
		if (!grp.ReadFromCtx(ctx))
			return;
		bool found = false;
		for (int i = 0; i < groups.Count(); i++) {
			if (groups.Get(i).shortname == oldShortname) {
				groups.Set(i, grp);
				found = true;
				break;
			}
		}
		if (!found)
			groups.Insert(grp);
		linked.TriggerLoad("groups");
	}
	
	void SetFlagPositions(array<ref Param2<ref vector, string>> arr) {
		flagPositions = arr;
		DisplayFlagPositions();
	}
	
	void OnGroupLoad() {
		mapWidget.ClearUserMarks();
		RLGroup grp = GetSelectedGroup();
		if (!grp)
			return;
		MarkMemberPositions(grp);
		DisplayGroupMarkers(grp);
	}
	
	void DisplayGroupMarkers(RLGroup grp) {
		foreach (RLMarker marker : grp.markers) {
			mapWidget.AddUserMark(marker.position, marker.name, marker.GetColorARGB(), marker.icon);
		}
	}
	
	void DisplayFlagPositions() {
		if (!chckbx_show_territorry_flags.IsChecked()) {
			OnGroupLoad();
			return;
		}
		mapWidget.ClearUserMarks();
		foreach (Param2<ref vector, string> param : flagPositions) {
			mapWidget.AddUserMark(param.param1, param.param2, FLAG_POSITION_COLOR, FLAG_POSITION_ICON);
		}
	}
	
	void OnGroupDeleteReceived(ParamsReadContext ctx) {
		RLLogger.Debug("OnGroupDeleteReceived " + GetGame().GetTime(), "AdvancedGroups");
		Param1<string> shortnameParam;
		if (!ctx.Read(shortnameParam))
			return;
		string shortname_ = shortnameParam.param1;
		for (int i = 0; i < groups.Count(); i++) {
			if (groups.Get(i).shortname == shortname_) {
				groups.RemoveOrdered(i);
				break;
			}
		}
		linked.TriggerLoad("groups");
	}
	
	void ClearMarkers() {
		mapWidget.ClearUserMarks();
	}
	
	void MarkMemberPositions(RLGroup grp) {
		foreach (RLGroupMember member : grp.members) {
			mapWidget.AddUserMark(member.position, member.name, member.GetColorARGB(), member.icon);
		}
	}
	
	void ChangeGroupName(RLGroup grp) {
		string newName = name.GetText();
		string newShortname = shortname.GetText();
		if (newName == grp.name && newShortname == grp.shortname)
			return;
		ScriptRPC rpc = new ScriptRPC();
		rpc.Write(grp.shortname);
		rpc.Write(newShortname);
		rpc.Write(newName);
		rpc.Send(null, RLGroupRPCs.GROUP_ADMIN_NAMES, true);
	}
	
	void OnMemberSelected(RLGroupMember member) {
		steamid.SetText(member.steamid);
		RLGroupPermission perm = RLGroupPermissions.Get.FindPermissionGroupByUID(member.permissionGroup);
		rank.SetText(perm.permName);
	}
	
	void AddGroupLevel(int level_) {
		RLGroup grp = GetSelectedGroup();
		Print("Adding " + level_ + " level to " + grp);
		if (!grp)
			return;
		if (level_ == 0)
			return;
		level_ += grp.level;
		if (level_ < 0)
			return;
		ScriptRPC rpc = new ScriptRPC();
		rpc.Write(grp.shortname);
		rpc.Write(level_);
		rpc.Send(null, RLGroupRPCs.GROUP_ADMIN_LEVEL, true);
	}
	
	void SetChatColorPreview() {
		chatColorPreview.SetColor(ARGB(255, sliderR.GetCurrent(), sliderG.GetCurrent(), sliderB.GetCurrent()));
	}
	
	override bool OnClick(Widget w, int x, int y, int button) {
		super.OnClick(w, x, y, button);
		
		if (w == btnChangeGroupNames) {
			ChangeGroupName(GetSelectedGroup());
			return true;
		} else if (w == btnLevelDown) {
			AddGroupLevel(-1);
			return true;
		} else if (w == btnLevelUp) {
			AddGroupLevel(1);
			return true;
		} else if (w == btnRefresh) {
			ClearGroups();
			RequestGroups();
			return true;
		} else if (w == btnJoin) {
			ScriptRPC rpc = new ScriptRPC();
			RLGroup grp = GetSelectedGroup();
			if (grp) {
				rpc.Write(grp.shortname);
			} else {
				return true;
			}
			rpc.Send(null, RLGroupRPCs.GROUP_ADMIN_JOIN, true);
			return true;
		} else if (w == btnDelete) {
			grp = GetSelectedGroup();
			if (grp) {
				RLWarningPopup.Get().Show("Delete", "Are you sure you want to delete the Group " + grp.name + " ?", ScriptCaller.Create(DeleteSelectedGroup));
			}
		} else if (w == btnKick) {
			SendSelectedGroupMemberRPC(RLGroupRPCs.GROUP_ADMIN_KICK);
		} else if (w == btnDemote) {
			SendSelectedGroupMemberRPC(RLGroupRPCs.GROUP_ADMIN_DEMOTE);
		} else if (w == btnPromote) {
			SendSelectedGroupMemberRPC(RLGroupRPCs.GROUP_ADMIN_PROMOTE);
		} else if (w == btnToLeader) {
			SendSelectedGroupMemberRPC(RLGroupRPCs.GROUP_ADMIN_TOLEADER);
		} else if (w == btnCopy) {
			CopySelectedUserData();
		} else if (w == chckbx_show_territorry_flags) {
			DisplayFlagPositions();
		} else if (w == btnApplyColor) {
			ApplyChatColorChange();
		}
		return false;
	}
	
	void DeleteSelectedGroup() {
		ScriptRPC rpc = new ScriptRPC();
		RLGroup grp = GetSelectedGroup();
		if (grp) {
			rpc.Write(grp.shortname);
			rpc.Send(null, RLGroupRPCs.GROUP_ADMIN_DELETE, true);
		}
	}
	
	void ApplyChatColorChange() {
		int r = sliderR.GetCurrent();
		int g = sliderG.GetCurrent();
		int b = sliderB.GetCurrent();
		bool hasTag = chckbx_hasCustomTag.IsChecked();
		RLGroup grp = GetSelectedGroup();
		
		if (!grp)
			return;
		string shortname_ = grp.shortname;
		ScriptRPC rpc = new ScriptRPC();
		rpc.Write(shortname_);
		rpc.Write(hasTag);
		rpc.Write(r);
		rpc.Write(g);
		rpc.Write(b);
		rpc.Send(null, RLGroupRPCs.GROUP_ADMIN_CHAT_COLOR, true);
	}
	
	void CopySelectedUserData() {
		RLGroupMember member = GetSelectedGroupMember();
		if (!member)
			return;
		string cop = member.steamid + " " + member.name;
		GetGame().CopyToClipboard(cop);
		NotificationSystem.AddNotificationExtended(4, "#rl_message_groupSystem", "#rl_copied_user_details", "set:ccgui_enforce image:HudUserMarker");
	}
	
	void SendSelectedGroupMemberRPC(int rpc_type) {
		ScriptRPC rpc = new ScriptRPC();
		RLGroup grp = GetSelectedGroup();
		string steamid_;
		if (!grp || !GetSelectedPlayerSteamId(steamid_))
			return;
		string shortname_ = grp.shortname;
		rpc.Write(shortname_);
		rpc.Write(steamid_);
		rpc.Send(null, rpc_type, true);
	}
	
	bool GetSelectedPlayerSteamId(out string steamid_) {
		RLGroupMember member = GetSelectedGroupMember();
		if (!member)
			return false;
		steamid_ = member.steamid;
		return true;
	}
	
	void RequestGroups() {
		if (!RLAdmins.Get().HasPermission("groups.view")) {
			return;
		}
		RLLogger.Debug("Requesting all Groups:  " + GetGame().GetTime(), "AdvancedGroups");
		GetGame().RPCSingleParam(null, RLGroupRPCs.GROUP_ADMIN_LIST, new Param1<bool>(true), true);
		GetGame().RPCSingleParam(null, RLGroupRPCs.GROUP_ADMIN_FLAGS, new Param1<bool>(true), true);
	}
	
}