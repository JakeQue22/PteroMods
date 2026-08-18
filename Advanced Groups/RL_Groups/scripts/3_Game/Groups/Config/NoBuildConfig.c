class RL_NoBuildConfig : RLConfigLoader<RL_NoBuildConfig_> {

	override void InitVars() {
		InitVarsInternal("RLGroup", "NoBuildZones.json", RLConfigType.CONFIG, true, "nobuild.change");
	}

}

class RL_NoBuildConfig_: RLConfigBase {
	
	bool enabled = false; // Enabled (1) or Disable (0) the whole system
	bool displayOnMap = true; // Set to 1 if players can see the zones on the map (they can turn them off in the client settings to hide them). Or set it to 0 to hide all zones for all players. No player will be able to enable the zones for them then
	string notificationTitle = "No Build Zones"; // Title of the notification when a player cannot build there
	string notificationMessage = "You cannot build near {pos}"; // The text of the notification. `{pos}` will be replaced with the name of the current Zone the player is in
	ref TStringArray ignoreItems = new TStringArray(); // Items which can be placed inside of No Build Zones. For example Traps
	int circleColorR = 255; // Red Color of the Circles shown on the map
	int circleColorG = 100; // Green Color of the Circles shown on the map
	int circleColorB = 50; // Blue Color of the Circles shown on the map
	ref array<ref RL_NoBuildEntry> zones = new array<ref RL_NoBuildEntry>(); // List of all no build zones
	
	override int GetCurrentVersion() {
		return 2;
	}
	
	override void LoadDefault() {
		ignoreItems.Insert("LandMineTrap");
		ignoreItems.Insert("BearTrap");
		ignoreItems.Insert("Fireplace");
	}
	
	override void UpdateVersion() {
		if (version < 2) {
			circleColorR = 255;
			circleColorG = 100;
			circleColorB = 50;
		}
	}
	
	bool ShowOnMap() {
		return enabled && displayOnMap;
	}
	
	bool IsInZone(vector v, out RL_NoBuildEntry zone_) {
		if (!enabled)
			return false;
		foreach (RL_NoBuildEntry zone : zones) {
			if (zone && zone.InZone(v)) {
				zone_ = zone;
				return true;
			}
		}
		return false;
	}
	
	int GetCircleColor() {
		return ARGB(200, circleColorR, circleColorG, circleColorB);
	}
	
}
class RL_NoBuildEntry {

	string name; // Name of this position. This will replace the `{pos}` placeholder when sending the error message to the player
	float x; // X position of the Zone
	float y; // Y position (or rather the Z coordinate) of the Zone
	float r; // Radius of the Zone
	
	bool InZone(vector v) {
		float xDiff = v[0] - x;
		float yDiff = v[2] - y;
		float dist = Math.Sqrt(xDiff * xDiff + yDiff * yDiff);
		return dist <= r;
	}

	void WriteToCtx(ParamsWriteContext ctx) {
		ctx.Write(name);
		ctx.Write(x);
		ctx.Write(y);
		ctx.Write(r);
	}
	
	bool ReadFromCtx(ParamsReadContext ctx) {
		if (!ctx.Read(name))
			return false;
		if (!ctx.Read(x))
			return false;
		if (!ctx.Read(y))
			return false;
		if (!ctx.Read(r))
			return false;
		return true;
	}
	
}