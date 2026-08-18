class RLMarkerListPage : RLGroupPage {
	
	ref RLMarkerList markerListManger;
		
	void RLMarkerListPage() {
		RLLayoutConfig.Event_StreamerModeChanged.Insert(OnStreamerModeChange);
	}
	
	void ~RLMarkerListPage() {
		if (RLLayoutConfig.Event_StreamerModeChanged)
			RLLayoutConfig.Event_StreamerModeChanged.Remove(OnStreamerModeChange);
	}
	
	void OnStreamerModeChange(bool enabled) {
		markerListManger.UpdateEntries(parent, true);
	}

	override bool InitPage(RLGroupUI parentUI) {
		return super.InitPage(parentUI, 2, 0, "#rl_page_markers", false);
	}
	
	int lastMarkerCount = 0;
	
	override void InitMainWidget() {
		markerListManger = new RLMarkerList();
		GridSpacerWidget marker_list = GridSpacerWidget.Cast(rootWidget.FindAnyWidget("marker_list"));
		markerListManger.listWidget = marker_list;
	}
	
	override void OnUpdateSlow() {
		UpdateMarkerListManager();
	}
	
	override bool OnClick(Widget w) {
		RLMarkerListEntry clicked = markerListManger.OnIconPressed(w);
		if (clicked) {
			parent.mapMarkerManager.MoveToPoint(clicked.marker.position, 0.15);
			return true;
		}
		if (markerListManger.OnButtonPressed(w)) {
			markerListManger.UpdateEntries(parent);
			return true;
		}
		return false;
	}
	
	override void OnMarkerChanged() {
		markerListManger.UpdateEntries(parent, true);
	}
	
	override void OnShow() {
		super.OnShow();
		UpdateMarkerListManager();
		OnStreamerModeChange(RLLayoutConfig.Get().streamerModeEnabled);
	}
	
	override bool CanDisplayButton() {
		return !RLGroupMainConfig.Get.disableMarkerPageOnMap;
	}
	
	void UpdateMarkerListManager() {
		int count = GetMarkerCount();
		RLLogger.Debug("GroupUI: UpdateMarkerListManager " + (markerListManger != null) + " " + count, "AdvancedGroups");
		if (markerListManger)
			markerListManger.UpdateEntries(parent, count != lastMarkerCount);
		lastMarkerCount = count;
	}
	
	int GetMarkerCount() {
		int count = 0;
		count += RLPrivateMarkerManager.Get().privateMarkers.Count();
		count += RLStaticMarkerManagerClient.Get().staticMarkers.Count();
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (pb && pb.GetRLGroup()) {
			count += pb.GetRLGroup().members.Count();
			count += pb.GetRLGroup().markers.Count();
		}
		return count;
	}
	
}