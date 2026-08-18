class RLGroupMainConfig : RLConfigLoader<RLGroupMainConfig_> {

	[SetPriority(RLConfigPriority.HIGH)]
	override void InitVars() {
		InitVarsInternal("RLGroup", "MainConfig.json", RLConfigType.CONFIG, true, "group.mainconfig.change");
	}
	
}

class RLGroupMainConfig_: RLConfigBase {
	
	int configVersion = 0;
	bool canSeeOwnPlayerOnMap = true;
	bool enableKOTHMarkers = true;
	int inactiveGroupLifetimeDays = 30;
	int tacticalPingLifetimeSeconds = 8;
	int tacticalPingMaxConcurrentMarkersPerPlayer = 1;
	int inviteCooldownSeconds = 120;
	float inviteMaxDistance = -1;
	bool inviteActionEnabled = false;
	bool inviteActionShowName = false;
	ref array<ref MarkerConfigEntry> markerConfig = new array<ref MarkerConfigEntry>();
	bool addPlayerDeathMarker = true;
	bool deathMarkerPrivate = false;
	bool deathMarkerGroup = true;
	bool deleteOldDeathMarker = true;
	bool preventFriendlyFire = false;
	bool preventFriendlyFireOnlyInSameSubgroup = true;
	string deathMarkerIcon = "RayLab_Groups\\gui\\icons\\skull.paa";
	bool enableMapLegend = false;
	string mapLegendTitle = "Map Legend";
	ref array<ref MapLegendItem> mapLegend = new array<ref MapLegendItem>();
	ref TStringArray availableIcons = new TStringArray();
	bool enableCompassHud = false;
	bool compassRequireItem = false;
	ref TStringArray compassItems = new TStringArray();
	bool enableGPS = false;
	bool gpsDisplayAngle = true;
	bool gpsDisplaySpeed = true;
	bool gpsDisplayCoords = true;
	bool gpsRequireItem = false;
	ref TStringArray gpsItems = new TStringArray();
	bool mapRequireItem = false;
	ref TStringArray mapItems = new TStringArray();
	string mapNotFoundText = "Map not found !";
	string mapNotFoundImage = "RayLab_Groups/gui/images/missing.edds";
	bool gpsOnlyInVehicles = false;
	ref TStringArray vehiclesWithGPS = new TStringArray();
	bool requireItemToSeeGroupMembers = false;
	bool requireItemToBeSeenByGroupMembers = false;
	ref TStringArray playerMarkerItems = {"PersonalRadio"};
	bool groupManagePageObfuscatePlayernames = false;
	bool enablePlayerList = true;
	bool enablePlayerListDistance = true;
	bool allowJoinSecondGroupTemporarily = false;
	bool removeTemporaryMemberOnServerStart = true;
	bool removeTemporaryMemberOnPlayerLeave = false;
	bool enableSubGroups = true;
	bool enableSubGroupSharedPlayerMapMarker = false;
	bool enableSubGroupSharedPingMapMarker = false;
	bool enableInfoPanelSurvivorCount = true;
	bool enableInfoPanelCursorCoordinates = true;
	bool enableUIPlayerPosition = true;
	bool enableInfoPanelIngameTime = true;
	bool enableInfoPanelRealTime = true;
	bool disableInfoPanelModCreatorMention = false;
	bool disableMarkerPageOnMap = false;
	bool disableSettingsPageOnMap = false;
	bool disableGroupPageOnMap = false;
	bool disableMarkerPlacement = false;
	int groupMarkerLimit = 20;
	float offlinePlayer3dMarkerDistance = 20.0;
	ref TStringArray subGroupNames = new TStringArray();
	ref array<ref RLButtonConfig> buttonConfig = new array<ref RLButtonConfig>();
	[NonSerialized()]
	int groupCreationCost = 0;
	[NonSerialized()]
	private int obfuscationInit = 0;
	[NonSerialized()]
	bool groupCreationEnabled = true;
	
	override int GetCurrentVersion() {
		return 22;
	}
	
	override void WriteExtraCtx(ParamsWriteContext ctx) {
		super.WriteExtraCtx(ctx);
		ctx.Write(groupCreationCost);
		ctx.Write(obfuscationInit);
		ctx.Write(groupCreationEnabled);
	}
	
	override bool ReadExtraCtx(ParamsReadContext ctx) {
		if (!super.ReadExtraCtx(ctx))
			return false;
		if (!ctx.Read(groupCreationCost))
			return false;
		if (!ctx.Read(obfuscationInit))
			return false;
		// if (!ctx.Read(groupCreationEnabled))
		// 	return false;
		return true;
	}
	
	override bool OnLoad() {
		RLConfigManager.Get().LoadImmediate("RLGroupPermissions_");
		RLConfigManager.Get().LoadImmediate("RLTerritoryConfig_");
		RLConfigManager.Get().LoadImmediate("RL_Webhook_Manager_");
		InitObfuscationRandom();
		SetGroupCreationCost();
		groupCreationEnabled = IsGroupCreationEnabled();
		if (configVersion != 0) {
			configVersion = 0;
			return true;
		}
		return false;
	}
	
	void InitObfuscationRandom() {
		int hour,min,sec;
		GetHourMinuteSecond(hour, min, sec);
		obfuscationInit = hour * 3600 + min * 60 + sec;
	}
	
	override void UpdateVersion() {
		if (version < 3) {
		}
		if (version < 4) {
			enablePlayerListDistance = true;
		}
		if (version < 5) {
			inviteMaxDistance = -1;
			inviteActionEnabled = false;
			inviteActionShowName = false;
		}
		if (version < 6) {
			enableKOTHMarkers = true;
		}
		if (version < 7) {
			enableGPS = false;
			gpsDisplayAngle = true;
			gpsDisplaySpeed = true;
			gpsDisplayCoords = true;
			gpsRequireItem = false;
			gpsItems = new TStringArray();
			gpsItems.Insert("GPSReceiver");
			gpsOnlyInVehicles = false;
			vehiclesWithGPS = new TStringArray();
			vehiclesWithGPS.Insert("CivilianSedan");
			foreach (MarkerConfigEntry marker : markerConfig) {
				marker.displayGPS = marker.displayMap;
			}
			compassRequireItem = false;
			compassItems = new TStringArray();
			compassItems.Insert("Compass");
			enableUIPlayerPosition = true;
		}
		if (version < 8) {
			availableIcons.Insert("RayLab_Groups\\gui\\icons\\circle.paa");
			availableIcons.Insert("RayLab_Groups\\gui\\icons\\safezone.paa");
			availableIcons.Insert("RayLab_Groups\\gui\\icons\\blackmarket.paa");
			availableIcons.Insert("RayLab_Groups\\gui\\icons\\sniper.paa");
			availableIcons.Insert("RayLab_Groups\\gui\\icons\\player.paa");
		}
		if (version < 9) {
			addPlayerDeathMarker = false;
			deathMarkerPrivate = false;
			deathMarkerGroup = true;
			deleteOldDeathMarker = true;
			deathMarkerIcon = "RayLab_Groups\\gui\\icons\\skull.paa";
			groupManagePageObfuscatePlayernames = false;
		}
		if (version < 10) {
			enableMapLegend = false;
			mapLegend = new array<ref MapLegendItem>();
			mapLegend.Insert(new MapLegendItem("RayLab_Groups\\gui\\icons\\safezone.paa", "Safezone"));
			mapLegend.Insert(new MapLegendItem("RayLab_Groups\\gui\\icons\\blackmarket.paa", "Blackmarket"));
		}
		if (version < 11) {
			mapRequireItem = false;
			mapItems = new TStringArray();
			mapItems.Insert("ChernarusMap");
		}
		if (version < 12) {
			mapNotFoundText = "Map not found !";
			mapNotFoundImage = "RayLab_Groups/gui/images/missing.edds";
		}
		if (version < 13) {
			mapLegendTitle = "Map Legend";
		}
		if (version < 14) {
			RLGroupPermission member = RLGroupPermissions.Get.FindPermissionGroupByUID(5);
			if (member) {
				member.canOpenGroupGarage = true;
				RLGroupPermissions.Get.LoadInheritence();
			}
			RLGroupPermissions.Loader.Save();
		}
		if (version < 15) {
			RLStaticMarkerManager.Get().SaveMarkers();
		}
		if (version < 16) {
			tacticalPingMaxConcurrentMarkersPerPlayer = 1;
			SaveLevels();
		}
		if (version < 18) {
			RLGroupPermissions.Loader.Save();
		}
		if (version < 20) {
			requireItemToSeeGroupMembers = false;
			requireItemToBeSeenByGroupMembers = false;
			playerMarkerItems = {"GPSReceiver"};
		}
		if (version < 22) {
			removeTemporaryMemberOnServerStart = true;
		}
		
	}
	
	void SaveLevels() {
	}
	
	override void LoadDefault() {
		canSeeOwnPlayerOnMap = true;
		inactiveGroupLifetimeDays = 30;
		
		
		enableGPS = true;
		gpsDisplayAngle = true;
		gpsDisplaySpeed = true;
		gpsDisplayCoords = true;
		gpsRequireItem = false;
		gpsItems = new TStringArray();
		gpsItems.Insert("Compass");
		gpsItems.Insert("PersonalRadio");
		gpsOnlyInVehicles = false;
		vehiclesWithGPS = new TStringArray();
		vehiclesWithGPS.Insert("CivilianSedan");
		compassRequireItem = false;
		compassItems = new TStringArray();
		compassItems.Insert("Compass");
		
	
		mapRequireItem = false;
		mapItems = new TStringArray();
		mapItems.Insert("ChernarusMap");
		
		enableKOTHMarkers = true;
		enableCompassHud = true;
		enablePlayerList = true;
		enablePlayerListDistance = true;
		enableSubGroups = true;
		enableSubGroupSharedPlayerMapMarker = false;
		enableSubGroupSharedPingMapMarker = false;
		enableInfoPanelSurvivorCount = true;
		enableInfoPanelCursorCoordinates = true;
		enableInfoPanelIngameTime = true;
		enableInfoPanelRealTime = true;
		disableInfoPanelModCreatorMention = false;
		offlinePlayer3dMarkerDistance = 20.0;
		subGroupNames.Insert("Alpha");
		subGroupNames.Insert("Bravo");
		subGroupNames.Insert("Charlie");
		subGroupNames.Insert("Delta");
		subGroupNames.Insert("Echo");
		subGroupNames.Insert("Foxtrot");
		subGroupNames.Insert("Golf");
		subGroupNames.Insert("Hotel");
		subGroupNames.Insert("India");
		subGroupNames.Insert("Juliett");
		markerConfig.Insert(MarkerConfigEntry.Init(RLMarkerType.SERVER_STATIC, -1, true, true, true, false, true));
		markerConfig.Insert(MarkerConfigEntry.Init(RLMarkerType.SERVER_DYNAMIC, -1, true, true, true, false, true));
		markerConfig.Insert(MarkerConfigEntry.Init(RLMarkerType.GROUP_PING, -1, true, false, false, true, false));
		markerConfig.Insert(MarkerConfigEntry.Init(RLMarkerType.GROUP_MARKER, -1, true, true, true, false, true));
		markerConfig.Insert(MarkerConfigEntry.Init(RLMarkerType.PRIVATE_MARKER, -1, true, true, true, false, true));
		markerConfig.Insert(MarkerConfigEntry.Init(RLMarkerType.GROUP_PLAYER_MARKER, 2000, true, false, true, true, true));
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\marker.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\marker-stroked.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\cross.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\home.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\camp.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\safezone.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\blackmarket.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\hospital.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\sniper.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\player.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\flag.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\star.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\car.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\parking.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\heli.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\rail.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\ship.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\scooter.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\bank.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\restaurant.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\post.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\castle.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\ranger-station.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\water.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\triangle.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\cow.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\bear.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\car-repair.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\communications.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\roadblock.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\stadium.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\skull.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\rocket.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\bbq.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\ping.paa");
		availableIcons.Insert("RayLab_Groups\\gui\\icons\\circle.paa");
		buttonConfig.Insert(RLButtonConfig.InitButton("Website", "musterseite.de", "google.com"));
		buttonConfig.Insert(RLButtonConfig.InitButton("Teamspeak", "teamspeak.com", "ts3server://teamspeak.com"));
		buttonConfig.Insert(RLButtonConfig.InitButton("Donate", "Donate via PayPal", "paypal.me/RayLab"));
		
		
		enableMapLegend = false;
		mapLegend = new array<ref MapLegendItem>();
		mapLegend.Insert(new MapLegendItem("RayLab_Groups\\gui\\icons\\safezone.paa", "Safezone"));
		mapLegend.Insert(new MapLegendItem("RayLab_Groups\\gui\\icons\\blackmarket.paa", "Blackmarket"));
		mapNotFoundText = "Map not found !";
		mapNotFoundImage = "RayLab_Groups/gui/images/missing.edds";
	}
	
	void SetGroupCreationCost() {
	}
	
	MarkerConfigEntry GetMarkerConfigEntry(RLMarkerType type) {
		foreach (MarkerConfigEntry entry : markerConfig) {
			if (entry.type == type)
				return entry;
		}
		return null;
	}
	
	bool IsGroupCreationEnabled() {
		return true;
	}
	
	bool IsCompassVisible(RLMarkerType type) {
		MarkerConfigEntry cfgEntry = GetMarkerConfigEntry(type);
		if (!cfgEntry)
			return false;
		return cfgEntry.displayCompass;
	}
	
	bool CanUsePing() {
		MarkerConfigEntry pingEntry = GetMarkerConfigEntry(RLMarkerType.GROUP_PING);
		if (!pingEntry)
			return false;
		return pingEntry.maxDistance != 0 && (pingEntry.display3d || pingEntry.displayMap || pingEntry.displayCompass || pingEntry.displayGPS);
	}
	
	void PrintMarkerConfigEntries() {
		RLLogger.Debug("Marker config Entires: " + markerConfig.Count(), "AdvancedGroups");
		foreach (MarkerConfigEntry entry : markerConfig) {
			RLLogger.Debug("" + entry.type + " " + entry.maxDistance + " " + entry.display3d + " "  + entry.displayDistance + " "  + entry.displayMap + " "  + entry.displayGPS, "AdvancedGroups");
		}
	}
	
	string ObfuscatePlayerName(PlayerIdentity identity) {
		return ObfuscatePlayerName(identity.GetName(), identity.GetPlainId().Hash());
	}
	
	string ObfuscatePlayerName(PlayerBase player) {
		string name = player.GetIdentity().GetName();
		int steamidHash = player.steamidHash;
		return ObfuscatePlayerName(name, steamidHash);
	}
	
	string ObfuscatePlayerName(string name, int steamidHash) {
		return "" + (((name + steamidHash) + obfuscationInit).Hash() * 54654545);
	}
}
class MarkerConfigEntry {
	
	RLMarkerType type;
	string typeString;
	int maxDistance;
	bool display3d;
	bool displayDistance;
	bool displayMap;
	bool displayCompass;
	bool displayGPS;
	
	static MarkerConfigEntry Init(RLMarkerType type2, int maxDist, bool disp3d, bool dispDist, bool dispMap, bool dispComp, bool dispGPS) {
		MarkerConfigEntry ent = new MarkerConfigEntry;
		ent.type = type2;
		ent.typeString = typename.EnumToString(RLMarkerType, type2);
		ent.maxDistance = maxDist;
		ent.display3d = disp3d;
		ent.displayDistance = dispDist;
		ent.displayMap = dispMap;
		ent.displayCompass = dispComp;
		ent.displayGPS = dispGPS;
		return ent;
	}
	
}