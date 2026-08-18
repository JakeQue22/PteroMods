class RLCompassHud {

	ref Widget mainWidget;
	ref Widget compassimage;
	ref Widget compassLineWidget;
	ref Widget topMarkerWidget;
	bool canEnable = false;
	
	void RLCompassHud() {
		RLColorManager.Event_OnColorChange.Insert(OnColorChanged);
	}
	
	void ~RLCompassHud() {
		if (RLColorManager.Event_OnColorChange)
			RLColorManager.Event_OnColorChange.Remove(OnColorChanged);
		if (mainWidget) {
			mainWidget.Unlink();
			mainWidget = null;
		}
		RLMarker.compassInit = false;
	}
	
	void InitWidgets() {
		if (mainWidget || GetGame().IsServer())
			return;
		mainWidget = RLLayoutManager.Get().CreateLayout("Compass", "RayLab_Groups/gui/layouts/compass/compass_default.layout");
		ConnectClassWidgetVariables(this, mainWidget, {"mainWidget"});
		RLMarker.compassWidgetGlobal = topMarkerWidget;
		RLLogger.Debug("Initialized Compass Hud Widget", "AdvancedGroups");
		RLMarker.InitCompassWidgets();
		OnColorChanged();
	}
	
	void Show(bool b) {
		if (mainWidget)
			mainWidget.Show(b && canEnable);
	}
	
	void UpdateHud() {
		IngameHud hud = IngameHud.Cast(GetGame().GetMission().GetHud());
		if (hud) {
			bool visible = hud.IsHudVisible() &&RLMarkerVisibilityManager.Get().compassEnabled && RLUtils.IsClientPlayerAlive();
			if (GetGame().GetUIManager().GetMenu())
				visible = false;
			Show(visible);
			if (visible) {
				float angle = GetCurrentAngle();
				compassimage.SetPos((-angle / 180.0) - 1.0, 0);
				RLMarker.currentCameraAngle = angle;
			}
		} else {
			Show(false);
		}
	}
	
	void UpdateCanEnableCompass() {
		canEnable = CanEnableCompass();
		RLLogger.Debug("Can Enable Compass Check: " + canEnable, "AdvancedGroups");
	}
	
	bool CanEnableCompass() {
		if (!RLGroupMainConfig.Get.compassRequireItem)
			return true;
		RLLogger.Debug("Require Compass", "AdvancedGroups");
		PlayerBase player = PlayerBase.Cast(GetGame().GetPlayer());
		if (!player)
			return false;
		RLLogger.Debug("Player found", "AdvancedGroups");
		return HasCompassInInventory(player);
	}
	
	bool HasCompassInInventory(PlayerBase player) {
		array<EntityAI> items = new array<EntityAI>();
		player.GetInventory().EnumerateInventory(InventoryTraversalType.PREORDER ,items);
		foreach (EntityAI item : items) {
			if (!item)
				continue;
			string type = item.GetType();
			RLLogger.Debug("Item In Inventory: " + type, "AdvancedGroups");
			if (RLGroupMainConfig.Get.compassItems.Find(type) != -1)
				return true;
		}
		return false;
	}
	
	void OnColorChanged() {
		compassimage.SetColor(RLColorManager.Get().GetColor("Compass"));
		compassLineWidget.SetColor(RLColorManager.Get().GetColor("Compass Line"));
	}
	
	float GetCurrentAngle() {
		vector dir = GetGame().GetCurrentCameraDirection();
		vector angles = dir.VectorToAngles();
		if (angles[0] > 180)
			return angles[0] - 360;
		return angles[0];
	}
	
}
