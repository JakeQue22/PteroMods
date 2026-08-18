class RLMarker {
	
	static ref array<RLMarker> allMarkers = new array<RLMarker>();
	static bool hideAllMarkers = false;

	RLMarkerType type;
	int uid;
	string name;
	string icon;
	vector position = vector.Zero;
	int currentSubgroup;
	int colorA = 255, colorR = 255, colorG = 255, colorB = 255;
	string creatorSteamID = "";
	float circleRadius = 0;
	int circleColorA = 255, circleColorR = 255, circleColorG = 255, circleColorB = 255;
	bool circleStriked = false;
	bool showAllPlayerNametags;
	
	[NonSerialized()]
	static bool streamerMode;
	[NonSerialized()]
	bool visibleOnScreen = false;
	[NonSerialized()]
	Widget mainWidget;
	[NonSerialized()]
	Widget bottomWidget;
	[NonSerialized()]
	ImageWidget iconWidget;
	[NonSerialized()]
	TextWidget nameWidget;
	[NonSerialized()]
	TextWidget distanceWidget;
	[NonSerialized()]
	RLGroup parentGroup;
	[NonSerialized()]
	ref MarkerConfigEntry cachedMarkerConfig = null;
	[NonSerialized()]
	float dist;
	[NonSerialized()]
	bool showMap = true;
	[NonSerialized()]
	bool showGPS = true;
	[NonSerialized()]
	bool show3D = true;
	[NonSerialized()]
	bool disable3dDifferentSubgroup = false;
	[NonSerialized()]
	int state = 0;
	[NonSerialized()]
	int lastcolor = 0;
	[NonSerialized()]
	bool hidden = false;
	
	[NonSerialized()]
	Widget compassWidget;
	[NonSerialized()]
	ImageWidget compassIconWidget;
	[NonSerialized()]
	TextWidget compassNameWidget;
	[NonSerialized()]
	bool createdCompassWidget = false;
	
	static bool compassInit = false;
	static float currentCameraAngle = 0.0;
	static Widget compassWidgetGlobal;
	
	static void InitCompassWidgets() {
		compassInit = true;
		if (allMarkers) {
			foreach (RLMarker marker : allMarkers) {
				if (marker) {
					marker.InitCompassWidget();
				}
			}
		}
	}
	
	static void UpdateAllMarkers() {
		if (allMarkers) {
			foreach (RLMarker marker : allMarkers) {
				if (marker) {
					if (!hideAllMarkers && marker.UpdateMarkerClient())
						marker.SetWidgetPosition();
					else {
						marker.SetVisibleOnScreen(false);
						if (marker.compassWidget)
							marker.compassWidget.Show(false);
					}
				}
			}
		}
	}
	
	static void UpdateAllMarkersSlow() {
		streamerMode = RLLayoutConfig.Get().streamerModeEnabled;
		if (allMarkers) {
			//RLLogger.Debug("UpdateAllMarkersSlow " + allMarkers.Count());
			foreach (RLMarker marker : allMarkers) {
				if (marker) {
					marker.UpdateMarkerSlow();
				}
			}
		}
	}
	
	void SetupMarker(RLMarkerType type_, string name_, string icon_, vector pos_) {
		this.type = type_;
		this.name = name_;
		this.icon = icon_;
		this.position = pos_;
		this.uid = Math.RandomInt(200, int.MAX - 1);
	}
	
	void InitMarker() {
		if (GetMarkerConfig() && !GetMarkerConfig().display3d)
			return;
		if (mainWidget || GetGame().IsServer())
			return;
		mainWidget = RLLayoutManager.Get().CreateLayout("3DMarker", "RayLab_Groups/gui/layouts/3dmarker.layout", null);
		if (mainWidget) {
			iconWidget = ImageWidget.Cast(mainWidget.FindAnyWidget("icon"));
			nameWidget = TextWidget.Cast(mainWidget.FindAnyWidget("name"));
			distanceWidget = TextWidget.Cast(mainWidget.FindAnyWidget("distance"));
			bottomWidget = mainWidget.FindAnyWidget("bottom");
			
			if (GetIcon().Length() > 0) {
				RLLogger.Debug("Loading Image: " + GetIcon(), "AdvancedGroups");
				iconWidget.LoadImageFile(0, GetIcon());
			} else {
				iconWidget.Show(false);
			}
			if (type != RLMarkerType.GROUP_PING) {
				nameWidget.SetText(name);
			} else {
				nameWidget.SetText("");
				nameWidget.Show(false);
				float pingSize = RLMarkerVisibilityManager().Get().pingSize;
				iconWidget.SetSize(pingSize,pingSize);
			}
			mainWidget.Update();
			UpdateDistance();
			
			SetColor(true);
		}
		SetVisibleOnScreen(false);
		string printname = name + "";
		printname.Replace("%", "");
		RLLogger.Debug("Init Marker " + printname + ". Created Layout: " + (mainWidget != null), "AdvancedGroups");
		UpdateMarkerSlow();
	}
	
	void InitCompassWidget() {
		if (createdCompassWidget || !GetMarkerConfig())
			return;
		createdCompassWidget = true;
		if (!GetMarkerConfig().displayCompass)
			return;
		if (compassWidget || GetGame().IsServer())
			return;
		if (!compassInit)
			return;
		compassWidget = RLLayoutManager.Get().CreateLayout("CompassMarker", "RayLab_Groups/gui/layouts/compass/compassMarker_default.layout", compassWidgetGlobal);
		if (compassWidget) {
			compassIconWidget = ImageWidget.Cast(compassWidget.FindAnyWidget("icon"));
			compassNameWidget = TextWidget.Cast(compassWidget.FindAnyWidget("name"));
			if (GetIcon().Length() > 0) {
				RLLogger.Debug("Loading Image: " + GetIcon(), "AdvancedGroups");
				compassIconWidget.LoadImageFile(0, GetIcon());
			} else {
				compassIconWidget.Show(false);
			}
			compassNameWidget.SetText(name);
			compassWidget.Show(false);
			SetColor(true);
		}
	
	}
	
	string GetIcon() {
		return icon;
	}
	
	bool SetIcon(string icon_) {
		if (this.icon != icon_) {
			this.icon = icon_;
			if (iconWidget)
				iconWidget.LoadImageFile(0, icon);
			return true;
		}
		return false;
	}
	
	bool IsGroupMarker() {
		return type == RLMarkerType.GROUP_PING || type == RLMarkerType.GROUP_PLAYER_MARKER;
	}
	
	void UpdateMarkerSlow() {
		if (!RLGroupMainConfig.Get) {
			return;
		}
		if (RLGroupMainConfig.Get.enableCompassHud)
			InitCompassWidget();
		SetColor();
		if (RLGroupMainConfig.Get.enableSubGroups && IsGroupMarker()) {
			PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
			if (!pb)
				return;
			int mySubgroup = pb.GetMySubGroup();
			disable3dDifferentSubgroup = mySubgroup != currentSubgroup;
			if (disable3dDifferentSubgroup)
				return;
		}
		disable3dDifferentSubgroup = false;
		showMap = ShowMarkerMapOrGPS(true);
		showGPS = ShowMarkerMapOrGPS(false);
		show3D = ShowMarker3D();
		//RLLogger.Debug("Show Marker: " + name + ": " + show, "AdvancedGroups");
	}
	
	void OnMarkerRPCClient(int type_, ParamsReadContext ctx) {
		if (type_ == RLGroupRPCs.POSITION) {
			float x,y,z;
			if (!ctx.Read(x) || !ctx.Read(y) || !ctx.Read(z))
				return;
			vector vec = Vector(x,y,z);
			SetPosition(vec);
		} else if (type_ == RLGroupRPCs.SUBGRUOP) {
			int grp = 0;
			if (!ctx.Read(grp))
				return;
			RLLogger.Debug("Setting new Subgroup: " + grp, "AdvancedGroups");
			SetSubGroup(grp);
		} else if (type_ == RLGroupRPCs.NAME) {
			string name_;
			if (!ctx.Read(name_))
				return;
			SetName(name_);
		} else if (type_ == RLGroupRPCs.COLOR) {
			int a,r,g,b;
			if (!ctx.Read(a) || !ctx.Read(r) || !ctx.Read(g) || !ctx.Read(b))
				return;
			SetColorARGB(a,r,g,b);
			SetColor(true);
		} else if (type_ == RLGroupRPCs.CIRCLE) {
			float radius;
			int ca, cr, cg, cb;
			bool striked;
			RLLogger.Debug("Reading new Marker Circle Info", "AdvancedGroups");
			if (!ctx.Read(radius) || !ctx.Read(ca) || !ctx.Read(cr) || !ctx.Read(cg) || !ctx.Read(cb) || !ctx.Read(striked)) {
				RLLogger.Error("Failed to read new Server Marker Circle Info", "AdvancedGroups");
				return;
			}
			SetRadius(radius, ca, cr, cg, cb, striked, false);
		} else if (type_ == RLGroupRPCs.VISIBILITY) {
			bool hidden_ = false;
			if (!ctx.Read(hidden_))
				return;
			SetHidden(hidden_);
		}
	}
	
	void AddToAllList() {
		if (allMarkers)
			allMarkers.Insert(this);
	}
	
	void RemoveFromAllList() {
		if (allMarkers)
			allMarkers.RemoveItem(this);
	}
	
	void RLMarker() {
		AddToAllList();
	}
	
	void SetSubGroup(int grp) {
		currentSubgroup = grp;
	}
	
	void SetPosition(vector pos) {
		position = pos;
		if (type == RLMarkerType.PRIVATE_MARKER) {
			RLPrivateMarkerManager.Get().Save();
		}
	}
	
	void SendPositionToServer() {
		ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.POSITION);
		rpc.Write(position[0]);
		rpc.Write(position[1]);
		rpc.Write(position[2]);
		SendMarkerRPC(rpc);
	}
	
	void SetRadius(float radius, bool sync = true) {
		if (this.circleRadius == radius)
			return;
		if (GetGame().IsDedicatedServer() || !sync) {
			this.circleRadius = radius;
		}
		if (sync) {
			SyncRadiusServer(radius, circleColorA, circleColorR, circleColorG, circleColorB, circleStriked);
		}
	}
	
	bool SetRadius(float radius, int cColorA, int cColorR, int cColorG, int cColorB, bool striked, bool sync = true) {
		bool changed = circleRadius != radius || circleColorA != cColorA || circleColorR != cColorR || circleColorG != cColorG || circleColorB != cColorB || striked != circleStriked;
		RLLogger.Debug("SetRadius: " + changed + " Radius: " + radius + " A:" + cColorA + " R:" + cColorR + " G:" + cColorG + " B:" + cColorB + " Striked:" + striked + " Sync: " + sync, "AdvancedGroups");
		if (!changed)
			return false;
		if (GetGame().IsDedicatedServer() || !sync) {
			this.circleStriked = striked;
			this.circleRadius = radius;
			this.circleColorA = cColorA;
			this.circleColorR = cColorR;
			this.circleColorG = cColorG;
			this.circleColorB = cColorB;
		}
		if (sync) {
			SyncRadiusServer(radius, cColorA, cColorR, cColorG, cColorB, striked);
		}
		return true;
	}
	
	bool SetHidden(bool hidden_) {
		bool oldHidden = this.hidden;
		this.hidden = hidden_;
		return oldHidden != this.hidden;
	}
	
	bool SetRadius(float radius, int color, bool striked, bool sync = true) {
		int a,r,g,b;
		RLConverter.ARGBToComponents(color, a,r,g,b);
		return SetRadius(radius, a,r,g,b, striked, sync);
	}
	
	int GetCircleColor() {
		return ARGB(circleColorA, circleColorR, circleColorG, circleColorB);
	}
	
	void SyncRadiusServer(float radius, int ca, int cr, int cg, int cb, bool striked) {
		ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.CIRCLE);
		rpc.Write(radius);
		rpc.Write(ca);
		rpc.Write(cr);
		rpc.Write(cg);
		rpc.Write(cb);
		rpc.Write(striked);
		SendMarkerRPC(rpc);
	}
	
	bool SetName(string name_) {
		if (this.name != name_) {
			this.name = name_;
			if (nameWidget)
				nameWidget.SetText(name);
			return true;
		}
		return false;
	}
	
	void ~RLMarker() {
		if (allMarkers)
			allMarkers.RemoveItem(this);
		if (mainWidget) {
			mainWidget.Unlink();
			mainWidget = null;
		}
		if (compassWidget) {
			compassWidget.Unlink();
			compassWidget = null;
		}
	}
	
	void SetColor(bool force = false) {
		int rgb = Get3DColorARGB();
		if (force || lastcolor != rgb) {
			if (iconWidget)
				iconWidget.SetColor(rgb);
			if (nameWidget)
				nameWidget.SetColor(rgb);
			if (compassNameWidget && compassIconWidget) {
				compassNameWidget.SetColor(rgb);
				compassIconWidget.SetColor(rgb);
			}
			if (distanceWidget) {
				distanceWidget.SetColor(ARGB(colorA, 255, 255, 255));
			}
			lastcolor = rgb;
		}
	}
	
	bool SetColorInt(int argb) {
		int a, r, g, b;
		RLConverter.ARGBToComponents(argb, a, r, g, b);
		return SetColorARGB(a, r, g, b);
	}
	
	bool SetColorARGB(int a, int r, int g, int b) {
		if (colorA != a || colorR != r || colorG != g || colorB != b) {
			colorA = a;
			colorR = r;
			colorG = g;
			colorB = b;
			return true;
		}
		return false;
	}
	
	bool SetColorARGBGlobal(int a, int r, int g, int b) {
		if (SetColorARGB(a,r,g,b)) {
			ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.COLOR);
			rpc.Write(a);
			rpc.Write(r);
			rpc.Write(g);
			rpc.Write(b);
			RLLogger.Debug("Sending Color Change: " + a + " " + r + " " + g + " " + b, "AdvancedGroups");
			SendMarkerRPC(rpc);
			return true;
		}
		return false;
	}
	
	bool SetIconGlobal(string icon_) {
		if (SetIcon(icon_)) {
			ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.ICON);
			rpc.Write(icon_);
			SendMarkerRPC(rpc);
			return true;
		}
		return false;
	}
	
	bool SetNameGlobal(string name_) {
		if (SetName(name_)) {
			ScriptRPC rpc = CreateRPCCall(RLGroupRPCs.NAME);
			rpc.Write(name_);
			SendMarkerRPC(rpc);
			return true;
		}
		return false;
	}
	
	MarkerConfigEntry GetMarkerConfig() {
		if (!RLGroupMainConfig.Get)
			return null;
		if (cachedMarkerConfig)
			return cachedMarkerConfig;
		cachedMarkerConfig = RLGroupMainConfig.Get.GetMarkerConfigEntry(type);
		return cachedMarkerConfig;
	}
	float GetCompassPosY() {
		if (type != RLMarkerType.GROUP_PING)
			return 0;
		return 0.5;
	}
	
	void SetDistance() {
		if (!GetGame() || !GetGame().GetPlayer() || !ShowDistance3D())
			return;
		vector pos = GetGame().GetCurrentCameraPosition();
		dist = vector.Distance(position, pos);
		if (dist < 1000) {
			distanceWidget.SetText("" + ((int) dist) + "m");
		} else {
			float km = ((float) ((int) (dist / 100))) / 10;
			distanceWidget.SetText("" + km + "km");
		}
	}
	
	bool IsInRadius2D(vector position_) {
		float radiusSquared = circleRadius * circleRadius;
		float dX = position_[0] - position[0];
		float dZ = position_[2] - position[2];
		float distSquared = dX * dX + dZ * dZ;
		RLLogger.Verbose("Radius Check for " + name + ": " + radiusSquared + " " + dX + " " + dZ + " " + distSquared, "AdvancedGroups");
		return distSquared <= radiusSquared;
	}
	
	bool ShowMarkerMapOrGPS(bool isMap) {
		if (hidden)
			return false;
		if (streamerMode && (type == RLMarkerType.SERVER_STATIC || type == RLMarkerType.SERVER_DYNAMIC))
			return false;
		if ((isMap && !GetMarkerConfig().displayMap) || (!isMap  && !GetMarkerConfig().displayGPS))
			return false;
		if (!RLMarkerVisibilityManager.Get().IsMapVisible(uid, type, false))
			return false;
		if (type != RLMarkerType.GROUP_PING && !RLMarkerVisibilityManager.Get().IsGlobal2DVisible(type) || type == RLMarkerType.GROUP_PING && !RLMarkerVisibilityManager.Get().IsGlobal2DVisible(RLMarkerType.GROUP_MARKER))
			return false;
		RLServerMarker serverMarker;
		if (Class.CastTo(serverMarker, this)) {
			return ((isMap && serverMarker.displayMap) || (!isMap  && serverMarker.displayGPS));
		}
		if (type == RLMarkerType.GROUP_PING || type == RLMarkerType.GROUP_MARKER || type == RLMarkerType.GROUP_PLAYER_MARKER) {
			
			PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
			if (!pb || !pb.GetRLGroup())
				return false;
			RLGroup grp = pb.GetRLGroup();
			RLGroupPermission myPerm = pb.GetPermission();
			if (!myPerm)
				return false;
			
			if (!myPerm.CanSeeMarkerType(type))
				return false;
			int mySubgroup = pb.GetMyGroupMarker().currentSubgroup;
			
			RLGroupMainConfig_ cfg = RLGroupMainConfig.Get;
			RLGroupMember memberMarker;
			if (Class.CastTo(memberMarker, this)) {
				bool checkSubgroupsPlayer = !cfg.enableSubGroupSharedPlayerMapMarker && cfg.enableSubGroups;
				return (!checkSubgroupsPlayer || mySubgroup == memberMarker.currentSubgroup);
			} else if (type == RLMarkerType.GROUP_PING) {
				bool checkSubgroupsPing = !cfg.enableSubGroupSharedPingMapMarker && cfg.enableSubGroups;
				return (!checkSubgroupsPing || mySubgroup == currentSubgroup);
			}
		}
		return true;
	}
	
	bool ShowMarker3D() {
		if (hidden)
			return false;
		if (streamerMode && (type == RLMarkerType.SERVER_STATIC || type == RLMarkerType.SERVER_DYNAMIC))
			return false;
		MarkerConfigEntry cfg = GetMarkerConfig();
	//	RLLogger.Debug("MarkerEntry: " + cfg, "AdvancedGroups");
		if (!cfg)
			return false;
		vector pos = GetGame().GetCurrentCameraPosition();
		dist = vector.Distance(position, pos);
	//	RLLogger.Debug("Dist: " + dist + " from: " + pos + " To: " + position, "AdvancedGroups");
		if (cfg.maxDistance < 0 || cfg.maxDistance > dist) {
			if (!RLMarkerVisibilityManager.Get().Is3DVisiblie(uid, type))
				return false;
		} else {
			return false;
		}
		if (type == RLMarkerType.GROUP_PING || type == RLMarkerType.GROUP_MARKER || type == RLMarkerType.GROUP_PLAYER_MARKER) {
			
			PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
			if (!pb || !pb.GetRLGroup())
				return false;
			RLGroup grp = pb.GetRLGroup();
			RLGroupPermission myPerm = pb.GetPermission();
			if (!myPerm)
				return false;
			
			if (!myPerm.CanSeeMarkerType(type))
				return false;
			int mySubgroup = pb.GetMyGroupMarker().currentSubgroup;
			
			RLGroupMainConfig_ cfg2 = RLGroupMainConfig.Get;
			RLGroupMember memberMarker;
			if (Class.CastTo(memberMarker, this)) {
				bool checkSubgroupsPlayer = !cfg2.enableSubGroupSharedPlayerMapMarker && cfg2.enableSubGroups;
				return (!checkSubgroupsPlayer || mySubgroup == memberMarker.currentSubgroup);
			} else if (type == RLMarkerType.GROUP_PING) {
				bool checkSubgroupsPing = !cfg2.enableSubGroupSharedPingMapMarker && cfg2.enableSubGroups;
				return (!checkSubgroupsPing || mySubgroup == currentSubgroup);
			}
		}
		return true;
	}
	
	bool ShowDistance3D() {
		if (!GetMarkerConfig())
			return true;
		return GetMarkerConfig().displayDistance;
	}
	
	bool ShouldCenterWidget() {
		return type == RLMarkerType.GROUP_PING;
	}
	
	void UpdateDistance() {
		if (ShowDistance3D()) {
			SetDistance();
			bottomWidget.Show(true);
		} else {
			bottomWidget.Show(false);
		}
	}
	
	int GetColorARGB() {
		if (type == RLMarkerType.GROUP_PING) {
			return RLColorManager.Get().GetColor("Ping 3D Marker");
		}
		return ARGB(colorA, colorR, colorG, colorB);
	}
	
	int Get3DColorARGB() {
		return GetColorARGB();
	}
	
	bool UpdateMarkerClient() {
		if (!mainWidget || (!showMap && !show3D ) || !RLUtils.IsClientPlayerAlive())
			return false;
		UpdateDistance();
		return true;
	}
	
	bool SetWidgetPosition() {
		if (!mainWidget || !position)
			return false;
		if (colorA == 0) {
			SetVisibleOnScreen(false);
			return false;
		}
		if (compassWidget) {
			float angle = GetMarkerAngle();
			float posX = (angle / 180.0) - 0.5;
			if (posX < -1)
				posX += 2;
			compassWidget.SetPos(posX, GetCompassPosY());
		}
		vector screenPos = GetGame().GetScreenPos(position);
		int screenWidth, screenHeight;
		GetScreenSize(screenWidth,screenHeight);
		if (screenPos[0] <= 0 || screenPos[0] >= screenWidth || screenPos[1] <= 0 || screenPos[1] >= screenHeight || screenPos[2] <= 0) {
			SetVisibleOnScreen(false);
			return false;
		}
		if (ShouldCenterWidget()) {
			float width, height;
			mainWidget.GetScreenSize(width, height);
			screenPos[0] = screenPos[0] - width / 2;
			screenPos[1] = screenPos[1] - height / 2;
		}
		SetVisibleOnScreen(true);
		mainWidget.SetPos(screenPos[0], screenPos[1]);
		return true;
	}
	
	float GetMarkerAngle() {
		vector camPos = GetGame().GetCurrentCameraPosition();
		vector dir = camPos - position;
		dir = dir.Normalized();
		vector angles = dir.VectorToAngles();
		float angle = angles[0] - currentCameraAngle + 360;
		while (angle > 180)
			angle -= 360;
		return angle;
	}
	
	bool IsMainWidgetVisible() {
		return visibleOnScreen && !disable3dDifferentSubgroup && show3D;
	}
	
	void SetVisibleOnScreen(bool b) {
		visibleOnScreen = b;
		if (mainWidget)
			mainWidget.Show(IsMainWidgetVisible());
		if (compassWidget) {
			compassWidget.Show(!disable3dDifferentSubgroup && show3D);
		}
	}
	
	bool ReadFromCtx(ParamsReadContext ctx) {
		if (!ctx.Read(type))
			return false;
		if (!ctx.Read(uid))
			return false;
		if (!ctx.Read(name))
			return false;
		if (!ctx.Read(icon))
			return false;
		if (!ctx.Read(position))
			return false;
		if (!ctx.Read(colorA))
			return false;
		if (!ctx.Read(colorR))
			return false;
		if (!ctx.Read(colorG))
			return false;
		if (!ctx.Read(colorB))
			return false;
		if (!ctx.Read(currentSubgroup))
			return false;
		if (!ctx.Read(circleRadius))
			return false;
		if (!ctx.Read(circleColorA))
			return false;
		if (!ctx.Read(circleColorR))
			return false;
		if (!ctx.Read(circleColorG))
			return false;
		if (!ctx.Read(circleColorB))
			return false;
		if (!ctx.Read(circleStriked))
			return false;
		if (!ctx.Read(showAllPlayerNametags))
			return false;
		return true;
	}
	
	int CalcHash() {
		int hash = type + uid + name.Hash() + position.ToString().Hash() + colorA * 4546546 + colorB * 45426365 + colorR * 52412 + colorB * 4241 + currentSubgroup * 745423;
		hash = hash + ((int) circleRadius) * 546353 + circleColorA * 7422221 + circleColorR * 756742 + circleColorG * 876543 + circleColorB * 787465;
		if (circleStriked)
			hash = hash + 7432435;
		return hash;
	}
	
	void WriteToCtx(ParamsWriteContext ctx, bool steamids_ = true, bool positions_ = true) {
		ctx.Write(type);
		ctx.Write(uid);
		ctx.Write(name);
		ctx.Write(icon);
		if (positions_)
			ctx.Write(position);
		else
			ctx.Write(vector.Zero);
		ctx.Write(colorA);
		ctx.Write(colorR);
		ctx.Write(colorG);
		ctx.Write(colorB);
		ctx.Write(currentSubgroup);
		ctx.Write(circleRadius);
		ctx.Write(circleColorA);
		ctx.Write(circleColorR);
		ctx.Write(circleColorG);
		ctx.Write(circleColorB);
		ctx.Write(circleStriked);
		ctx.Write(showAllPlayerNametags);
	}
	
	ScriptRPC CreateRPCCall(int type_) {
		ScriptRPC rpc = new ScriptRPC();
		rpc.Write(type_);
		rpc.Write(uid);
		return rpc;
	}
	
	void SendMarkerRPC(ScriptRPC rpc) {
		if (type == RLMarkerType.PRIVATE_MARKER)
			return;
		if (!parentGroup && GetGame().IsClient() && GetGame().IsMultiplayer()) {
			PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
			parentGroup = pb.GetRLGroup();
		}
		if (parentGroup) {
			#ifdef RayLab_GROUPS_DEBUG
			RLLogger.Debug("Sending Marker RPC...", "AdvancedGroups");
			#endif
			if (GetGame().IsServer()) {
				parentGroup.SendRPCToGroupMembers(rpc);
			} else {
				parentGroup.SendRPCToServer(rpc);
			}
		} else if (type == RLMarkerType.SERVER_STATIC || type == RLMarkerType.SERVER_DYNAMIC) {
			RLLogger.Debug("Sending Static Marker RPC", "AdvancedGroups");
			rpc.Send(null, RLGroupRPCs.MARKER_RPC, true);
		} else {
			if (!parentGroup)
				RLLogger.Debug("Failed to send Marker RPC for Marker: " + type + " No Parent Group ! ", "AdvancedGroups");
			else
				RLLogger.Debug("Failed to send Marker RPC for Marker: " + type + " Parent Group: " + parentGroup.shortname, "AdvancedGroups");
		}
	}
	
}