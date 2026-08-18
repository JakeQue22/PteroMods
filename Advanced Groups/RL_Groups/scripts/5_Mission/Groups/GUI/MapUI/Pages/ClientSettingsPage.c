class RLClientSettingsPage : RLGroupPage {
	
	const int CHAT_SIZE_START = 7;
	
	SliderWidget sliderA, sliderR, sliderG, sliderB;
	EditBoxWidget editA, editR, editG, editB;
	Widget colorPreview;
	TextListboxWidget availableColors;
	ButtonWidget toDefault;
	
	ButtonWidget btn_ResetAll, btn_Reload_From_Config, btn_Save_Config;
	XComboBoxWidget comboboxPingIcon, comboboxPingSize, comboboxChatSize, comboboxPlayerMarkerPos, comboboxPlayerIcon, comboboxPlayerSize;
	ImageWidget imagePingIcon, imagePlayerIcon;
	CheckBoxWidget chckbx_Playerlist, chckbx_Compass, chckbx_ShowChatTag, chckbx_ShowClantextures, chckbx_StreamerMode, chckbx_GPS, chckbx_show_no_build, checkMarkerStyleIcon, checkMarkerStyleName, checkMarkerStyleDistance, chckbx_centerPlayer;
	
	SliderWidget sliderY, sliderX, sliderGPSZoom;
	Widget previewIcon;
	TextListboxWidget availableLayoutsList;
	CheckBoxWidget invertX, invertY;
	ButtonWidget toDefaultPosition;
	
	TextListboxWidget availableLayoutsListStyles;
	XComboBoxWidget comboBoxStyle, comboPlayerlistStyleTemp, comboboxGPSSize;
	ButtonWidget toDefaultStyle;
	
	XComboBoxWidget comboboxChatVisibility;
	ButtonWidget btnToggleChatVisibility;
	Widget chatVisibilityMark;
	
	ref TStringArray colorArray = new TStringArray();
	ref TStringArray positionsArray = new TStringArray();
	ref TStringArray stylesArray = new TStringArray();
	ref TStringArray pingIconsArray = new TStringArray();
	ref TStringArray playerIconsArray = new TStringArray();

	override bool InitPage(RLGroupUI parentUI) {
		return super.InitPage(parentUI, 3, 0, "#rl_page_settings", true);
	}
	
	override void StoreAllWidgetData(RLDataSerializer data) {
		data.Write(new Param3<int, int, int>(availableColors.GetSelectedRow(), availableLayoutsList.GetSelectedRow(), availableLayoutsListStyles.GetSelectedRow()));
	}
	
	override void RestoreAllWidgetData(RLDataSerializer data) {
		Param3<int, int, int> selectedParam = Param3<int, int, int>.Cast(data.Read());
		availableColors.SelectRow(selectedParam.param1);
		availableColors.EnsureVisible(selectedParam.param1);
		OnItemSelected(availableColors, selectedParam.param1, 0);
		availableLayoutsList.SelectRow(selectedParam.param2);
		availableLayoutsList.EnsureVisible(selectedParam.param2);
		OnItemSelected(availableLayoutsList, selectedParam.param2, 0);
		availableLayoutsListStyles.SelectRow(selectedParam.param3);
		availableLayoutsListStyles.EnsureVisible(selectedParam.param3);
		OnItemSelected(availableLayoutsListStyles, selectedParam.param3, 0);
	}
	
	override void InitMainWidget() {
		ConnectClassWidgetVariables(this, rootWidget, {"buttonWidget", "rootWidget"}, {"invertX", "checkInvertX", "invertY", "checkInvertY"});
		comboPlayerlistStyleTemp.AddItem("default");
		comboPlayerlistStyleTemp.AddItem("small");
		comboPlayerlistStyleTemp.AddItem("tiny");
		
		comboboxGPSSize.AddItem("default");
		comboboxGPSSize.AddItem("small");
		comboboxGPSSize.AddItem("tiny");
		comboboxGPSSize.AddItem("big");
		
		comboboxPlayerMarkerPos.AddItem("Head");
		comboboxPlayerMarkerPos.AddItem("Stomach");
		comboboxPlayerMarkerPos.AddItem("Feet");
	}
	
	override void OnShow() {
		super.OnShow();
		ReloadAll();
	}
	
	void ReloadButtonVisibility() {
		comboboxGPSSize.Enable(RLGroupMainConfig.Get.enableGPS);
		sliderGPSZoom.Enable(RLGroupMainConfig.Get.enableGPS);
		
		comboboxPingIcon.Enable(RLGroupMainConfig.Get.CanUsePing());
		comboboxPingSize.Enable(RLGroupMainConfig.Get.CanUsePing());
		
		comboPlayerlistStyleTemp.Enable(RLGroupMainConfig.Get.enablePlayerList);
		bool showChat = false;
		#ifndef RL_DISABLE_CHAT
		showChat = true;
		#endif
		
		comboboxChatSize.Enable(showChat);
		chckbx_ShowChatTag.Enable(showChat);
		
		if (comboboxChatVisibility)
			comboboxChatVisibility.Show(showChat);
		if (btnToggleChatVisibility)
			btnToggleChatVisibility.Show(showChat);
		if (chatVisibilityMark)
			chatVisibilityMark.Show(showChat);
		if (!showChat) {
			chckbx_ShowChatTag.SetTextColor(ARGB(255,128,128,128));
		} else {
			chckbx_ShowChatTag.SetTextColor(ARGB(255,255,255,255));
		}
		chckbx_show_no_build.Enable(RL_NoBuildConfig.Get.enabled);
		if (!RL_NoBuildConfig.Get.enabled) {
			chckbx_show_no_build.SetTextColor(ARGB(255,128,128,128));
		} else {
			chckbx_ShowChatTag.SetTextColor(ARGB(255,255,255,255));
		}
		chckbx_GPS.Enable(RLGroupMainConfig.Get.enableGPS);
		if (!RLGroupMainConfig.Get.enableGPS) {
			chckbx_GPS.SetTextColor(ARGB(255,128,128,128));
		} else {
			chckbx_ShowChatTag.SetTextColor(ARGB(255,255,255,255));
		}
		chckbx_Compass.Enable(RLGroupMainConfig.Get.enableCompassHud);
		if (!RLGroupMainConfig.Get.enableCompassHud) {
			chckbx_Compass.SetTextColor(ARGB(255,128,128,128));
		} else {
			chckbx_ShowChatTag.SetTextColor(ARGB(255,255,255,255));
		}
		chckbx_Playerlist.Enable(RLGroupMainConfig.Get.enablePlayerList);
		if (!RLGroupMainConfig.Get.enablePlayerList) {
			chckbx_Playerlist.SetTextColor(ARGB(255,128,128,128));
		} else {
			chckbx_ShowChatTag.SetTextColor(ARGB(255,255,255,255));
		}
		
	}
	
	void ReloadAll(bool force = false) {
		ReloadButtonVisibility();
		ReloadColors(force);
		ReloadPositions(force);
		ReloadPingMarkers(force);
		ReloadPlayerMarkers(force);
		ReloadStyles();
		ReloadTextSizes();
		ReloadPingSizes();
		ReloadPlayerSizes();
		OnIconPreviewPositionChange();
		ReloadPlayerMarkerPos();
		ReloadPlayerMarkerStyle();
		ReloadGPS();
		ReloadChatVisibility();
		chckbx_Playerlist.SetChecked(RLMarkerVisibilityManager.Get().playerlistEnabled);
		chckbx_Compass.SetChecked(RLMarkerVisibilityManager.Get().compassEnabled);
		chckbx_ShowClantextures.SetChecked(!RLMarkerVisibilityManager.Get().disableShowClantextures);
		chckbx_StreamerMode.SetChecked(RLLayoutConfig.Get().streamerModeEnabled);
		chckbx_show_no_build.SetChecked(RLMarkerVisibilityManager.Get().showNoBuildZones);
		chckbx_centerPlayer.SetChecked(RLMarkerVisibilityManager.Get().centerMapOnPlayer);
		
		PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
		chckbx_ShowChatTag.Enable(pb.GetPermission() && pb.GetPermission().nextGroupUID == -1);
		chckbx_ShowChatTag.SetChecked(pb.GetRLGroup() && pb.GetRLGroup().showTagInChat);
	}
	
	void ReloadChatVisibility() {
		#ifndef RL_DISABLE_CHAT
		comboboxChatVisibility.ClearAll();
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		RLLogger.Verbose("ReloadChatVisibility. Channels: " + mission.channels.Count(), "AdvancedGroups");
		foreach (ChannelCfg channel : mission.channels) {
			comboboxChatVisibility.AddItem(channel.channelName);
		}
		comboboxChatVisibility.SetCurrentItem(0);
		OnChannelSelected();
		#endif
	}
	
	void OnChannelSelected() {
		#ifndef RL_DISABLE_CHAT
		int selected = comboboxChatVisibility.GetCurrentItem();
		if (selected < 0 || selected >= comboboxChatVisibility.GetNumItems())
			return;
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		string name = mission.channels.Get(selected).channelName;
		bool hidden = RLMarkerVisibilityManager.Get().IsChannelHidden(name);
		RLLogger.Verbose("OnChannelSelected: " + name + " Hidden ?" + hidden, "AdvancedGroups");
		if (hidden) {
			btnToggleChatVisibility.SetText("Hidden");
			chatVisibilityMark.SetColor(COLOR_RED);
		} else {
			btnToggleChatVisibility.SetText("Shown");
			chatVisibilityMark.SetColor(COLOR_GREEN);
		}
		#endif
	}
	
	void OnChannelVisibilityChangeButtonClicked() {
		#ifndef RL_DISABLE_CHAT
		int selected = comboboxChatVisibility.GetCurrentItem();
		if (selected < 0 || selected >= comboboxChatVisibility.GetNumItems())
			return;
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		string name = mission.channels.Get(selected).channelName;
		bool hidden = RLMarkerVisibilityManager.Get().IsChannelHidden(name);
		RLMarkerVisibilityManager.Get().SetChannelHidden(name, !hidden);
		OnChannelSelected();
		#endif
	}
	
	void ReloadGPS() {
		if (comboboxGPSSize)
			comboboxGPSSize.SetCurrentItem(RLLayoutConfig.Get().gpsSizeIndex);
		if (sliderGPSZoom)
			sliderGPSZoom.SetCurrent(RLLayoutConfig.Get().gpsZoom);
		chckbx_GPS.SetChecked(RLMarkerVisibilityManager.Get().gpsEnabled);
	}
	
	void ReloadPlayerMarkerPos() {
		if (comboboxPlayerMarkerPos)
			comboboxPlayerMarkerPos.SetCurrentItem(RLLayoutConfig.Get().playerMarkerPosIndex);
	}
	
	void ReloadPlayerMarkerStyle() {
		int index = RLLayoutConfig.Get().playerMarkerStyleIndex;
		RLLogger.Debug("ReloadPlayerMarkerStyle. Index: " + index, "AdvancedGroups");
		if (checkMarkerStyleIcon)
			checkMarkerStyleIcon.SetChecked((index & 0x01) == 0);
		if (checkMarkerStyleName)
			checkMarkerStyleName.SetChecked((index & 0x02) == 0);
		if (checkMarkerStyleDistance)
			checkMarkerStyleDistance.SetChecked((index & 0x04) == 0);
	}
	
	void ReloadStyles() {
		if (comboPlayerlistStyleTemp && comboPlayerlistStyleTemp.GetNumItems() > 0)
			comboPlayerlistStyleTemp.SetCurrentItem(RLLayoutConfig.Get().playerlistLayoutIndex % comboPlayerlistStyleTemp.GetNumItems());
	}
	
	void ReloadColors(bool force = false) {
		TStringArray colorArray2 = RLColorManager.Get().GetColorStrings();
		if (force || colorArray2.Count() != colorArray.Count()) {
			availableColors.ClearItems();
			colorArray = colorArray2;
			foreach (string s : colorArray)
				availableColors.AddItem(s, null, 0);
			availableColors.SelectRow(0);
			availableColors.EnsureVisible(0);
		}
	}
	
	void ReloadPositions(bool force = false) {
		TStringArray positionsArray2 = RLPositionManager.Get().GetPositionStrings();
		if (force || positionsArray2.Count() != positionsArray.Count()) {
			availableLayoutsList.ClearItems();
			positionsArray = positionsArray2;
			foreach (string s : positionsArray)
				availableLayoutsList.AddItem(s, null, 0);
			availableLayoutsList.SelectRow(0);
			availableLayoutsList.EnsureVisible(0);
		}
	}
	
	void ReloadTextSizes() {
		comboboxChatSize.ClearAll();
		for (int i = CHAT_SIZE_START; i <= 25; i++) {
			comboboxChatSize.AddItem("" + i);
		}
		comboboxChatSize.SetCurrentItem(RLMarkerVisibilityManager.Get().GetChatSize() - CHAT_SIZE_START);
	}
	
	void ReloadPingSizes() {
		comboboxPingSize.ClearAll();
		for (int i = 10; i <= 30; i++) {
			comboboxPingSize.AddItem("" + i);
			i++;
		}
		int index = (RLMarkerVisibilityManager.Get().pingSize - 10) / 2;
		RLLogger.Debug("Ping Size: " + RLMarkerVisibilityManager.Get().pingSize + " -> Index: " + index, "AdvancedGroups");
		comboboxPingSize.SetCurrentItem(index);
	}
	
	override bool CanDisplayButton() {
		return !RLGroupMainConfig.Get.disableSettingsPageOnMap;
	}
	
	void ReloadPlayerSizes() {
		comboboxPlayerSize.ClearAll();
		for (int i = 10; i <= 30; i++) {
			comboboxPlayerSize.AddItem("" + i);
			i++;
		}
		int index = (RLMarkerVisibilityManager.Get().playerSize - 10) / 2;
		RLLogger.Debug("Player Size: " + RLMarkerVisibilityManager.Get().playerSize + " -> Index: " + index, "AdvancedGroups");
		comboboxPlayerSize.SetCurrentItem(index);
	}
	
	void ReloadPingMarkers(bool force = false) {
		TStringArray pingIconsArray2 = RLMarkerVisibilityManager.Get().GetPingMarkerIcons();
		if (force || pingIconsArray.Count() != pingIconsArray2.Count()) {
			comboboxPingIcon.ClearAll();
			pingIconsArray = pingIconsArray2;
			foreach (string s : pingIconsArray2)
				comboboxPingIcon.AddItem("");
			comboboxPingIcon.SetCurrentItem(0);
			string currentIcon = RLMarkerVisibilityManager.Get().GetPingMarkerIcon();
			int index = pingIconsArray.Find(currentIcon);
			if (index >= 0)
				comboboxPingIcon.SetCurrentItem(index);
			PingIconChanged();
		}
	}
	
	void ReloadPlayerMarkers(bool force = false) {
		TStringArray playerIconsArray2 = RLMarkerVisibilityManager.Get().GetPlayerMarkerIcons();
		if (force || playerIconsArray.Count() != playerIconsArray2.Count()) {
			comboboxPlayerIcon.ClearAll();
			playerIconsArray = playerIconsArray2;
			foreach (string s : playerIconsArray2)
				comboboxPlayerIcon.AddItem("");
			comboboxPlayerIcon.SetCurrentItem(0);
			string currentIcon = RLMarkerVisibilityManager.Get().GetPlayerMarkerIcon();
			int index = playerIconsArray.Find(currentIcon);
			if (index >= 0)
				comboboxPlayerIcon.SetCurrentItem(index);
			PlayerIconChanged();
		}
	}
	
	void PingIconChanged() {
		string icon = pingIconsArray.Get(comboboxPingIcon.GetCurrentItem());
		RLMarkerVisibilityManager.Get().SetPingMarkerIcon(icon);
		imagePingIcon.LoadImageFile(0, icon);
		imagePingIcon.SetColor(RLColorManager.Get().GetColor("Ping 3D Marker"));
	}
	
	void PlayerIconChanged() {
		string icon = playerIconsArray.Get(comboboxPlayerIcon.GetCurrentItem());
		RLMarkerVisibilityManager.Get().SetPlayerMarkerIcon(icon);
		imagePlayerIcon.LoadImageFile(0, icon);
		imagePlayerIcon.SetColor(RLColorManager.Get().GetColor("Player 3D Marker"));
	}
	
	override void OnHide() {
		super.OnHide();
		RLColorManager.InvokeOnChanged();
		RLPositionManager.InvokeOnChanged();
		RLLayoutConfig.InvokeGPSChanged();
	}
	
	override void OnUpdateFrame() {
		colorPreview.SetColor(GetCurrentColor());
	}
	
	void LoadColor(int color) {
		int a,r,g,b;
		RLConverter.ARGBToComponents(color, a,r,g,b);
		sliderA.SetCurrent(a);
		sliderR.SetCurrent(r);
		sliderG.SetCurrent(g);
		sliderB.SetCurrent(b);
		editA.SetText("" + ((int) sliderA.GetCurrent()));
		editR.SetText("" + ((int) sliderR.GetCurrent()));
		editG.SetText("" + ((int) sliderG.GetCurrent()));
		editB.SetText("" + ((int) sliderB.GetCurrent()));
	}
	
	void SetCurrentColor() {
		int selectedItem = availableColors.GetSelectedRow();
		if (selectedItem < 0) {
			LoadColor(-1);
			return;
		}
		RLColorManager.Get().SetColor(colorArray.Get(selectedItem), GetCurrentColor());
		imagePingIcon.SetColor(RLColorManager.Get().GetColor("Ping 3D Marker"));
		imagePlayerIcon.SetColor(RLColorManager.Get().GetColor("Player 3D Marker"));
	}
	
	void ResetToDefaultColor() {
		int selectedItem = availableColors.GetSelectedRow();
		if (selectedItem < 0) {
			LoadColor(-1);
			return;
		}
		string colorStr = colorArray.Get(selectedItem);
		RLColorManager.Get().ResetColorToDefault(colorStr);
		int color = RLColorManager.Get().GetColor(colorStr);
		LoadColor(color);
	}
	
	void ResetToDefaultPosition() {
		RLWidgetPosition position = GetCurrentPosition();
		if (!position) {
			return;
		}
		RLPositionManager.Get().ResetPositionToDefault(position.param1);
		LoadPosition();
		SetInvertBoxes(position.param3);
	}
	
	int GetCurrentColor() {
		int a = sliderA.GetCurrent();
		int r = sliderR.GetCurrent();
		int g = sliderG.GetCurrent();
		int b = sliderB.GetCurrent();
		return ARGB(a,r,g,b);
	}
	
	void LoadPosition() {
		RLWidgetPosition positionParam = GetCurrentPosition();
		if (!positionParam) {
			return;
		}
		vector pos = positionParam.param2;
		sliderX.SetCurrent(pos[0]);
		sliderY.SetCurrent(pos[1]);
		OnIconPreviewPositionChange();
	}
	
	void OnPingSizeChanged() {
		int size = comboboxPingSize.GetCurrentItem() * 2 + 10;
		RLLogger.Debug("Ping Size Index: " + comboboxPingSize.GetCurrentItem() + " -> " + size, "AdvancedGroups");
		RLMarkerVisibilityManager.Get().pingSize = size;
	}
	void OnPlayerSizeChanged() {
		int size = comboboxPlayerSize.GetCurrentItem() * 2 + 10;
		RLLogger.Debug("Player Size Index: " + comboboxPlayerSize.GetCurrentItem() + " -> " + size, "AdvancedGroups");
		RLMarkerVisibilityManager.Get().playerSize = size;
	}
	
	#ifndef RL_DISABLE_CHAT
	void OnChatSizeChanged() {
		int size = comboboxChatSize.GetCurrentItem() + CHAT_SIZE_START;
		RLMarkerVisibilityManager.Get().chatSize = size;
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		if (mission && mission.m_Chat) {
			mission.m_Chat.ResetTextSizes();
		}
	}
	#endif
	void OnIconPreviewPositionChange() {
		RLWidgetPosition positionParam = GetCurrentPosition();
		if (!positionParam) {
			return;
		}
		int index = positionParam.param3;
		float posX = sliderX.GetCurrent();
		float posY = sliderY.GetCurrent();
		vector pos = Vector(posX, posY, 0);
		RLWidgetUtils.SetWidgetPositionIndex(previewIcon, pos, index);
		RLWidgetUtils.SetWidgetAlignmentIndex(previewIcon, index);
		RLPositionManager.Get().SetPosition(positionsArray.Get(availableLayoutsList.GetSelectedRow()), pos);
	}
	
	void SetInvertBoxes(int index) {
		int value = RLWidgetUtils.FromIndex(index);
		invertX.SetChecked(!RLWidgetUtils.IsLeft(value));
		invertY.SetChecked(!RLWidgetUtils.IsTop(value));
	}
	
	void SaveInvertBoxes() {
		int index = 0;
		if (invertX.IsChecked())
			index += 2;
		if (invertY.IsChecked())
			index += 6;
		RLWidgetPosition positionParam = GetCurrentPosition();
		if (!positionParam) {
			return;
		}
		positionParam.param3 = index;
		OnIconPreviewPositionChange();
	}
	
	RLWidgetPosition GetCurrentPosition() {
		int selectedItem = availableLayoutsList.GetSelectedRow();
		if (selectedItem < 0) {
			return null;
		}
		return RLPositionManager.Get().positions.Get(selectedItem);
	}
	
	override bool OnClick(Widget w) {
		if (super.OnClick(w))
			return true;
		if (w == toDefault) {
			ResetToDefaultColor();
			return true;
		} else if (w == btn_ResetAll) {
			RLColorManager.Get().ResetAll();
			RLPositionManager.Get().ResetAll();
			RLLayoutConfig.ResetAll();
			RLMarkerVisibilityManager.Get().ResetPingToDefault();
			ReloadAll(true);
			GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Call(RLLayoutConfig.InvokeOnLayoutChanged);
			NotificationSystem.AddNotificationExtended(4.0, "#rl_message_groupSystem", "#rl_message_settingsToDefault", "set:ccgui_enforce image:MapUserMarker");
		} else if (w == btn_Save_Config) {
			RLColorManager.Get().Save();
			RLPositionManager.Get().Save();
			RLLayoutConfig.Get().Save();
			RLMarkerVisibilityManager.Get().Save();
			NotificationSystem.AddNotificationExtended(4.0, "#rl_message_groupSystem", "#rl_message_settingsSaved", "set:ccgui_enforce image:MapUserMarker");
		} else if (w == btn_Reload_From_Config) {
			RLColorManager.Reload();
			RLPositionManager.Reload();
			RLLayoutConfig.Reload();
			RLMarkerVisibilityManager.Get().ResetPingToLast();
			ReloadAll(true);
			GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Call(RLLayoutConfig.InvokeOnLayoutChanged);
			NotificationSystem.AddNotificationExtended(4.0, "#rl_message_groupSystem", "#rl_message_settingsReloaded", "set:ccgui_enforce image:MapUserMarker");
		} else if (w == invertX || w == invertY) {
			if (w == invertX) {
				sliderX.SetCurrent(1.0 - sliderX.GetCurrent());
			} else {
				sliderY.SetCurrent(1.0 - sliderY.GetCurrent());
			}
			SaveInvertBoxes();
			LoadPosition();
			return true;
		} else if (w == toDefaultPosition) {
			ResetToDefaultPosition();
			return true;
		} else if (w == toDefaultStyle) {
			
		} else if (w == chckbx_Playerlist) {
			RLMarkerVisibilityManager.Get().playerlistEnabled = !RLMarkerVisibilityManager.Get().playerlistEnabled;
		} else if (w == chckbx_Compass) {
			RLMarkerVisibilityManager.Get().compassEnabled = !RLMarkerVisibilityManager.Get().compassEnabled;
		} else if (w == chckbx_ShowClantextures) {
			RLMarkerVisibilityManager.Get().disableShowClantextures = !chckbx_ShowClantextures.IsChecked();
			ScriptRPC rpc = new ScriptRPC();
			rpc.Write(!RLMarkerVisibilityManager.Get().disableShowClantextures);
			rpc.Send(null, RLGroupRPCs.CLAN_CLOTHING_UPDATE, true);
		} else if (w == chckbx_ShowChatTag) {
			bool checked = chckbx_ShowChatTag.IsChecked();
			PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
			if (pb && pb.GetRLGroup()) {
				pb.GetRLGroup().SendChatTagVisibilityRequest(checked);
			}
		} else if (w == comboPlayerlistStyleTemp) {
			RLLayoutConfig.Get().SetPlayerlistLayout(comboPlayerlistStyleTemp.GetCurrentItem());
		} else if (w == chckbx_StreamerMode) {
			RLLayoutConfig.Get().SetStreamerMode(chckbx_StreamerMode.IsChecked());
		} else if (w == chckbx_GPS) {
			RLMarkerVisibilityManager.Get().gpsEnabled = chckbx_GPS.IsChecked();
		} else if (w == chckbx_show_no_build) {
			RLMarkerVisibilityManager.Get().showNoBuildZones = chckbx_show_no_build.IsChecked();
		} else if (w == chckbx_centerPlayer) {
			RLMarkerVisibilityManager.Get().centerMapOnPlayer = chckbx_centerPlayer.IsChecked();
		} else if (w == comboboxChatVisibility) {
			OnChannelSelected();
		} else if (w == btnToggleChatVisibility) {
			OnChannelVisibilityChangeButtonClicked();
		}
		return false;
	}
	
	override bool OnItemSelected(Widget w, int row, int column) {
		if (super.OnItemSelected(w, row, column))
			return true;
		if (w == availableColors) {
			int selectedItem = availableColors.GetSelectedRow();
			if (selectedItem < 0) {
				LoadColor(-1);
				return true;
			}
			Param2<string, int> colorParam = RLColorManager.Get().colors.Get(selectedItem);
			if (!colorParam) {
				LoadColor(-1);
				return true;
			}
			LoadColor(colorParam.param2);
			return true;
		} else if (w == availableLayoutsList) {
			RLWidgetPosition positionParam = GetCurrentPosition();
			if (!positionParam) {
				SetInvertBoxes(0);
				LoadPosition();
				return true;
			}
			SetInvertBoxes(positionParam.param3);
			LoadPosition();
			return true;
		} else if (w == availableLayoutsListStyles) {
			
		}
		return false;
	}
	
	override bool OnChange(Widget w) {
		if (super.OnChange(w))
			return true;
		bool changed = false;
		if (w == sliderA) {
			int a = sliderA.GetCurrent();
			editA.SetText(a.ToString());
			changed = true;
		} else if (w == sliderR) {
			int r = sliderR.GetCurrent();
			editR.SetText(r.ToString());
			changed = true;
		} else if (w == sliderG) {
			int g = sliderG.GetCurrent();
			editG.SetText(g.ToString());
			changed = true;
		} else if (w == sliderB) {
			int b = sliderB.GetCurrent();
			editB.SetText(b.ToString());
			changed = true;
		} else if (w == editA) {
			int txtInt = Math.Clamp(editA.GetText().ToInt(), 0, 255);
			sliderA.SetCurrent(txtInt);
			editA.SetText(txtInt.ToString());
			changed = true;
		} else if (w == editR) {
			txtInt = Math.Clamp(editR.GetText().ToInt(), 0, 255);
			sliderR.SetCurrent(txtInt);
			editR.SetText(txtInt.ToString());
			changed = true;
		} else if (w == editG) {
			txtInt = Math.Clamp(editG.GetText().ToInt(), 0, 255);
			sliderG.SetCurrent(txtInt);
			editG.SetText(txtInt.ToString());
			changed = true;
		} else if (w == editB) {
			txtInt = Math.Clamp(editB.GetText().ToInt(), 0, 255);
			sliderB.SetCurrent(txtInt);
			editB.SetText(txtInt.ToString());
			changed = true;
		} else if (w == sliderY || w == sliderX) {
			OnIconPreviewPositionChange();
		} else if (w == comboBoxStyle) {
			
		} else if (w == comboboxPingIcon) {
			PingIconChanged();
		} else if (w == comboboxPlayerIcon) {
			PlayerIconChanged();
		} else if (w == comboboxChatSize) {
			#ifndef RL_DISABLE_CHAT
			OnChatSizeChanged();
			#endif
		} else if (w == comboboxPingSize) {
			OnPingSizeChanged();
		} else if (w == comboboxPlayerSize) {
			OnPlayerSizeChanged();
		} else if (w == comboboxGPSSize) {
			int index = comboboxGPSSize.GetCurrentItem();
			RLLayoutConfig.Get().gpsSizeIndex = index;
			RLLogger.Debug("Changing GPS Size to " + index, "AdvancedGroups");
			return true;
		} else if (w == comboboxPlayerMarkerPos) {
			index = comboboxPlayerMarkerPos.GetCurrentItem();
			RLLayoutConfig.Get().playerMarkerPosIndex = index;
			RLLogger.Debug("Changing Player Marker Pos to " + index, "AdvancedGroups");
			return true;
		} else if (w == checkMarkerStyleIcon) {
			if (checkMarkerStyleIcon.IsChecked())
				RLLayoutConfig.Get().playerMarkerStyleIndex = ~(~RLLayoutConfig.Get().playerMarkerStyleIndex | 0x01);
			else
				RLLayoutConfig.Get().playerMarkerStyleIndex = (RLLayoutConfig.Get().playerMarkerStyleIndex | 0x01);
			RLLogger.Debug("Changing Player Marker Style to " + RLLayoutConfig.Get().playerMarkerStyleIndex, "AdvancedGroups");
			return true;
		} else if (w == checkMarkerStyleName) {
			if (checkMarkerStyleName.IsChecked())
				RLLayoutConfig.Get().playerMarkerStyleIndex = ~(~RLLayoutConfig.Get().playerMarkerStyleIndex | 0x02);
			else
				RLLayoutConfig.Get().playerMarkerStyleIndex = (RLLayoutConfig.Get().playerMarkerStyleIndex | 0x02);
			RLLogger.Debug("Changing Player Marker Style to " + RLLayoutConfig.Get().playerMarkerStyleIndex, "AdvancedGroups");
			return true;
		} else if (w == checkMarkerStyleDistance) {
			if (checkMarkerStyleDistance.IsChecked())
				RLLayoutConfig.Get().playerMarkerStyleIndex = ~(~RLLayoutConfig.Get().playerMarkerStyleIndex | 0x04);
			else
				RLLayoutConfig.Get().playerMarkerStyleIndex = (RLLayoutConfig.Get().playerMarkerStyleIndex | 0x04);
			RLLogger.Debug("Changing Player Marker Style to " + RLLayoutConfig.Get().playerMarkerStyleIndex, "AdvancedGroups");
			return true;
		} else if (w == sliderGPSZoom) {
			float zoom = sliderGPSZoom.GetCurrent();
			RLLayoutConfig.Get().gpsZoom = zoom;
			RLLogger.Debug("Changing GPS Zoom to: " + zoom, "AdvancedGroups");
			return true;
		}
		if (changed) {
			SetCurrentColor();
			return true;
		}
		return false;
	}
	
}
