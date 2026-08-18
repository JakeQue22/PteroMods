class RLPrivateMarkerManager {

	ref array<ref Param2<string, ref array<ref RLMarker>>> markers = new array<ref Param2<string, ref array<ref RLMarker>>>();
	ref array<ref RLMarker> privateMarkers = new array<ref RLMarker>();
	string currentServer;
	
	static ref RLPrivateMarkerManager g_RLPrivateMarkerManager;
	
	static void Delete() {
		if (g_RLPrivateMarkerManager)
			delete g_RLPrivateMarkerManager;
	}
	
	void ~RLPrivateMarkerManager() {
		Save();
		if (privateMarkers) {
			foreach (RLMarker marker : privateMarkers)
				delete marker;
			privateMarkers.Clear();
		}
	}
	
	static RLPrivateMarkerManager Get(string server = "") {
		if (!g_RLPrivateMarkerManager) {
			g_RLPrivateMarkerManager = Load(server);
			g_RLPrivateMarkerManager.InitMarkers();
		}
		return g_RLPrivateMarkerManager;
	}
	
	static RLPrivateMarkerManager Load(string server) {
		RLLogger.Info("Loading Private Markers for Server " + server, "AdvancedGroups");
		RLPrivateMarkerManager mgr;
		
		RayLabConfigMover.MoveFile(RLGroupConstants.SAVE_PREFIX_OLD + RLGroupConstants.SAVE_SUFFIX_PRIVATE_MARKER, RLGroupConstants.CONFIG_FOLDER);
		if (!FileExist(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_PRIVATE_MARKER)) {
			mgr = LoadDefault();
			mgr.currentServer = server;
			mgr.Save();
			return mgr;
		}
		mgr = new RLPrivateMarkerManager();
		mgr.currentServer = server;
		JsonFileLoader<ref array<ref Param2<string, ref array<ref RLMarker>>>>.JsonLoadFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_PRIVATE_MARKER, mgr.markers);
		return mgr;
	}
	
	static RLPrivateMarkerManager LoadDefault() {
		RLPrivateMarkerManager def = new RLPrivateMarkerManager;
		return def;
	}
	
	void Save() {
		RayLabConfigMover.CreateFolders(RLGroupConstants.CONFIG_FOLDER);
		JsonFileLoader<ref array<ref Param2<string, ref array<ref RLMarker>>>>.JsonSaveFile(RLGroupConstants.CONFIG_FOLDER + RLGroupConstants.SAVE_SUFFIX_PRIVATE_MARKER, markers);
	}
	
	void InitMarkers() {
		privateMarkers = GetPrivateMarkersFromList();
		foreach (RLMarker mark : privateMarkers) {
			mark.InitMarker();
			mark.AddToAllList();
		}
	}
	
	void DeleteOldDeathMarker() {
		RLMarker marker = null;
		foreach (RLMarker mar : privateMarkers) {
			if (mar && mar.creatorSteamID == "Death") {
				marker = mar;
				break;
			}
		}
		if (marker)
			RemoveMarker(marker);
	}
	
	private array<ref RLMarker> GetPrivateMarkersFromList() {
		foreach (Param2<string, ref array<ref RLMarker>> param : markers) {
			if (param.param1 == currentServer) {
				return param.param2;
			}
		}
		privateMarkers = new array<ref RLMarker>();
		markers.Insert(new Param2<string, ref array<ref RLMarker>>(currentServer, privateMarkers));
		return privateMarkers;
	}
	
	void AddMarker(RLMarker marker) {
		privateMarkers.Insert(marker);
		marker.InitMarker();
		Save();
	}
	
	void RemoveMarker(RLMarker marker) {
		privateMarkers.RemoveItem(marker);
		delete marker;
		Save();
	}
	
	RLMarker FindMarkerByUID(int uid) {
		foreach (RLMarker marker : privateMarkers) {
			if (marker.uid == uid)
				return marker;
		}
		return null;
	}
	
	bool FindMarkerByIcon(string icon, out RLMarker mark) {
		foreach (RLMarker marker : privateMarkers) {
			if (marker.icon == icon) {
				mark = marker;
				return true;
			}
		}
		return false;
	}
	
	bool FindNearestMarker(vector position, out RLMarker markero, out float distance) {
		if (privateMarkers.Count() == 0)
			return false;
		float bestDist = 0;
		RLMarker bestMarker = null;
		foreach (RLMarker marker : privateMarkers) {
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