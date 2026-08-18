class RLMarkerList {

	Widget listWidget;
	
	ref array<ref RLMarkerListEntry> entries = new array<ref RLMarkerListEntry>();
	
	void CreateEntries(RLGroupUI groupUI) {
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb)
			return;
		RLGroup grp = pb.GetRLGroup();
		if (!RLLayoutConfig.Get().streamerModeEnabled) {
			AddSpacer("Server Marker", RLMarkerType.SERVER_STATIC);
			foreach (RLServerMarker serverMarker : RLStaticMarkerManagerClient.Get().staticMarkers) {
				AddEntry(serverMarker, groupUI);
			}
		}
		if (grp) {
			RLGroupPermission perm = pb.GetPermission();
			if (perm && perm.CanSeeMarkerType(RLMarkerType.GROUP_MARKER)) {
				AddSpacer("Group Marker", RLMarkerType.GROUP_MARKER);
				foreach (RLMarker groupMarker : grp.markers) {
					AddEntry(groupMarker, groupUI);
				}
			}
			if (perm && perm.CanSeeMarkerType(RLMarkerType.GROUP_PLAYER_MARKER)) {
				AddSpacer("Players", RLMarkerType.GROUP_PLAYER_MARKER);
				foreach (RLGroupMember member : grp.members) {
					AddEntry(member, groupUI);
				}
			}
		}
		AddSpacer("Private Marker", RLMarkerType.PRIVATE_MARKER);
		foreach (RLMarker privateMarker : RLPrivateMarkerManager.Get().privateMarkers) {
			AddEntry(privateMarker, groupUI);
		}
	}
	
	void AddSpacer(string name, RLMarkerType type) {
		RLMarkerListEntry entry = new RLMarkerListEntry();
		entry.InitSpacer(listWidget, name, type);
		entries.Insert(entry);
	}
	
	void AddEntry(RLMarker marker, RLGroupUI groupUI) {
		RLMarkerListEntry entry = new RLMarkerListEntry();
		entry.InitMarker(listWidget, marker, groupUI);
		entries.Insert(entry);
	}
	
	int ShouldUpdateList() {
		if (entries.Count() != 0)
			return 2;
		return 1;
	}
	
	void ClearAndDeleterEntries() {
		foreach (RLMarkerListEntry entry : entries) {
			delete entry;
		}
		entries.Clear();
	}
	
	void UpdateEntries(RLGroupUI groupUI, bool force = false) {
		if (!listWidget)
			return;
		int update = ShouldUpdateList();
		if (update == 1 || force) {
			ClearAndDeleterEntries();
			CreateEntries(groupUI);
		}
		RLLogger.Debug("Updating MarkerList", "AdvancedGroups");
		bool expanded = true;
		foreach (RLMarkerListEntry entry : entries) {
			if (entry.spacer) {
				expanded = entry.expanded;
				entry.Show(true);
				entry.UpdateWidget();
				continue;
			}
			if (expanded) {
				entry.Show(true);
				entry.UpdateWidget();
			} else {
				entry.Show(false);
			}
		}
	}
	
	RLMarkerListEntry OnIconPressed(Widget w) {
		foreach (RLMarkerListEntry entry : entries) {
			if (entry.icon_btn == w)
				return entry;
		}
		return null;
	}
	
	bool OnButtonPressed(Widget w) {
		foreach (RLMarkerListEntry entry : entries) {
			if (entry.OnButtonPressed(w))
				return true;
		}
		return false;
	}
	
}