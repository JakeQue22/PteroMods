class RLAddMarkerPopup {
	
	TextWidget addTitle;
	ImageWidget image_Icon;
	SliderWidget sliderR, sliderG, sliderB, sliderA;
	SliderWidget radiusSliderR, radiusSliderG, radiusSliderB, radiusSliderA, sliderMarkerRadius;
	EditBoxWidget txtMarkerRadius;
	CheckBoxWidget chckbxRadiusStriked, chckbxShowPlayerNametags;
	ButtonWidget btn_add_marker, btn_add_cancel, btn_add_delete, btn_clear_name;
	EditBoxWidget input_marker_name;
	XComboBoxWidget comboBoxImage, comboBoxVisibility;
	Widget markerAdminPanel, radiusColorPreview;
	vector addPosition;
	
	ref array<ref RLMarkerType> availableTypes = new array<ref RLMarkerType>();
	
	ref RLMarker edit_marker;
	
	Widget addPopup;
	RLGroupUI parent;
	
	void StoreAllWidgetData(RLDataSerializer data) {
		data.Write(new Param4<float, float, float, float>(sliderR.GetCurrent(), sliderG.GetCurrent(), sliderB.GetCurrent(), sliderA.GetCurrent()));
		data.Write(new Param5<float, float, float, float, int>(radiusSliderR.GetCurrent(), radiusSliderG.GetCurrent(), radiusSliderB.GetCurrent(), radiusSliderA.GetCurrent(), (int) sliderMarkerRadius.GetCurrent()));
		data.Write(new Param1<string>(input_marker_name.GetText()));
		data.Write(new Param2<int, int>(comboBoxImage.GetCurrentItem(), comboBoxVisibility.GetCurrentItem()));
		data.Write(new Param1<bool>(chckbxShowPlayerNametags.IsChecked()));
	}
	
	void RestoreAllWidgetData(RLDataSerializer data) {
		Param4<float, float, float, float> colorParam = Param4<float, float, float, float>.Cast(data.Read());
		sliderR.SetCurrent(colorParam.param1);
		sliderG.SetCurrent(colorParam.param2);
		sliderB.SetCurrent(colorParam.param3);
		sliderA.SetCurrent(colorParam.param4);
		Param5<float, float, float, float, int> colorParamRadius = Param5<float, float, float, float, int>.Cast(data.Read());
		radiusSliderR.SetCurrent(colorParamRadius.param1);
		radiusSliderG.SetCurrent(colorParamRadius.param2);
		radiusSliderB.SetCurrent(colorParamRadius.param3);
		radiusSliderA.SetCurrent(colorParamRadius.param4);
		sliderMarkerRadius.SetColor(colorParamRadius.param5);
		Param1<string> nameParam = Param1<string>.Cast(data.Read());
		input_marker_name.SetText(nameParam.param1);
		Param2<int, int> selectParam = Param2<int, int>.Cast(data.Read());
		comboBoxImage.SetCurrentItem(selectParam.param1);
		comboBoxVisibility.SetCurrentItem(selectParam.param2);
		Param1<bool> playerTagsParam = Param1<bool>.Cast(data.Read());
		chckbxShowPlayerNametags.SetChecked(playerTagsParam.param1);
		UpdateIconImage();
	}
	
	void Init(RLGroupUI parentUI) {
		parent = parentUI;
		addPopup = RLLayoutManager.Get().CreateLayout("Map Marker Add Popup", "RayLab_Groups/gui/layouts/mapmenu/markerpopup_default.layout", parent.layoutRoot);
		addPopup.Show(false);
		
		ConnectClassWidgetVariables(this, addPopup, {"addPopup"});
		foreach (string str : RLGroupMainConfig.Get.availableIcons) {
			string displayname = str;
			int index = displayname.LastIndexOf("\\");
			int index2 = displayname.LastIndexOf("/");
			if (index < index2)
				index = index2;
			if (index > 0) {
				displayname = displayname.Substring(index + 1, displayname.Length() - index - 1);
			}
			index = displayname.LastIndexOf(".");
			if (index != -1) {
				displayname = displayname.Substring(0, index);
			}
			if (displayname.Length() > 0) {
				string first = displayname[0] + "";
				first.ToUpper();
				displayname[0] = first;
			}
			comboBoxImage.AddItem(displayname);
		}
		comboBoxImage.SetCurrentItem(0);
		UpdateIconImage();
		FillAvailableMarkerTypes();
		UpdateMarkerRadiusText();
	}

	
	void OnUpdateFrame() {
		SetIconColor();
		SetRadiusColor();
	}
	
	void OnUpdateSlow() {
		
	}
	
	void UpdateShowRadiusCreation() {
		int visibility = comboBoxVisibility.GetCurrentItem();
		RLMarkerType type = availableTypes.Get(visibility);
		ShowRadiusCreation(type == RLMarkerType.SERVER_STATIC || type == RLMarkerType.SERVER_DYNAMIC);
	}
	
	void ShowRadiusCreation(bool show) {
		markerAdminPanel.Show(show);
		int height = RLWidgetUtils.HeightToPixel(270);
		if (show)
			height = RLWidgetUtils.HeightToPixel(365);
		float widthOld, heightOld;
		addPopup.GetSize(widthOld, heightOld);
		addPopup.SetSize(widthOld, height);
	}
	
	void UpdateMarkerRadiusText() {
		int radius = (int) sliderMarkerRadius.GetCurrent();
		txtMarkerRadius.SetText("" + radius);
	}
	
	void SetIconColor() {
		image_Icon.SetColor(ARGB(sliderA.GetCurrent(), sliderR.GetCurrent(), sliderG.GetCurrent(), sliderB.GetCurrent()));
	}
	
	void SetRadiusColor() {
		radiusColorPreview.SetColor(ARGB((int) radiusSliderA.GetCurrent(), (int) radiusSliderR.GetCurrent(), (int) radiusSliderG.GetCurrent(), (int) radiusSliderB.GetCurrent()));
	}
	
	void OnGroupChanged() {
		FillAvailableMarkerTypes();
	}
	
	void AddMarkerButtonClicked() {
		int visibility = comboBoxVisibility.GetCurrentItem();
		RLMarkerType type = availableTypes.Get(visibility);
		string name = input_marker_name.GetText();
		int iconIndex = comboBoxImage.GetCurrentItem();
		string icon = RLGroupMainConfig.Get.availableIcons.Get(iconIndex);
		int colorA = sliderA.GetCurrent();
		int colorR = sliderR.GetCurrent();
		int colorG = sliderG.GetCurrent();
		int colorB = sliderB.GetCurrent();
		if (edit_marker) {
			if (type != edit_marker.type) {
				vector pos = edit_marker.position;
				RequestMarkerDelete(edit_marker);
				AddMarker(pos, type, name, icon, colorR, colorG, colorB, colorA);
			} else {
				edit_marker.SetColorARGBGlobal(colorA,colorR,colorG,colorB);
				edit_marker.SetIconGlobal(icon);
				edit_marker.SetNameGlobal(name);
				if (markerAdminPanel.IsVisible()) {
					edit_marker.SetRadius(sliderMarkerRadius.GetCurrent(), (int) radiusSliderA.GetCurrent(), (int) radiusSliderR.GetCurrent(), (int) radiusSliderG.GetCurrent(), (int) radiusSliderB.GetCurrent(), chckbxRadiusStriked.IsChecked(), false);
					edit_marker.showAllPlayerNametags = chckbxShowPlayerNametags.IsChecked();
				} else {
					edit_marker.SetRadius(0.0, false);
					edit_marker.showAllPlayerNametags = false;
				}
				addPopup.Show(false);
				if (edit_marker.type == RLMarkerType.SERVER_STATIC || edit_marker.type == RLMarkerType.SERVER_DYNAMIC) {
					RLStaticMarkerManagerClient.Get().RequestGlobalMarkerUpdate(edit_marker.uid);
				}
			}
			parent.UpdateMarkerListLater();
		} else {
			AddMarker(addPosition, type, name, icon, colorR, colorG, colorB, colorA);
		}
	}
	
	void AddMarker(vector pos, RLMarkerType type, string name, string icon, int colorR, int colorG, int colorB, int colorA) {
		string printname = name + "";
		printname.Replace("%", "");
		RLLogger.Info("AddMarker: " + pos + " " + type + " " + printname + " " + icon, "AdvancedGroups");
		RLMarker marker = new RLMarker();
		pos[1] = GetGame().SurfaceY(pos[0], pos[2]);
		if (pos[1] < 1)
			pos[1] = 1;
		marker.SetupMarker(type, name, icon, pos);
		marker.colorA = colorA;
		marker.colorR = colorR;
		marker.colorG = colorG;
		marker.colorB = colorB;
		RLLogger.Debug("Add Marker Radius ? " + markerAdminPanel.IsVisible(), "AdvancedGroups");
		if (markerAdminPanel.IsVisible()) {
			marker.SetRadius(sliderMarkerRadius.GetCurrent(), (int) radiusSliderA.GetCurrent(), (int) radiusSliderR.GetCurrent(), (int) radiusSliderG.GetCurrent(), (int) radiusSliderB.GetCurrent(), chckbxRadiusStriked.IsChecked(), false);
			marker.showAllPlayerNametags = chckbxShowPlayerNametags.IsChecked();
		} else {
			marker.showAllPlayerNametags = false;
			marker.SetRadius(0.0, false);
		}
		if (type == RLMarkerType.GROUP_MARKER) {
			PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
			if (pb) {
				RLGroup grp = pb.GetRLGroup();
				if (grp) {
					RLLogger.Debug("Adding Group Marker", "AdvancedGroups");
					grp.AddMarker(marker);
				}
			}
		} else if (type == RLMarkerType.PRIVATE_MARKER) {
			RLLogger.Debug("Adding Private Marker", "AdvancedGroups");
			RLPrivateMarkerManager.Get().AddMarker(marker);
		} else if (type == RLMarkerType.SERVER_STATIC || type == RLMarkerType.SERVER_DYNAMIC) {
			RLLogger.Debug("Adding Server Marker", "AdvancedGroups");
			RLStaticMarkerManagerClient.Get().RequestGlobalMarkerAdd(marker);
		}
		addPopup.Show(false);
	}
	
	void UpdateIconImage() {
		int selected = comboBoxImage.GetCurrentItem();
		if (selected < 0)
			return;
		string image = RLGroupMainConfig.Get.availableIcons.Get(selected);
		image_Icon.LoadImageFile(0, image);
	}
	
	void ShowPopup(int x, int y, bool deleteMode, RLMarker marker) {
		vector pos = Vector(x,y,0);
		addPosition = parent.mapWidget.ScreenToMap(pos);
		addPopup.Show(true);
		RLLogger.Debug("Showing Add Popup " + x + " " + y + " MapPos: " + addPosition + " Delete: " + deleteMode, "AdvancedGroups");
		btn_add_delete.Show(deleteMode);
		edit_marker = marker;
		if (deleteMode) {
			if (addTitle)
				addTitle.SetText("#rl_edit_marker");
			int index = RLGroupMainConfig.Get.availableIcons.Find(marker.icon);
			if (index == -1)
				index = 0;
			comboBoxImage.SetCurrentItem(index);
			int typeIndex = availableTypes.Find(marker.type);
			if (typeIndex == -1)
				typeIndex = 0;
			comboBoxVisibility.SetCurrentItem(typeIndex);
			sliderA.SetCurrent(marker.colorA);
			sliderR.SetCurrent(marker.colorR);
			sliderG.SetCurrent(marker.colorG);
			sliderB.SetCurrent(marker.colorB);
			input_marker_name.SetText(marker.name);
			radiusSliderA.SetCurrent(marker.circleColorA);
			radiusSliderR.SetCurrent(marker.circleColorR);
			radiusSliderG.SetCurrent(marker.circleColorG);
			radiusSliderB.SetCurrent(marker.circleColorB);
			sliderMarkerRadius.SetCurrent(marker.circleRadius);
			chckbxRadiusStriked.SetChecked(marker.circleStriked);
			chckbxShowPlayerNametags.SetChecked(marker.showAllPlayerNametags);
			UpdateMarkerRadiusText();
			UpdateIconImage();
			SetIconColor();
		} else {
			if (addTitle)
				addTitle.SetText("#rl_addmarker");
		}
		UpdateShowRadiusCreation();
	}
	
	bool OnClick(Widget w) {
		if (w == btn_add_marker) {
			AddMarkerButtonClicked();
			return true;
		} else if (w == btn_add_cancel) {
			addPopup.Show(false);
			return true;
		} else if (w == btn_add_delete) {
			addPopup.Show(false);
			RequestMarkerDelete(edit_marker);
			return true;
		} else if (w == btn_clear_name) {
			input_marker_name.SetText("");
			return true;
		}
		return false;
	}
	
	bool OnChange(Widget w) {
		if (w == comboBoxVisibility) {
			UpdateShowRadiusCreation();
			return true;
		} else if (w == comboBoxImage) {
			UpdateIconImage();
			return true;
		} else if (w == sliderMarkerRadius) {
			UpdateMarkerRadiusText();
			return true;
		} else if (w == txtMarkerRadius) {
			sliderMarkerRadius.SetCurrent(txtMarkerRadius.GetText().ToInt());
			UpdateMarkerRadiusText();
		}
		return false;
	}
	
	void FillAvailableMarkerTypes() {
		comboBoxVisibility.ClearAll();
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		availableTypes.Clear();
		if (pb && pb.GetRLGroup()) {
			comboBoxVisibility.AddItem("#rl_group");
			availableTypes.Insert(RLMarkerType.GROUP_MARKER);
		}
		comboBoxVisibility.AddItem("#rl_marker_private");
		availableTypes.Insert(RLMarkerType.PRIVATE_MARKER);
		if (RLAdmins.Get().HasPermission("marker.temp.change")) {
			comboBoxVisibility.AddItem("#rl_marker_global_temp");
			availableTypes.Insert(RLMarkerType.SERVER_DYNAMIC);
		}
		if (RLAdmins.Get().HasPermission("marker.perm.change")) {
			comboBoxVisibility.AddItem("#rl_marker_global_perm");
			availableTypes.Insert(RLMarkerType.SERVER_STATIC);
		}
		UpdateShowRadiusCreation();
	}
	
	void DeleteMarkerUnderMouse() {
		int x, y;
		GetMousePos(x,y);
		RLLogger.Info("DeleteMarkerUnderMouse. Pos: " + x + "," + y, "AdvancedGroups");
		vector mousePos = Vector(x + 10,y + 10,0);
		vector mapPos = parent.mapWidget.ScreenToMap(mousePos);
		RLLogger.Debug("MapPos: " + mapPos, "AdvancedGroups");
		RLMarker marker = FindMarkerInRadius(mapPos);
		RequestMarkerDelete(marker);
	}
	
	void RequestMarkerDelete(RLMarker marker) {
		if (marker) {
			string printname = marker.name + "";
			printname.Replace("%", "");
			RLLogger.Info("Removing Marker: " + printname + " " + marker.icon, "AdvancedGroups");
			if (marker.type == RLMarkerType.PRIVATE_MARKER) {
				RLPrivateMarkerManager.Get().RemoveMarker(marker);
			} else if (marker.type == RLMarkerType.GROUP_MARKER) {
				PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
				if (!pb || !pb.GetRLGroup())
					return;
				RLGroup grp = pb.GetRLGroup();
				grp.RemoveMarker(marker);
			} else if (marker.type == RLMarkerType.SERVER_STATIC || marker.type == RLMarkerType.SERVER_DYNAMIC) {
				RLStaticMarkerManagerClient.Get().RequestGlobalMarkerRemove(marker.uid);
			}
		}
	}
	
	RLMarker FindMarkerInRadius(vector center, float radius = 500) {
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		if (!pb)
			return null;
		RLGroup grp = pb.GetRLGroup();
		RLMarker bestMarker = null;
		float bestDist = radius + 1;
		if (grp) {
			foreach (RLMarker marker : grp.markers) {
				if (!RLMarkerVisibilityManager.Get().IsMapVisible(marker.uid, marker.type))
					continue;
				float dist = Math.Sqrt((marker.position[0] - center[0]) * (marker.position[0] - center[0]) + (marker.position[2] - center[2]) * (marker.position[2] - center[2]));
				if (dist < bestDist) {
					RLLogger.Verbose("New Best Marker: " + marker + " OLD: " + bestMarker + " Dist: " + dist + " OLD: " + bestDist, "AdvancedGroups");
					bestMarker = marker;
					bestDist = dist;
				}
			}
		}
		foreach (RLMarker marker2 : RLPrivateMarkerManager.Get().privateMarkers) {
			if (!RLMarkerVisibilityManager.Get().IsMapVisible(marker2.uid, marker2.type))
				continue;
			dist = Math.Sqrt((marker2.position[0] - center[0]) * (marker2.position[0] - center[0]) + (marker2.position[2] - center[2]) * (marker2.position[2] - center[2]));
			if (dist < bestDist) {
				RLLogger.Verbose("New Best Marker: " + marker2 + " OLD: " + bestMarker + " Dist: " + dist + " OLD: " + bestDist, "AdvancedGroups");
				bestMarker = marker2;
				bestDist = dist;
			}
		}
		if (availableTypes.Find(RLMarkerType.SERVER_STATIC) != -1) {
			foreach (RLServerMarker serverMark2 : RLStaticMarkerManagerClient.Get().staticMarkers) {
				if (!RLMarkerVisibilityManager.Get().IsMapVisible(serverMark2.uid, serverMark2.type))
					continue;
				if (serverMark2.type != RLMarkerType.SERVER_STATIC)
					continue;
				dist = Math.Sqrt((serverMark2.position[0] - center[0]) * (serverMark2.position[0] - center[0]) + (serverMark2.position[2] - center[2]) * (serverMark2.position[2] - center[2]));
				if (dist < bestDist) {
					RLLogger.Verbose("New Best Marker: " + serverMark2 + " OLD: " + bestMarker + " Dist: " + dist + " OLD: " + bestDist, "AdvancedGroups");
					bestMarker = serverMark2;
					bestDist = dist;
				}
			}
		}
		if (availableTypes.Find(RLMarkerType.SERVER_DYNAMIC) != -1) {
			foreach (RLServerMarker serverMark1 : RLStaticMarkerManagerClient.Get().staticMarkers) {
				if (!RLMarkerVisibilityManager.Get().IsMapVisible(serverMark1.uid, serverMark1.type))
					continue;
				if (serverMark1.type != RLMarkerType.SERVER_DYNAMIC)
					continue;
				dist = Math.Sqrt((serverMark1.position[0] - center[0]) * (serverMark1.position[0] - center[0]) + (serverMark1.position[2] - center[2]) * (serverMark1.position[2] - center[2]));
				if (dist < bestDist) {
					RLLogger.Verbose("New Best Marker: " + serverMark1 + " OLD: " + bestMarker + " Dist: " + dist + " OLD: " + bestDist, "AdvancedGroups");
					bestMarker = serverMark1;
					bestDist = dist;
				}
			}
		}
		RLLogger.Verbose("Best Marker: " + bestMarker + " Dist: " + bestDist, "AdvancedGroups");
		return bestMarker;
	}

}