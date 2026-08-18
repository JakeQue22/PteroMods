class RLConfigLoaderBase {
	
	private string _modName, _filename, _permissionChange = "", _permissionReceive = "";
	private RLConfigType _type = RLConfigType.CONFIG;
	private bool _syncedToClients = true;
	
	void Load();
	void InitMission();
	void Reload();
	void Delete();
	void Save();
	void SendToClient(PlayerIdentity target);
	void ReadFromCtx(ParamsReadContext ctx, PlayerIdentity sender);
	string GetName();
	
	void InitVars() {
		InitVarsInternal(GetModName(), GetFilename(), GetType(), IsSyncedToClient(), GetChangePermission(), GetReceivePermission());
	}
	
	void InitVarsInternal(string modName, string filename, RLConfigType type = RLConfigType.CONFIG, bool syncedToClients = true, string permissionChange = "", string permissionReceive = "") {
		this._modName = modName;
		this._filename = filename;
		this._permissionChange = permissionChange;
		this._permissionReceive = permissionReceive;
		this._type = type;
		this._syncedToClients = syncedToClients;
	}
	
	RLConfigType GetType() {return _type;}
	string GetModName() {return _modName;}
	string GetBaseFolder() {return "$profile:RayLab/";}
	string GetFilename() {return _filename;}
	string GetChangePermission() {return _permissionChange;}
	string GetReceivePermission() {return _permissionReceive;};
	bool IsForceValid() {return true;};
	bool IsSyncedOnJoin() {return true;}
	bool IsSyncedToClient() {return _syncedToClients;}
	bool IsClientSideConfig() {return false;}
	string GetFilePath() {
		string path = GetBaseFolder();
		if (GetType() == RLConfigType.CONFIG)
			path += "Config/";
		else
			path += "Data/";
		path += GetModName() + "/" + GetFilename();
		return path;
	}
	
}