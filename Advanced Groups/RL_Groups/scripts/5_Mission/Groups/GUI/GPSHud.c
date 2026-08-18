class RL_GPSHud : Managed {
	
	private Widget layoutRoot, iconPane, drawCanvas, panelMap;
	private MapWidget mapWidget;
	private TextWidget txt_angle, txt_speed, txt_coords;
	private ref RLMapMarkerManager mapMarkerManager;
	int lastGroupMarkerCount = 0, lastServerMarkerCount = 0, lastPrivateMarkerCount = 0, lastPlayerMarkerCount = 0, lastCustomMarkerCount = 0;
	bool lastNoBuildEnabled = false;
	int lastServerMarkerHash;
	ref MapMarkerWrapperObject markerWrapper;
	bool visible = false;
	float zoom = 0.25;
	float speed = 0;
	vector lastPos = vector.Zero;
	ref Timer speedTimer = new Timer();
	ref Timer coordTimer = new Timer();
	
	static bool foundWorldSize = false;
	
	void Init() {
		layoutRoot = RLLayoutManager.Get().CreateLayout("GPS", "RayLab_Groups/gui/layouts/gps/gps.layout");
		if (!layoutRoot)
			return;
		ConnectClassWidgetVariables(this, layoutRoot);
		mapMarkerManager = new RLMapMarkerManager(mapWidget);
		mapMarkerManager.isMap = false;
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(CheckNewMarkers, 100, true, false);
		RLColorManager.Event_OnColorChange.Insert(OnColorChanged);
		RLPositionManager.Event_OnPositionChange.Insert(OnPositionChange);
		RLLayoutConfig.Event_GPSChanged.Insert(OnSizeChange);
		RLLogger.Debug("Display Speed: " + RLGroupMainConfig.Get.gpsDisplaySpeed + " Display Coords: " + RLGroupMainConfig.Get.gpsDisplayCoords + " Display Angle: " + RLGroupMainConfig.Get.gpsDisplayAngle, "AdvancedGroups");
		if (RLGroupMainConfig.Get.gpsDisplaySpeed) {
			speedTimer.Run(0.5, this, "UpdateSpeed", null, true);
			RLLogger.Debug("Started Timer 1", "AdvancedGroups");
			txt_speed.Show(true);
		} else {
			txt_speed.Show(false);
		}
		if (RLGroupMainConfig.Get.gpsDisplayCoords) {
			coordTimer.Run(0.4, this, "UpdateCoords", null, true);
			RLLogger.Debug("Started Timer 2", "AdvancedGroups");
			txt_coords.Show(true);
		} else {
			txt_coords.Show(false);
		}
		txt_angle.Show(RLGroupMainConfig.Get.gpsDisplayAngle);
		UpdatePosition();
		OnSizeChange();
		RLWidgetUtils.Event_OnScreenSizeChanged.Insert(OnSizeChange);
	}
	
	void ~RL_GPSHud() {
		if (GetGame() && GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM)) {
			GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Remove(CheckNewMarkers);
		}
		if (RLColorManager.Event_OnColorChange)
			RLColorManager.Event_OnColorChange.Remove(OnColorChanged);
		if (RLPositionManager.Event_OnPositionChange)
			RLPositionManager.Event_OnPositionChange.Remove(OnPositionChange);
		if (RLLayoutConfig.Event_GPSChanged)
			RLLayoutConfig.Event_GPSChanged.Remove(OnSizeChange);
		if (layoutRoot)
			layoutRoot.Unlink();
		delete mapMarkerManager;
		speedTimer.Stop();
		coordTimer.Stop();
		RLWidgetUtils.Event_OnScreenSizeChanged.Remove(OnSizeChange);
	}
	
	int lastGPSCheck = 0;
	bool lastGPSCheckResult = false;
	
	bool CanEnableGPS() {
		int time = GetGame().GetTime();
		if (time - lastGPSCheck >= 1000) {
			lastGPSCheckResult = CanEnableGPSInternal();
			lastGPSCheck = time;
		}
		return lastGPSCheckResult;
	}
	
	private bool CanEnableGPSInternal() {
		PlayerBase player = PlayerBase.Cast(GetGame().GetPlayer());
		if (!player)
			return false;
		HumanCommandVehicle veh = player.GetCommand_Vehicle();
		Transport trans = null;
		if (veh)
			trans = veh.GetTransport();
		
		if (RLGroupMainConfig.Get.gpsOnlyInVehicles) {
			if (!trans)
				return false;
			bool hasGPS = !RLGroupMainConfig.Get.gpsRequireItem || HasGPSInInventory(player);
			string type = trans.GetType();
			TStringSet vehicles = RLInherit.Get().GetAllChildren(RLGroupMainConfig.Get.vehiclesWithGPS, false, true, true);
			return vehicles.Find(type) != -1 && hasGPS;
		}
		return !RLGroupMainConfig.Get.gpsRequireItem || HasGPSInInventory(player);
	}
	
	bool HasGPSInInventory(PlayerBase player) {
		return RL_PlayerBase_Utils.HasItemsInInventory(player, RLGroupMainConfig.Get.gpsItems);
	}
	
	void OnColorChanged() {
		CheckNewMarkers(true);
	}
	
	void UpdateSpeed() {
		Man player = GetGame().GetPlayer();
		if (player) {
			vector pos = player.GetPosition();
			if (lastPos != vector.Zero) {
				float dist = vector.Distance(pos, lastPos);
				speed = dist * 2 * 3.6;
				txt_speed.SetText(FloatToString(speed, true) + " km/h");
			}
			lastPos = pos;
		}
	}
	
	void UpdateCoords() {
		Man player = GetGame().GetPlayer();
		if (player) {
			vector pos = player.GetPosition();
			string format = FloatToString(pos[0], false) + "  " + FloatToString(pos[1], false) + "  " + FloatToString(pos[2], false);
			txt_coords.SetText(format);
		}
		
	}
	
	string FloatToString(float f, bool addPoint) {
		string txt = "" + f;
		int index = txt.IndexOf(".");
		if (addPoint) {
			if (index > 0)
				txt = txt.Substring(0, index + 2);
			else
				txt = txt + ".0";
		} else {
			if (index > 0)
				txt = txt.Substring(0, index);
		}
		return txt;
	}
	
	void OnSizeChange() {
		zoom = RLLayoutConfig.Get().gpsZoom;
		RLLogger.Info("On GPS Size changed: " + zoom, "AdvancedGroups");
		float width, height;
		if (GetSize(width, height)) {
			SetSize(width, height);
		}
		int index = RLLayoutConfig.Get().gpsSizeIndex;
		txt_coords.Show(RLGroupMainConfig.Get.gpsDisplayCoords && (index  == 0 || index == 3));
		UpdatePosition();
	}
	
	void SetSize(float width, float height) {
		layoutRoot.SetSize(width, height);
	}
	
	bool GetSize(out float width, out float height){
		int index = RLLayoutConfig.Get().gpsSizeIndex;
		if (index == 2) {
			width = 175 * RLWidgetUtils.widthScale;
			height = 175 * RLWidgetUtils.widthScale;
			return true;
		}
		if (index == 1) {
			width = 220 * RLWidgetUtils.widthScale;
			height = 220 * RLWidgetUtils.widthScale;
			return true;
		}
		if (index == 0) {
			width = 300 * RLWidgetUtils.widthScale;
			height = 300 * RLWidgetUtils.widthScale;
			return true;
		}
		if (index == 3) {
			width = 350 * RLWidgetUtils.widthScale;
			height = 350 * RLWidgetUtils.widthScale;
			return true;
		}
		return false;
	}
	
	void OnPositionChange() {
		UpdatePosition();
	}
	
	void UpdatePosition() {
		vector pos = RLPositionManager.Get().GetPosition("Minimap");
		int index = RLPositionManager.Get().GetIndex("Minimap");
		RLLogger.Verbose("Updating Position of Minimap " + pos + " " + index, "AdvancedGroups");
		RLWidgetUtils.SetWidgetAlignmentIndex(layoutRoot, index);
		RLWidgetUtils.SetWidgetPositionIndex(layoutRoot, pos, index);
		RLLogger.Verbose("Updated Position to: " + pos, "AdvancedGroups");
	}
	
	
	void CheckNewMarkers(bool force = false) {
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		if (!mission || !mission.openedMapUI) {
			return;
		}
		if (force || NeedMarkerRefresh()) {
			mission.openedMapUI.AddMapMarker(mapWidget, mapMarkerManager, false);
			if (GetGame().GetPlayer())
				markerWrapper = mapMarkerManager.AddMarkerObject(GetGame().GetPlayer(), "", RLColorManager.Get().GetColor("Own Player Map Marker"), "RayLab_Groups\\gui\\icons\\marker-stroked.paa");
		}
	}
	
	void UpdateHud() {
		IngameHud hud = IngameHud.Cast(GetGame().GetMission().GetHud());
		if (hud) {
			bool visible_ = hud.IsHudVisible() && RLMarkerVisibilityManager.Get().gpsEnabled && RLUtils.IsClientPlayerAlive();
			if (GetGame().GetUIManager().GetMenu())
				visible_ = false;
			Show(visible_);
			if (visible_) {
				Update();
			}
		} else {
			Show(false);
		}
	}
	
	bool IsVisible() {
		return visible;
	}
	
	void Show(bool show) {
		visible = show;
		layoutRoot.Show(show && CanEnableGPS());
	}
	
	void UpdateGPSItemVisibility() {
		Show(visible);
	}
	
	void OnGroupChanged() {
		CheckNewMarkers(true);
	}
	
	bool NeedMarkerRefresh() {
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		bool need = false;
		bool noBuild = (RLMarkerVisibilityManager.Get() != null && RLMarkerVisibilityManager.Get().showNoBuildZones && RL_NoBuildConfig.Get != null && RL_NoBuildConfig.Get.enabled);
		if (noBuild != lastNoBuildEnabled) {
			lastNoBuildEnabled = noBuild;
			need = true;
		}
		int groupMarkers = 0;
		if (pb && pb.GetRLGroup()) {
			RLGroup grp = pb.GetRLGroup();
			groupMarkers += grp.markers.Count();
			groupMarkers += grp.pings.Count();
			groupMarkers += grp.members.Count();
		}
		if (groupMarkers != lastGroupMarkerCount) {
			lastGroupMarkerCount = groupMarkers;
			need = true;
		}
		RLStaticMarkerManagerClient mgr = RLStaticMarkerManagerClient.Get();
		int staticHash = 0;
		foreach (RLServerMarker markServ : mgr.staticMarkers) {
			staticHash += markServ.CalcHash();
		}
		if (staticHash != lastServerMarkerHash) {
			lastServerMarkerHash = staticHash;
			need = true;
		}
		RLPrivateMarkerManager mgrc = RLPrivateMarkerManager.Get();
		int clientMarkers = mgrc.privateMarkers.Count();
		if (clientMarkers != lastPrivateMarkerCount) {
			lastPrivateMarkerCount = clientMarkers;
			need = true;
		}
		RLGroupMainConfig_ cfg = RLGroupMainConfig.Get;
		if (cfg && cfg.canSeeOwnPlayerOnMap && lastPlayerMarkerCount != 1) {
			lastPlayerMarkerCount = 1;
			need = true;
		} else if (cfg && !cfg.canSeeOwnPlayerOnMap && lastPlayerMarkerCount != 0) {
			lastPlayerMarkerCount = 0;
			need = true;
		}
		return need;
	}
	
	void Update() {
		if (!visible)
			return;
		mapMarkerManager.UpdateFrame();
		vector pos = GetDayZGame().GetCurrentCameraPosition();
		float angle = GetCurrentAngle();
		string angletxt = "" + angle;
		int index = angletxt.IndexOf(".");
		if (index > 0)
			angletxt = angletxt.Substring(0, index);
		txt_angle.SetText(angletxt);
		mapWidget.SetScale(zoom);
		
		float finalX = pos[0] + zoom * 10;
		float finalY = pos[2] + zoom * 10;
		vector finalPos = Vector(finalX, 0, finalY);
		mapWidget.SetMapPos(finalPos);
		if (markerWrapper && markerWrapper.icon)
			markerWrapper.icon.SetRotation(0,0,angle - 180);
	}
	
	float GetCurrentAngle() {
		vector dir = GetGame().GetCurrentCameraDirection();
		vector angles = dir.VectorToAngles();
		if (angles[0] < 0)
			return angles[0] + 360;
		if (angles[0] > 360)
			return angles[0] - 360;
		return angles[0];
	}
	

}