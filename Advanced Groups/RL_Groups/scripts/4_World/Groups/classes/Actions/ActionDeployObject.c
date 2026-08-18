modded class ActionDeployObject {

	override bool SetupAction(PlayerBase player, ActionTarget target, ItemBase item, out ActionData action_data, Param extra_data = NULL) {		
		RL_NoBuildEntry zone;
		if (!(RLAdmins.Get().HasPermission("build.everywhere", player) && RLAdmins.Get().IsActive(player)) && RL_NoBuildConfig.Get.IsInZone(player.GetPosition(), zone)) {
			if (RL_NoBuildConfig.Get.ignoreItems.Find(item.GetType()) == -1) {
				string mess = RL_NoBuildConfig.Get.notificationMessage + "";
				mess.Replace("{pos}", zone.name);
				NotificationSystem.AddNotificationExtended(5, RL_NoBuildConfig.Get.notificationTitle, mess, "set:ccgui_enforce image:HudBuild");
				return false;
			}
		}
		return super.SetupAction(player, target, item, action_data, extra_data);
	}

}

modded class ActionArmExplosive {

	override bool SetupAction(PlayerBase player, ActionTarget target, ItemBase item, out ActionData action_data, Param extra_data = NULL) {		
		RL_NoBuildEntry zone;
		ItemBase obj = ItemBase.Cast(target.GetObject());
		if (!obj)
			return super.SetupAction(player, target, item, action_data, extra_data);
		if (!(RLAdmins.Get().HasPermission("build.everywhere", player) && RLAdmins.Get().IsActive(player)) && RL_NoBuildConfig.Get.IsInZone(player.GetPosition(), zone)) {
			if (RL_NoBuildConfig.Get.ignoreItems.Find(obj.GetType()) == -1) {
				string mess = RL_NoBuildConfig.Get.notificationMessage + "";
				mess.Replace("{pos}", zone.name);
				NotificationSystem.AddNotificationExtended(5, RL_NoBuildConfig.Get.notificationTitle, mess, "set:ccgui_enforce image:HudBuild");
				return false;
			}
		}
		return super.SetupAction(player, target, item, action_data, extra_data);
	}
	
}

modded class ActionActivateTrap {

	override bool SetupAction(PlayerBase player, ActionTarget target, ItemBase item, out ActionData action_data, Param extra_data = NULL) {		
		RL_NoBuildEntry zone;
		ItemBase obj = ItemBase.Cast(target.GetObject());
		if (!obj)
			return super.SetupAction(player, target, item, action_data, extra_data);
		if (!(RLAdmins.Get().HasPermission("build.everywhere", player) && RLAdmins.Get().IsActive(player)) && RL_NoBuildConfig.Get.IsInZone(player.GetPosition(), zone)) {
			if (RL_NoBuildConfig.Get.ignoreItems.Find(obj.GetType()) == -1) {
				string mess = RL_NoBuildConfig.Get.notificationMessage + "";
				mess.Replace("{pos}", zone.name);
				NotificationSystem.AddNotificationExtended(5, RL_NoBuildConfig.Get.notificationTitle, mess, "set:ccgui_enforce image:HudBuild");
				return false;
			}
		}
		return super.SetupAction(player, target, item, action_data, extra_data);
	}
	
}