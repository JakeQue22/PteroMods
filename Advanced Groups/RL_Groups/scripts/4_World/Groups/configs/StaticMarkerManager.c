class RLStaticMarkerManager {

	ref array<ref RLServerMarker> staticMarkers = new array<ref RLServerMarker>();
	ref array<ref RLServerMarker> tempStaticMarkers = new array<ref RLServerMarker>();
	
	static ref RLStaticMarkerManager g_RLStaticMarkerManager;
	
	static RLStaticMarkerManager Get() {
		if (!g_RLStaticMarkerManager) {
			g_RLStaticMarkerManager = Load();
		}
		return g_RLStaticMarkerManager;
	}
	
	static void Delete() {
		if (g_RLStaticMarkerManager)
			delete g_RLStaticMarkerManager;
	}
	
	static RLStaticMarkerManager Load() {
		RLStaticMarkerManager markerMgr;
		RayLabConfigMover.MoveFile(RLGroupConstants.SAVE_PREFIX_OLD + RLGroupConstants.SAVE_SUFFIX_STATIC_MARKER, RLGroupConstants.CONFIG_FOLDER);
		if (!FileExist(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_STATIC_MARKER)) {
			markerMgr = LoadDefault();
			RayLabConfigMover.CreateFolders(RLGroupConstants.CONFIG_FOLDER);
			JsonFileLoader<array<ref RLServerMarker>>.JsonSaveFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_STATIC_MARKER, markerMgr.staticMarkers);
			return markerMgr;
		}
		markerMgr = new RLStaticMarkerManager();
		JsonFileLoader<array<ref RLServerMarker>>.JsonLoadFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_STATIC_MARKER, markerMgr.staticMarkers);
		return markerMgr;
	}
	
	static RLStaticMarkerManager LoadDefault() {
		RLStaticMarkerManager mgr = new RLStaticMarkerManager();
		RLServerMarker marker = new RLServerMarker();
		marker.Init("Green Mountain Trader", "3714 0 5998", "DZ\\gear\\navigation\\data\\map_tree_ca.paa", 255, 0, 255, 0);
		mgr.staticMarkers.Insert(marker);
		marker = new RLServerMarker();
		marker.Init("Toxic Zone", "1590 0 14123", "DZ\\gear\\navigation\\data\\map_tree_ca.paa", 255, 255, 0, 0);
		mgr.staticMarkers.Insert(marker);
		return mgr;
	}
	
	array<ref RLServerMarker> GetStaticMarkers() {
		return staticMarkers;
	}
	
	array<ref RLServerMarker> GetTempMarkers() {
		return tempStaticMarkers;
	}
	
	RLServerMarker FindMarker(int uid, array<ref RLServerMarker> lst) {
		foreach (RLServerMarker marker : lst) {
			if (marker && marker.uid == uid)
				return marker;
		}
		return null;
	}
	
	RLServerMarker FindAnyMarker(int uid) {
		RLServerMarker found = FindPermMarker(uid);
		if (!found)
			found = FindTempMarker(uid);
		return found;
	}
	
	RLServerMarker FindTempMarker(int uid) {
		return FindMarker(uid, tempStaticMarkers);
	}
	
	RLServerMarker FindPermMarker(int uid) {
		return FindMarker(uid, staticMarkers);
	}
	
	bool RemoveServerMarker(RLServerMarker marker) {
		return false; // Implemented in Server Side Part
	}
	
	bool RemoveServerMarker(int uid) {
		return false; // Implemented in Server Side Part
	}
	
	RLServerMarker AddTempServerMarker(string name, vector position, string icon, int color, bool toSurface = true, bool display3d = true, bool displayMap = true, bool displayGPS = true) {
		return null; // Implemented in Server Side Part
	}
	
	RLServerMarker AddPermServerMarker(string name, vector position, string icon, int color, bool toSurface = true, bool display3d = true, bool displayMap = true, bool displayGPS = true) {
		return null; // Implemented in Server Side Part
	}
	
	RLServerMarker AddTempCircleMarker(vector position, float radius, int color, bool striked) {
		RLServerMarker marker = AddTempServerMarker("", position, "DZ\\gear\\navigation\\data\\map_tree_ca.paa", ARGB(0,255,255,255));
		marker.SetRadius(radius, color, striked, true);
		return marker;
	}
	
	RLServerMarker AddPermCircleMarker(vector position, float radius, int color, bool striked) {
		RLServerMarker marker = AddPermServerMarker("", position, "DZ\\gear\\navigation\\data\\map_tree_ca.paa", ARGB(0,255,255,255));
		marker.SetRadius(radius,color, striked, true);
		return marker;
	}
	
	protected RLServerMarker AddServerMarker(string name, vector position, string icon, int color, bool toSurface, bool display3d, bool displayMap, bool displayGPS) {
		return null; // Implemented in Server Side Part
	}
	
	void SaveMarkers() {}
}

class RLStaticMarkerManagerClient {

	ref array<ref RLServerMarker> staticMarkers = new array<ref RLServerMarker>();
	
	static ref RLStaticMarkerManagerClient g_RLGroupGroups;
	
	static void Delete() {
		if (g_RLGroupGroups)
			delete g_RLGroupGroups;
	}
	
	void ~RLStaticMarkerManagerClient() {
		if (staticMarkers) {
			foreach (RLServerMarker marker : staticMarkers)
				delete marker;
			staticMarkers.Clear();
		}
	}
	
	static RLStaticMarkerManagerClient Get() {
		if (!g_RLGroupGroups) {
			g_RLGroupGroups = new RLStaticMarkerManagerClient();
			GetGame().RPCSingleParam(null, RLGroupRPCs.CONFIG_SYNC_STATIC_MARKERS, new Param1<bool>(true), true);
		}
		return g_RLGroupGroups;
	}
	
	void RequestGlobalMarkerAdd(RLMarker marker) {
		ScriptRPC rpc = new ScriptRPC();
		marker.WriteToCtx(rpc);
		rpc.Send(null, RLGroupRPCs.CONFIG_GLOBAL_MARKER_ADD, true);
	}
	
	void RequestGlobalMarkerRemove(int uid) {
		ScriptRPC rpc = new ScriptRPC();
		rpc.Write(uid);
		rpc.Send(null, RLGroupRPCs.CONFIG_GLOBAL_MARKER_REMOVE, true);
	}
	
	void RequestGlobalMarkerUpdate(int uid) {
		RLServerMarker marker = FindMarkerByUID(uid);
		if (!uid)
			return;
		ScriptRPC rpc = new ScriptRPC();
		marker.WriteToCtx(rpc);
		rpc.Send(null, RLGroupRPCs.CONFIG_GLOBAL_MARKER_CHANGE, true);
	}
	
	void StaticMarkersReceivedRPC(ParamsReadContext ctx) {
		int count;
		if (!ctx.Read(count)) {
			RLLogger.Error("Unable to receive Static Markers from Server !", "AdvancedGroups");
			return;
		}
		RLLogger.Debug("Trying to read " + count + " Static Markers from the Server", "AdvancedGroups");
		DeleteStaticMarkers();
		for (int i = 0; i < count; i++) {
			RLServerMarker mark = new RLServerMarker;
			if (mark.ReadFromCtx(ctx)) {
				AddNewMarker(mark);
				RLLogger.Debug("Received Static Marker from Server: " + mark.name + " " + mark.icon + " " + mark.colorA + " " + mark.colorR + " " + mark.colorG + " " + mark.colorB + " " + mark.CalcHash(), "AdvancedGroups");
			} else {
				RLLogger.Error("Failed to read received Markers from Server. Index: " + i, "AdvancedGroups");
				return;
			}
		}
		RLLogger.Info("Received Static Markers from Server: " + staticMarkers.Count(), "AdvancedGroups");
		
	}
	
	void StaticMarkerAddedRPC(ParamsReadContext ctx) {
		RLServerMarker mark = new RLServerMarker;
		if (!mark.ReadFromCtx(ctx))
			return;
		AddNewMarker(mark);
	}
	
	void StaticMarkerRemovedRPC(ParamsReadContext ctx) {
		int uid = 0;
		if (!ctx.Read(uid))
			return;
		DeleteMarker(uid);
	}
	
	void StaticMarkerChangedRPC(ParamsReadContext ctx) {
		RLServerMarker mark = new RLServerMarker;
		if (!mark.ReadFromCtx(ctx))
			return;
		RLServerMarker previous = FindMarkerByUID(mark.uid);
		if (!previous) {
			AddNewMarker(mark);
			return;
		}
		ChangeMarker(previous, mark);
	}
	
	void ChangeMarker(RLServerMarker myMarker, RLServerMarker from) {
		myMarker.name = from.name;
		myMarker.colorA = from.colorA;
		myMarker.colorR = from.colorR;
		myMarker.colorG = from.colorG;
		myMarker.colorB = from.colorB;
		myMarker.circleRadius = from.circleRadius;
		myMarker.circleColorA = from.circleColorA;
		myMarker.circleColorR = from.circleColorR;
		myMarker.circleColorG = from.circleColorG;
		myMarker.circleColorB = from.circleColorB;
		myMarker.circleStriked = from.circleStriked;
		myMarker.showAllPlayerNametags = from.showAllPlayerNametags;
		myMarker.icon = from.icon;
	}
	
	void MarkerRPC(ParamsReadContext ctx) {
		int uid, type_;
		if (!ctx.Read(type_) || !ctx.Read(uid))
			return;
		RLServerMarker marker = FindMarkerByUID(uid);
		RLLogger.Debug("Marker UID: " + uid + " RPC Type: " + type_ + " Marker: " + marker, "AdvancedGroups");
		if (!marker)
			return;
		marker.OnMarkerRPCClient(type_, ctx);
	}
	
	void DeleteStaticMarkers() {
		foreach (RLServerMarker marker : staticMarkers) {
			delete marker;
		}
		staticMarkers.Clear();
	}
	
	void AddNewMarker(RLServerMarker marker) {
		AddMarker(marker);
		marker.InitMarker();
	}
	
	void AddMarker(RLServerMarker marker) {
		if (!marker)
			return;
		staticMarkers.Insert(marker);
	}
	
	void DeleteMarker(RLServerMarker marker) {
		if (!marker)
			return;
		RemoveMarker(marker);
		delete marker;
	}
	
	void DeleteMarker(int uid) {
		DeleteMarker(FindMarkerByUID(uid));
	}
	
	void RemoveMarker(RLServerMarker marker) {
		staticMarkers.RemoveItem(marker);
	}
	
	RLServerMarker FindMarkerByUID(int uid) {
		foreach (RLServerMarker marker : staticMarkers) {
			if (marker.uid == uid)
				return marker;
		}
		return null;
	}
	
	bool FindNearestMarker(vector position, out RLMarker markero, out float distance) {
		if (staticMarkers.Count() == 0)
			return false;
		float bestDist = 0;
		RLMarker bestMarker = null;
		foreach (RLMarker marker : staticMarkers) {
			if (!bestMarker || vector.Distance(position, marker.position) < bestDist) {
				bestMarker = marker;
				bestDist = vector.Distance(position, marker.position) < bestDist;
			}
		}
		if (bestMarker) {
			markero = bestMarker;
			distance = bestDist;
			return true;
		}
		return false;
	}
	
}