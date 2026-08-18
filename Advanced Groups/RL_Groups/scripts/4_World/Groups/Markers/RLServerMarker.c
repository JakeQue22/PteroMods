class RLServerMarker : RLMarker {
	
	bool toSurface, display3d, displayMap, displayGPS;
	
	override bool ReadFromCtx(ParamsReadContext ctx) {
		if (!super.ReadFromCtx(ctx))
			return false;
		if (!ctx.Read(toSurface))
			return false;
		if (!ctx.Read(display3d))
			return false;
		if (!ctx.Read(displayMap))
			return false;
		if (!ctx.Read(displayGPS))
			return false;
		return true;
	}
	
	void InitFromRLMarker(RLMarker marker) {
		this.type = marker.type;
		this.uid = marker.uid;
		this.name = marker.name;
		this.icon = marker.icon;
		this.position = marker.position;
		this.currentSubgroup = marker.currentSubgroup;
		this.colorA = marker.colorA;
		this.colorR = marker.colorR;
		this.colorG = marker.colorG;
		this.colorB = marker.colorB;
		this.circleRadius = marker.circleRadius;
		this.circleColorA = marker.circleColorA;
		this.circleColorR = marker.circleColorR;
		this.circleColorG = marker.circleColorG;
		this.circleColorB = marker.circleColorB;
		this.circleStriked = marker.circleStriked;
		this.showAllPlayerNametags = marker.showAllPlayerNametags;
		toSurface = true;
		display3d = true;
		displayMap = true;
		displayGPS = true;
	}
	
	override void WriteToCtx(ParamsWriteContext ctx, bool steamids_ = true, bool positions_ = true) {
		super.WriteToCtx(ctx, steamids_, positions_);
		ctx.Write(toSurface);
		ctx.Write(display3d);
		ctx.Write(displayMap);
		ctx.Write(displayGPS);
	}
	
	override void InitMarker() {
		super.InitMarker();
		if (toSurface)
			position[1] = GetGame().SurfaceY(position[0], position[2]);
	}
	
	override void SetPosition(vector pos) {
		super.SetPosition(pos);
		if (toSurface)
			position[1] = GetGame().SurfaceY(position[0], position[2]);
	}
	
	override bool UpdateMarkerClient() {
		if (display3d)
			return super.UpdateMarkerClient();
		SetVisibleOnScreen(false);
		return false;
	}
	
	static int staticMarkerUID = 100;
	void Init(string namee, vector pos, string ic, int a, int r, int g, int b, bool toSuf = true, bool disp3d = true, bool dispMap = true, bool dispGPS = true) {
		super.SetupMarker(RLMarkerType.SERVER_STATIC, namee, ic, pos);
		colorA = a;
		colorR = r;
		colorG = g;
		colorB = b;
		toSurface = toSuf;
		display3d = disp3d;
		displayMap = dispMap;
		displayGPS = dispGPS;
		RLLogger.Debug("Init Server Marker: " + namee + " " + a + " " + r + " " +g + " " + b, "AdvancedGroups");
	}

}