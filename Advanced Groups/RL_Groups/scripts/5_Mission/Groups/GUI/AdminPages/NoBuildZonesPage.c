class RLNoBuildZonesPage : RLAdmin_Menu_Page {
	
	SliderWidget slider_size;
	Widget zones_w;
	EditBoxWidget txt_size, txt_name;
	ButtonWidget btn_add, btn_save;
	CheckBoxWidget chckbx_show_territorry_flags, chckbx_enable, chckbx_show;
	
	bool placing = false;
	MapMarkerWrapperCircle circle;
	ref array<ButtonWidget> deleteButtons = new array<ButtonWidget>();
	ref array<Widget> button_border = new array<Widget>();
	ref array<MapMarkerWrapperCircle> circles = new array<MapMarkerWrapperCircle>();
	
	int currentMarkedZoneIndex = -1;
	
	private ref RLMapMarkerManager mapMarkerManager;
	private MapWidget mapWidget;
	
	override string GetLayoutPath() {
		return "RayLab_Groups/gui/layouts/admin/noBuildZones.layout";
	}
	
	override string GetButtonName() {
		return "No Build";
	}
	
	override void OnShow() {
		super.OnShow();
		StopPlacing();
		circles.Clear();
		linked.LoadLinkedVars();
		AddMarkers();
	}
	
	RL_NoBuildConfig_ GetRL_NoBuildConfig() {
		return RL_NoBuildConfig.Get;
	}
	
	override string GetPageShowPermission() {
		return "nobuild.change";
	}
	
	override void RegisterAllLinkedVars() {
		linked.RegisterLinkedVar("GetRL_NoBuildConfig().displayOnMap", chckbx_show);
		linked.RegisterLinkedVar("GetRL_NoBuildConfig().enabled", chckbx_enable);
		
		ApplyWidgetPermission("panel_edit", "nobuild.change");
	}
	
	override void OnHide() {
		super.OnHide();
		circles.Clear();
		StopPlacing();
		RL_NoBuildConfig.Loader.Load();
	}
	
	override void InitWidgets() {
		super.InitWidgets();
		mapMarkerManager = new RLMapMarkerManager(mapWidget);
	}
	
	override bool OnClick(Widget w, int x, int y, int button) {
		if (super.OnClick(w, x, y, button))
			return true;
		if (w == btn_add) {
			TogglePlacing();
			return true;
		} else if (w == mapWidget && placing) {
			vector pos = mapMarkerManager.GetMousePosWld();
			int radius = GetSliderRadius();
			RLLogger.Info("Placing Circle: " + pos + " " + radius + " " + w, "AdvancedGroups");
			RL_NoBuildEntry zone = new RL_NoBuildEntry();
			zone.x = pos[0];
			zone.y = pos[2];
			zone.r = radius;
			zone.name = txt_name.GetText();
			RL_NoBuildConfig.Get.zones.Insert(zone);
			AddMarkers();
			StartPlacing();
		} else if (w == btn_save) {
			RL_NoBuildConfig.Loader.Save();
			return true;
		} else {
			int i = 0;
			foreach (ButtonWidget btn : deleteButtons) {
				if (w == btn) {
					RL_NoBuildConfig.Get.zones.RemoveOrdered(i);
					AddMarkers();
					return true;
				}
				i++;
			}
		}
		return false;
	}
	
	void AddZonesToList() {
		while (zones_w.GetChildren()) {
			zones_w.RemoveChild(zones_w.GetChildren());
		}
		deleteButtons.Clear();
		button_border.Clear();
		foreach (RL_NoBuildEntry zone : RL_NoBuildConfig.Get.zones) {
			Widget zoneWidget = RLLayoutManager.Get().CreateLayout("NoBuildZoneListEntry", "RayLab_Groups/gui/layouts/mapmenu/zonelistentry_default.layout", zones_w);
			TextWidget name = TextWidget.Cast(zoneWidget.FindAnyWidget("name"));
			name.SetText(zone.name + "  X:" + zone.x + " Z:" + zone.y + " R:" + zone.r);
			deleteButtons.Insert(ButtonWidget.Cast(zoneWidget.FindAnyWidget("btn_0")));
			button_border.Insert(zoneWidget.FindAnyWidget("button_border"));
		}
	}
	
	void AddMarkers() {
		circles.Clear();
		mapMarkerManager.ClearMarkers();
		foreach (RL_NoBuildEntry zone : RL_NoBuildConfig.Get.zones) {
			MapMarkerWrapperCircle circle_ = mapMarkerManager.AddCircleNonScaling(Vector(zone.x, 0, zone.y), zone.r, ARGB(255,255,0,0), 7456341, true);
			circles.Insert(circle_);
		}
		AddZonesToList();
		if (placing) {
			StartPlacing();
		}
		mapMarkerManager.AddMarkerObject(GetGame().GetPlayer(), "Me", RLColorManager.Get().GetColor("Own Player Map Marker"), RLMarkerVisibilityManager.Get().GetPlayerMarkerIcon());
		mapMarkerManager.UpdateFrame(true);
	}
	
	override void OnUpdateFrame() {
		super.OnUpdateFrame();
		if (mapMarkerManager)
			mapMarkerManager.UpdateFrame();
		if (circle) {
			vector pos = mapMarkerManager.GetMousePosWld();
			int radius = GetSliderRadius();
			RLLogger.Verbose("Placing Circle at: " + pos + " Radius: " + radius, "AdvancedGroups");
			circle.SetPosition(pos);
			circle.SetRadius(radius);
		}
		int lastIndex = currentMarkedZoneIndex;
		CalcNearestZoneToCursor();
		if (lastIndex != currentMarkedZoneIndex) {
			RLLogger.Verbose("Moved to new Zone: " + lastIndex + " -> " + currentMarkedZoneIndex, "AdvancedGroups");
			MapMarkerWrapperCircle circle_ = circles.Get(currentMarkedZoneIndex);
			Widget border = button_border.Get(currentMarkedZoneIndex);
			if (circle_)
				circle_.SetColor(ARGB(255,0,255,0));
			if (border)
				border.SetColor(ARGB(255,0,255,0));
			MapMarkerWrapperCircle circle2 = circles.Get(lastIndex);
			Widget border2 = button_border.Get(lastIndex);
			if (circle2)
				circle2.SetColor(ARGB(255,255,0,0));
			if (border2)
				border2.SetColor(ARGB(255,255,255,255));
			
		}
		if (GetUApi().GetInputByName("UARLMGroupDeleteMarker").LocalPress()) {
			RL_NoBuildConfig.Get.zones.RemoveOrdered(currentMarkedZoneIndex);
			AddMarkers();
		}
	}
	
	void CalcNearestZoneToCursor() {
		int nearestIndex = -1;
		float distance = -1;
		vector pos = mapMarkerManager.GetMousePosWld();
		int i = 0;
		foreach (RL_NoBuildEntry zone : RL_NoBuildConfig.Get.zones) {
			float dist = vector.Distance(pos, Vector(zone.x, 0, zone.y));
			if (distance < 0 || dist < distance) {
				nearestIndex = i;
				distance = dist;
			}
			i++;
		}
		currentMarkedZoneIndex = nearestIndex;
	}
	
	void StartPlacing() {
		StopPlacing();
		placing = true;
		circle = mapMarkerManager.AddCircleNonScaling(vector.Zero, GetSliderRadius(), ARGB(255,255,0,0), 7456342, true);
	}
	
	void StopPlacing() {
		placing = false;
		mapMarkerManager.RemoveLayer(7456342);
		circle = null;
	}
	
	void TogglePlacing() {
		if (placing)
			StopPlacing();
		else
			StartPlacing();
	}
	
	int GetSliderRadius() {
		return Math.Min(Math.Max(10, slider_size.GetCurrent()), 2000);
	}
	
	override bool OnChange(Widget w, int x, int y, bool finished) {
		if (w == slider_size) {
			int radius = GetSliderRadius();
			txt_size.SetText("" + radius + "m");
			return true;
		}
		return false;
	}
	
	override bool OnMouseLeave(Widget w, Widget enterW, int x, int y) {
		if (super.OnMouseLeave(w, enterW, x, y))
			return true;
		if (w == txt_size) {
			int dist = txt_size.GetText().ToInt();
			txt_size.SetText("" + dist + "m");
			slider_size.SetCurrent(dist);
			SetFocus(slider_size);
			return true;
		}
		return false;
	}
	
	override bool OnMouseEnter(Widget w, int x, int y) {
		if (super.OnMouseEnter(w, x, y))
			return true;
		if (w == txt_size) {
			int dist = txt_size.GetText().ToInt();
			txt_size.SetText("" + dist);
		}
		return false;
	}
}