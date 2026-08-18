class RLSafezoneMarkers : RLConfigLoader<RLSafezoneMarkers_> {

	override void InitVars() {
		InitVarsInternal("RLGroup", "SafezoneMarkers.json", RLConfigType.CONFIG, true, "safezonemarkers.change");
	}
	
}

class RLSafezoneMarkers_ : RLConfigBase {
	
	bool enablePlayerMarkers = false;
	bool showMarkersEverywhere = false;
	bool checkLineOfSight = false;
	bool obfuscatePlayerNames = false;
	float maxPlayerDistance = 100.0;
	string playerTagPrefix = "Player: ";
	ref RLColorConfig playerTagColor = RLColorConfig.Init(255, 51, 153, 255);
	string adminTagPrefix = "Admin: ";
	ref RLColorConfig adminTagColor = RLColorConfig.Init(255, 255, 0, 0);
	[NonSerialized()]
	ref TIntArray admins = new TIntArray();
	
	override bool OnLoad() {
		FillAdminNametagsPlayerList();
		return false;
	}
	
	override void WriteExtraCtx(ParamsWriteContext ctx) {
		super.WriteExtraCtx(ctx);
		ctx.Write(admins);
	}
	
	override bool ReadExtraCtx(ParamsReadContext ctx) {
		if (!super.ReadExtraCtx(ctx))
			return false;
		if (!ctx.Read(admins))
			return false;
		return true;
	}
	
	override int GetCurrentVersion() {
		return 1;
	}
	
	override void OnReceivedFromRPC(PlayerIdentity sender) {
		if (GetGame().IsClient())
			PlayerBase.ReinitAllSafezoneMarkers();
	}
	
	void FillAdminNametagsPlayerList() {
		TStringArray arr = RLAdmins.Get().FindAdminsWithPermission("groups.nametags.admin");
		admins = new TIntArray();
		foreach (string steamid : arr) {
			admins.Insert(steamid.Hash());
		}
	}
	
	bool IsInRadius(vector position, vector myPosition) {
		if (!enablePlayerMarkers)
			return false;
		if (vector.Distance(position, myPosition) > maxPlayerDistance)
			return false;
		if (checkLineOfSight && !DoLineOfSightCheck(position, myPosition))
			return false;
		if (showMarkersEverywhere)
			return true;
		foreach (RLServerMarker zone : RLStaticMarkerManagerClient.Get().staticMarkers) {
			if (zone.showAllPlayerNametags && zone.IsInRadius2D(position))
				return true;
		}
		return false;
	}
	
	bool DoLineOfSightCheck(vector position, vector myPosition) {
		int layer = PhxInteractionLayers.NOCOLLISION | PhxInteractionLayers.BUILDING | PhxInteractionLayers.DEFAULT | PhxInteractionLayers.VEHICLE | PhxInteractionLayers.DYNAMICITEM | PhxInteractionLayers.DYNAMICITEM_NOCHAR | PhxInteractionLayers.ROADWAY | PhxInteractionLayers.TERRAIN | PhxInteractionLayers.FENCE | PhxInteractionLayers.ITEM_SMALL | PhxInteractionLayers.ITEM_LARGE;
		Object hitObject;
		vector hitPos, hitNormal;
		float hitFraction;
		DayZPhysics.SphereCastBullet(myPosition, position, 0.10, layer, GetGame().GetPlayer(), hitObject, hitPos, hitNormal, hitFraction);
		if (!hitObject || (PlayerBase.Cast(hitObject) && hitObject != GetGame().GetPlayer())) {
			set<Object> results = new set<Object>();
			int hitComponent;
			if (DayZPhysics.RaycastRV(myPosition, position, hitPos, hitNormal, hitComponent, results, null, GetGame().GetPlayer())) {
				foreach (Object obj : results) {
					if (!PlayerBase.Cast(obj) && obj)
						return false;
				}
			}
			return true;
		}
		return false;
	}
	
	bool IsAdmin(int steamidHash) {
		return admins.Find(steamidHash) != -1;
	}
	
}