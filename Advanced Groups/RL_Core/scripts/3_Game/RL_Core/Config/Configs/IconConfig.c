class RLIconConfig : RLConfigLoader<RLIconConfig_> {
	
	override void InitVars() {
		InitVarsInternal("Common", "Icons.json", RLConfigType.CONFIG, true, "icons.change");
	}
	
}
class RLIconConfig_ : RLConfigBase {

	override int GetCurrentVersion() {
		return 1;
	}
	
	string error = "set:ccgui_enforce image:MapDestroyed"; // Icon for Messages, which should not appear for the normal Player, only if something is not configured properly
	string warning = "set:ccgui_enforce image:MapUnipost"; // Icon for Messages, which warn the player about something they are doing wrong
	string info = "set:ccgui_enforce image:MapUserMarker"; // Icon for Messages, which inform the player about something worked
	
	string applied = "set:ccgui_enforce image:HudEnter"; // Mostly used for Server config changes being saved
	string abort = "set:ccgui_enforce image:HudLeave"; // Icon for info messages, which are sent when something was canceled
	string building = "set:ccgui_enforce image:HudBuild"; // Icon for info messages related to (Base)building actions
	
}