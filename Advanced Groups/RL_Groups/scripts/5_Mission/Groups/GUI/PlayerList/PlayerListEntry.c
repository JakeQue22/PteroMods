class RLPlayerListEntry {

	ref RLPlayerList parentList;
	ref RLGroupMember member;
	
	Widget mainWidget;
	ProgressBarWidget healthbar;
	TextWidget playername, distance;
	Widget borderWidget;
	
	float lastHealth;
	
	void ~RLPlayerListEntry(){
		if (mainWidget)
			mainWidget.Unlink();
	}
	
	void Init(RLPlayerList parent, RLGroupMember groupMember) {
		parentList = parent;
		string path = RLLayoutConfig.Get().GetPlayerListLayout();
		RLLogger.Debug("Creating Player List Entry with " + path + " and Parent: " + parent, "AdvancedGroups");
		mainWidget = RLLayoutManager.Get().CreateLayout("PlayerListEntry", path, parent.listWidget);
		ConnectClassWidgetVariables(this, mainWidget, {"mainWidget"});
		Show(false);
		InitMember(groupMember);
	}
	
	void InitMember(RLGroupMember groupMember) {
		member = groupMember;
	}
	
	void UpdateWidget() {
		if (!member || !member.online) {
			Show(false);
			return;
		}
		RLLogger.Debug("Update Playerlits Widget " + member.name, "AdvancedGroups");
		Show(true);
		healthbar.SetCurrent(member.health);
		healthbar.SetColor(GetColor(member.health));
		playername.SetText(member.name);
		lastHealth = member.health;
		if (borderWidget) {
			int borderColor = RLColorManager.Get().GetColor("Playerlist entry border");
			borderWidget.SetColor(borderColor);
		}
	}
	
	void UpdateDistance() {
		if (distance) {
			if (RLGroupMainConfig.Get.enablePlayerListDistance && member && member.steamid != RLAdmins.Get().GetMySteamid()) {
				distance.Show(true);
				vector pos = GetGame().GetCurrentCameraPosition();
				float dist = vector.Distance(member.position, pos);
				if (dist < 1000) {
					distance.SetText(" " + ((int) dist) + "m");
				} else {
					float km = ((float) ((int) (dist / 100))) / 10;
					distance.SetText(" " + km + "km");
				}
			} else {
				distance.Show(false);
			}
		}
	}
	
	int GetColor(float health) {
		int colorFull = GetHealthFullColor();
		int colorZero = GetHealthEmptyColor();
		float colorPartFull = health / 100.0;
		return RLConverter.MixColors(colorFull, colorZero, colorPartFull);
	}
	
	int GetHealthEmptyColor() {
		return RLColorManager.Get().GetColor("Playerlist entry zero health");
	}
	
	int GetHealthFullColor() {
		return RLColorManager.Get().GetColor("Playerlist entry full health");
	}
	
	void Show(bool b) {
		mainWidget.Show(b);
	}

}