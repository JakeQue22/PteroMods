class RLPlayerList {

	ref Widget mainWidget;
	ref Widget listWidget;
	int lastMemberHash = 0;
	
	ref array<ref RLPlayerListEntry> entries = new array<ref RLPlayerListEntry>();
	
	static ref RLPlayerList g_RLPlayerList;
	
	static void Delete() {
		if (g_RLPlayerList)
			delete g_RLPlayerList;
	}
	
	static RLPlayerList Get() {
		if (!g_RLPlayerList) {
			g_RLPlayerList = new RLPlayerList();
			g_RLPlayerList.InitWidgets();
		}
		return g_RLPlayerList;
	}
	
	void RLPlayerList() {
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(UpdateEntries, 1000, true, false);
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(UpdateDistances, 200, true);
		RLColorManager.Event_OnColorChange.Insert(OnColorChange);
		RLPositionManager.Event_OnPositionChange.Insert(OnPositionChange);
		RLLayoutConfig.Event_OnLayoutChanged.Insert(OnLayoutChange);
	}
	
	void ~RLPlayerList() {
		if (GetGame() && GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM)) {
			GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Remove(UpdateEntries);
			GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Remove(UpdateDistances);
		}
		if (RLColorManager.Event_OnColorChange)
			RLColorManager.Event_OnColorChange.Remove(OnColorChange);
		if (RLPositionManager.Event_OnPositionChange)
			RLPositionManager.Event_OnPositionChange.Remove(OnPositionChange);
		if (RLLayoutConfig.Event_OnLayoutChanged)
			RLLayoutConfig.Event_OnLayoutChanged.Remove(OnLayoutChange);
		foreach (RLPlayerListEntry entry : entries) {
			delete entry;
		}
		entries.Clear();
		if (mainWidget)
			mainWidget.Unlink();
	}
	
	void OnLayoutChange() {
		UpdateEntries(true);
	}
	
	void OnColorChange() {
		UpdateEntries(true);
	}
	
	void OnPositionChange() {
		UpdatePosition();
	}
	
	void UpdatePosition() {
		RLLogger.Debug("Updating Position of Playerlist", "AdvancedGroups");
		vector pos = RLPositionManager.Get().GetPosition("PlayerList");
		int index = RLPositionManager.Get().GetIndex("PlayerList");
		RLWidgetUtils.SetWidgetAlignmentIndex(listWidget, index);
		RLWidgetUtils.SetWidgetPositionIndex(listWidget, pos, index);
		RLLogger.Debug("Updated Position to: " + pos, "AdvancedGroups");
	}
	
	void UpdateVisibility() {
		if (!GetGame() || !GetGame().GetMission())
			return;
		IngameHud hud = IngameHud.Cast(GetGame().GetMission().GetHud());
		if (!hud || GetGame().GetUIManager().GetMenu()) {
			mainWidget.Show(false);
			return;
		}
		mainWidget.Show(hud.IsHudVisible() && RLMarkerVisibilityManager.Get().playerlistEnabled);
	}
	
	void InitWidgets() {
		if (mainWidget || GetGame().IsServer())
			return;
		mainWidget = RLLayoutManager.Get().CreateLayout("PlayerListParent", "RayLab_Groups/gui/layouts/playerlist/playerlist.layout", null);
		if (mainWidget) {
			listWidget = mainWidget.FindAnyWidget("playerlist");
			RLLogger.Debug("Created Parent Widget for Player List: " + listWidget, "AdvancedGroups");
		}
		RLLogger.Debug("Initialized PlayerList Widget", "AdvancedGroups");
		OnGroupChanged();
	}
	
	void OnGroupChanged() {
		OnColorChange();
		OnPositionChange();
		OnLayoutChange();
	}
	
	void CreateEntries() {
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup())
			return;
		RLGroup grp = pb.GetRLGroup();
		int mySubGroup = pb.GetMyGroupMarker().currentSubgroup;
		array<ref RLGroupMember> members = grp.GetSubgroupMembers(mySubGroup);
		foreach (RLGroupMember member : members) {
			RLPlayerListEntry entry = new RLPlayerListEntry();
			entry.Init(this, member);
			entries.Insert(entry);
			entry.UpdateWidget();
		}
		UpdateDistances();
	}
	
	int ShouldUpdateList() {
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb || !pb.GetRLGroup())
			return 0;
		RLGroup grp = pb.GetRLGroup();
		int mySubGroup = pb.GetMyGroupMarker().currentSubgroup;
		array<ref RLGroupMember> members = grp.GetSubgroupMembers(mySubGroup);
		RLLogger.Verbose("Group Members: " + members.Count(), "AdvancedGroups");
		if (members.Count() != entries.Count())
			return 1;
		int memberHash = 0;
		foreach (RLGroupMember member : grp.members) {
			memberHash += member.CalcHash();
		}
		if (memberHash != lastMemberHash) {
			lastMemberHash = memberHash;
			return 1;
		}
		bool changedHealth = false;
		foreach (RLPlayerListEntry entry : entries) {
			if (!entry || !entry.member || members.Find(entry.member) == -1)
				return 1;
			if (entry.lastHealth != entry.member.health)
				changedHealth = true;
		}
		if (changedHealth)
			return 2;
		return 0;
	}
	
	void ClearAndDeleterEntries() {
		foreach (RLPlayerListEntry entry : entries) {
			delete entry;
		}
		entries.Clear();
	}
	
	void UpdateEntries(bool force = false) {
		RLLogger.Verbose("UpdateEntries. Force ? " + force + " Count: " + entries.Count(), "AdvancedGroups");
		if (!mainWidget)
			return;
		int update = ShouldUpdateList();
		RLLogger.Verbose("Update: " + update, "AdvancedGroups");
		if (update == 0 && !force)
			return;
		if (update == 1 || force) {
			ClearAndDeleterEntries();
			CreateEntries();
		}
		RLLogger.Verbose("Updating PlayerList Widgets. Entry Count: " + entries.Count(), "AdvancedGroups");
		foreach (RLPlayerListEntry entry : entries) {
			entry.UpdateWidget();
		}
		RLLogger.Verbose("Updated all PlayerList Widgets", "AdvancedGroups");
	}
	
	void UpdateDistances() {
		foreach (RLPlayerListEntry entry : entries) {
			entry.UpdateDistance();
		}
	}
	
}
