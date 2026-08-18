class RLMapMarkerManager {
	private MapWidget map_widget;
	private ref CanvasWidget drawCanvas;
	private Widget iconPane;
	
	private ref array<ref MapMarkerWrapper> markers = new array<ref MapMarkerWrapper>();
	vector lastMapPos = vector.Zero;
	float lastMapScale = 0.0;
	float xOffset = 0, yOffset = 0;
	bool isMap = true;
	
	void RLMapMarkerManager(MapWidget mapWidget) {
		if (!mapWidget)
			return;
		map_widget = mapWidget;
		iconPane = map_widget.FindAnyWidget("iconPane");
		drawCanvas = CanvasWidget.Cast(map_widget.FindAnyWidget("drawCanvas"));
		GetGame().GetCallQueue(CALL_CATEGORY_GUI).CallLater(UpdateSlowVisibility, 100, true);
		RLLayoutConfig.Event_StreamerModeChanged.Insert(OnStreamerModeChange);
		UpdateOffsets();
	}
	
	void ~RLMapMarkerManager() {
		ClearMarkers();
		if (GetGame() && GetGame().GetCallQueue(CALL_CATEGORY_GUI))
			GetGame().GetCallQueue(CALL_CATEGORY_GUI).Remove(UpdateSlowVisibility);
		if (RLLayoutConfig.Event_StreamerModeChanged)
			RLLayoutConfig.Event_StreamerModeChanged.Remove(OnStreamerModeChange);
	}
	
	void UpdateOffsets() {
		iconPane.GetScreenPos(xOffset, yOffset);
	}
	
	void SetDragable(bool drag) {
		float scale = map_widget.GetScale();
		vector pos = map_widget.GetMapPos();
		foreach (MapMarkerWrapper wrap : markers) {
			MapMarkerWrapperRLMarker mark;
			if (Class.CastTo(mark, wrap)) {
				mark.SetDragable(drag);
				mark.Update(scale, pos, map_widget, xOffset, yOffset);
				mark.UpdateVisibility(isMap);
			}
		}
	}
	
	void UpdateFrame(bool force = false) {
		float scale = map_widget.GetScale();
		vector pos = map_widget.GetMapPos();
		UpdateOffsets();
		if (force || scale != lastMapScale || vector.Distance(pos, lastMapPos) > 0.01) {
			drawCanvas.Clear();
			foreach (MapMarkerWrapper wrapper : markers) {
				if (wrapper.IsVisible())
					wrapper.Update(scale, pos, map_widget, xOffset, yOffset);
			}
		}
		bool updateCircles = false;
		foreach (MapMarkerWrapper wrapper3 : markers) {
			MapMarkerWrapperCircle circle;
			if (!Class.CastTo(circle, wrapper3)) {
				MapMarkerWrapperRLMarker markerWrapper;
				if (Class.CastTo(markerWrapper, wrapper3)) {
					circle = markerWrapper.circle;
				}
			}
			if (circle != null && circle.UpdateOptional(false)) {
				updateCircles = true;
				break;
			}
		}
		if (updateCircles)
			drawCanvas.Clear();
		foreach (MapMarkerWrapper wrapper2 : markers) {
			if (wrapper2.IsVisible() && (wrapper2.UpdateOptional() || updateCircles))
				wrapper2.Update(scale, pos, map_widget, xOffset, yOffset);
		}
		lastMapScale = scale;
		lastMapPos = pos;
	}
	
	void OnStreamerModeChange(bool enabled) {
		UpdateSlowVisibility();
	}
	
	void UpdateSlowVisibility() {
		//RLLogger.Verbose("UpdateSlowVisibility " + markers.Count(), "AdvancedGroups");
		foreach (MapMarkerWrapper wrapper : markers) {
			wrapper.UpdateVisibility(isMap);
		}
	}
	
	void ClearMarkers() {
		markers.Clear();
		drawCanvas.Clear();
	}
	
	private void AddMarker(MapMarkerWrapper wrapper) {
		markers.Insert(wrapper);
		if (wrapper.IsVisible()) {
			float scale = map_widget.GetScale();
			vector pos = map_widget.GetMapPos();
			wrapper.Update(scale, pos, map_widget, xOffset, yOffset);
			wrapper.UpdateVisibility(isMap);
			UpdateFrame();
		}
	}
	
	vector GetMousePosWld() {
		int x,y;
		GetMousePos(x,y);
		vector mouse = Vector(x,y,0);
		vector mapPos = map_widget.ScreenToMap(mouse);
		return mapPos;
	}
	
	MapMarkerWrapperRLMarker AddMarker(RLMarker marker, int layer = 0) {
		MapMarkerWrapperRLMarker wrapper = new MapMarkerWrapperRLMarker();
		wrapper.SetDrawCanvas(drawCanvas);
		wrapper.Init(marker, iconPane);
		wrapper.layer = layer;
		AddMarker(wrapper);
		return wrapper;
	}
	
	MapMarkerWrapperObject AddMarkerObject(Object obj, string name = "", int color = 0xFFFFFFFF, string icon = "", int layer = 0) {
		MapMarkerWrapperObject wrapper = new MapMarkerWrapperObject();
		wrapper.SetDrawCanvas(drawCanvas);
		wrapper.Init(obj, color, icon, name, iconPane);
		wrapper.layer = layer;
		AddMarker(wrapper);
		return wrapper;
	}
	
	MapMarkerWrapperCircle AddCircleNonScaling(vector pos, float radius, int color, int layer = 0, bool striked = false) {
		MapMarkerWrapperCircle wrapper = new MapMarkerWrapperCircle();
		wrapper.SetDrawCanvas(drawCanvas);
		wrapper.Init(pos, radius, color, striked);
		wrapper.layer = layer;
		AddMarker(wrapper);
		return wrapper;
	}
	
	void RemoveLayer(int layer) {
		for (int i = 0; i < markers.Count(); i++) {
			MapMarkerWrapper wrapper = markers.Get(i);
			if (wrapper && wrapper.layer == layer) {
				markers.Remove(i);
				i--;
			}
		}
		UpdateFrame(true);
	}
	
	void CutAllCircles() {
		map<int, ref array<MapMarkerWrapperCircle>> circles = new map<int, ref array<MapMarkerWrapperCircle>>();
		for (int i = 0; i < markers.Count(); i++) {
			MapMarkerWrapper wrapper = markers.Get(i);
			MapMarkerWrapperCircle circle;
			if (Class.CastTo(circle, wrapper)) {
				array<MapMarkerWrapperCircle> arr;
				if (circles.Contains(wrapper.layer))
					arr = circles.Get(wrapper.layer);
				else {
					arr = new array<MapMarkerWrapperCircle>();
					circles.Insert(wrapper.layer, arr);
				}
				arr.Insert(circle);
			}
		}
		RLLogger.Debug("Circle Layers: " + circles.Count(), "AdvancedGroups");
		foreach (int layer, array<MapMarkerWrapperCircle> circ : circles) {
			RLLogger.Verbose("Layer: " + layer + " Circles: " + circ.Count(), "AdvancedGroups");
			foreach (MapMarkerWrapperCircle cir : circ) {
				cir.SetOtherCircles(circ);
			}
		}
		UpdateFrame(true);
	}
	
	void RemoveMarker(RLMarker marker) {
		for (int i = 0; i < markers.Count(); i++) {
			MapMarkerWrapper wrapper = markers.Get(i);
			MapMarkerWrapperRLMarker wrapCast;
			if (!Class.CastTo(wrapCast, wrapper))
				continue;
			if (wrapCast && wrapCast.marker == marker) {
				markers.Remove(i);
				i--;
			}
		}
	}
	
	MapMarkerWrapper FindByMainWidget(Widget w) {
		for (int i = 0; i < markers.Count(); i++) {
			MapMarkerWrapper wrapper = markers.Get(i);
			if (wrapper.widget == w)
				return wrapper;
		}
		return null;
	}
	
	RLMarker FindMarkerByMainWidget(Widget w) {
		MapMarkerWrapperRLMarker wrap = MapMarkerWrapperRLMarker.Cast(FindByMainWidget(w));
		if (!wrap)
			return null;
		return wrap.marker;
	}
	
	void OnDragStart(Widget w) {
		MapMarkerWrapper wrap = FindByMainWidget(w);
		if (!wrap)
			return;
		wrap.isDragged = true;
	}
	
	void OnDragStop(Widget w) {
		MapMarkerWrapper wrap = FindByMainWidget(w);
		if (!wrap)
			return;
		wrap.isDragged = false;
	}
	
	void MoveToPoint(vector pos, float scale) {
		map_widget.SetScale(scale);
		map_widget.SetMapPos(pos);
		UpdateFrame();
	}
	
}
class MapMarkerWrapper {

	float widgetWidth, widgetHeight;
	Widget widget;
	bool isDragged = false;
	
	int layer = 0;
	
	vector position = vector.Zero;
	vector lastPosition = vector.Zero;
	string lastname = "";
	string lasticon = "";
	int lastColor = 0;
	
	bool changed = false;
	ref CanvasWidget drawCanvas;
	
	void ~MapMarkerWrapper() {
		if (widget) {
			widget.Unlink();
		}
	}
	
	void SetDrawCanvas(CanvasWidget canvas) {
		this.drawCanvas = canvas;
	}
	
	void SetLayer(int layer_) {
		layer = layer_;
	}
	
	void Update(float mapScale, vector mapPos, MapWidget mapWidget, float xOffset, float yOffset) {
	}
	bool UpdateOptional(bool change = true) {
		if (changed) {
			if (change)
				changed = false;
			return true;
		}
		return false;
	}
	void UpdateVisibility(bool isMap) {
	}
	
	bool IsVisible() {
		if (!widget)
			return false;
		return widget.IsVisible();
	}
	
	bool SetPosition(vector pos) {
		if (vector.Distance(lastPosition, pos) > 1) {
			this.position = pos;
			this.lastPosition = pos; 
			changed = true;
			return true;
		}
		return false;
	}
	
	bool SetColor(int color) {
		if (lastColor != color) {
			RLLogger.Debug("Set Color at " + position + " from " + lastColor + " to " + color, "AdvancedGroups");
			lastColor = color;
			changed = true;
			return true;
		}
		return false;
	}
}
class MapMarkerWrapperCircle : MapMarkerWrapper {

	const int CIRCLE_WIDTH = 2;
	
	float radius = 100.0;
	ref array<MapMarkerWrapperCircle> otherCircles = new array<MapMarkerWrapperCircle>();
	bool needPointUpdate = true;
	bool drawLines = true;
	ref array<ref Param2<float, float>> intersectingAngles = new array<ref Param2<float, float>>();
	float lastcircumference = -1;
	ref array<ref Param4<bool, bool, float, bool>> intersectionPreCalculated = new array<ref Param4<bool, bool, float, bool>>();
	
	void Init(vector center, float radius_, int color, bool striked) {
		this.drawLines = striked;
		this.position = center;
		this.radius = radius_;
		this.lastColor = color;
		drawCanvas.GetScreenSize(widgetWidth, widgetHeight);
	}
	
	bool SetStriked(bool striked) {
		if (drawLines != striked) {
			drawLines = striked;
			changed = true;
			return true;
		}
		return false;
	}
	
	override bool IsVisible() {
		return drawCanvas && drawCanvas.IsVisible();
	}
	
	override void Update(float mapScale, vector mapPos, MapWidget mapWidget, float xOffset, float yOffset) {
		float canvasWidth, canvasHeight;
		drawCanvas.GetScreenSize(canvasWidth, canvasHeight);
		
		vector screenPos = mapWidget.MapToScreen(position) + Vector(-xOffset, -yOffset, 0);
		
		float radiusRoot = Math.Sqrt(radius);
		float circumference = 2.0 * Math.PI * radiusRoot + 1.0;
		float part = 1.0 / radiusRoot;
		float screenScale = RLWidgetUtils.screenHeight / 11500.0 * 15360.0 / GetGame().GetWorld().GetWorldSize();
		float mapToScreen = mapScale / screenScale;
		float radiusScreen = radius / mapToScreen;
		
		if (screenPos[0] + radiusScreen < 0 || screenPos[0] - radiusScreen > canvasWidth || screenPos[1] + radiusScreen < 0 || screenPos[1] - radiusScreen > canvasHeight) {
			return;
		}
		
		float angle2 = 0.0;
		float rawX2 = radius, rawY2 = 0.0, newY2 = screenPos[1], newX2 = screenPos[0] + radius / mapToScreen;
		int partStart = TickCount(0);
		if (needPointUpdate) {
			CalcAllCircleIntersections();
		}
		if (needPointUpdate || lastcircumference != circumference) {
			CalcCircleIntersections(circumference, part);
			lastcircumference = circumference;
		}
		needPointUpdate = false;
		bool hasIntersections = intersectingAngles.Count() > 0;
		int currentIntersectionIndex = 0;
		
		DrawStrikeLines(mapScale, mapWidget, xOffset, yOffset, lastColor);
		int i = 0;
		foreach (Param4<bool, bool, float, bool> param : intersectionPreCalculated) {
			i++;
			float angle1 = angle2;
			angle2 = part * i;
			float rawX1 = rawX2;
			rawX2 = radius * Math.Cos(angle2);
			float rawY1 = rawY2;
			rawY2 = -radius * Math.Sin(angle2);
			float newX1 = newX2;
			newX2 = screenPos[0] + rawX2 / mapToScreen;
			float newY1 = newY2;
			newY2 = screenPos[1] + rawY2 / mapToScreen;
			
			if (!hasIntersections || currentIntersectionIndex >= intersectingAngles.Count()) {
				drawCanvas.DrawLine(newX1, newY1 , newX2 , newY2 , CIRCLE_WIDTH, lastColor);
				hasIntersections = false;
				continue;
			}
			
			if (param.param1) {
				continue;
			}
			if (param.param2) {
				if (param.param4) {
					float newX3 = screenPos[0] + radius * Math.Cos(param.param3 + 0.0015) / mapToScreen;
					float newY3 = screenPos[1] - radius * Math.Sin(param.param3 + 0.0015) / mapToScreen;
					drawCanvas.DrawLine(newX3, newY3 , newX2 , newY2 , CIRCLE_WIDTH, lastColor);
				} else {
					newX3 = screenPos[0] + radius * Math.Cos(param.param3 - 0.0015) / mapToScreen;
					newY3 = screenPos[1] - radius * Math.Sin(param.param3 - 0.0015) / mapToScreen;
					drawCanvas.DrawLine(newX3, newY3 , newX1 , newY1 , CIRCLE_WIDTH, lastColor);
				}
				continue;
			}
			drawCanvas.DrawLine(newX1, newY1 , newX2 , newY2 , CIRCLE_WIDTH, lastColor);
		}
	}
	
	void CalcCircleIntersections(int circumference, float part) {
		intersectionPreCalculated.Clear();
		float angle2 = 0.0;
		for (int i = 1; i < circumference + 1; i++) {
			
			if (intersectingAngles.Count() <= 0) {
				intersectionPreCalculated.Insert(new Param4<bool, bool, float, bool>(false, false, 0.0, false));
				continue;
			}
			float angle1 = angle2;
			angle2 = part * i;
			
			Param2<float, float> inter = intersectingAngles.Get(0);
			bool intersecting = false;
			bool edge = false;
			float angleEdge = 0.0;
			bool useNew2 = false;
			foreach (Param2<float, float> intersects : intersectingAngles) {
				if (angle2 > intersects.param1 && angle1 < intersects.param2) {
					if (intersects.param1 < angle1) {
						angleEdge = intersects.param2;
						useNew2 = true;
					} else {
						angleEdge = intersects.param1;
						useNew2 = false;
					}
					edge = true;
				}
				if (angle1 > intersects.param1 && angle2 < intersects.param2) {
					intersecting = true;
					break;
				}
			}
			intersectionPreCalculated.Insert(new Param4<bool, bool, float, bool>(intersecting, edge, angleEdge, useNew2));
		}
	}
	
	void DrawStrikeLines(float mapScale, MapWidget mapWidget, float xOffset, float yOffset, int color) {
		int startTime = TickCount(0);
		if (!drawLines)
			return;
		
		vector screenPos = mapWidget.MapToScreen(position) + Vector(-xOffset, -yOffset, 0);
		float screenPos1 = screenPos[0];
		float screenPos2 = screenPos[1];
		float screenScale = RLWidgetUtils.screenHeight / 11500.0 * 15360.0 / GetGame().GetWorld().GetWorldSize();
		float mapToScreen = mapScale / screenScale;
		float radiusScreen = radius / mapToScreen;
		
		int lineCount = (radiusScreen * 2) / 20 + 1;
		int radPart = radiusScreen / 20;
		float sq_20_2 = 20.0 / Math.Sqrt(2.0);
		float screen_X = screenPos[0] / 2.0;
		float start_Offset = (screen_X - ((int)(screen_X / sq_20_2)) * sq_20_2);
		float screen_Y = screenPos[1] / 2.0;
		start_Offset = start_Offset + (screen_Y - ((int)(screen_Y / sq_20_2)) * sq_20_2);
		float start1 = radPart * sq_20_2 - start_Offset + sq_20_2;
		float start2 = radPart * sq_20_2 - start_Offset + sq_20_2;
		if (lineCount > 1000) {
			return;
		}

		for (int i = 1; i <= lineCount; i++) {
			float otherLen = Math.Sqrt(radiusScreen * radiusScreen - (start1 * start1 + start2 * start2));
			float begin1 = 0.7071067 * otherLen;
			float begin2 = -0.7071067 * otherLen;
			float end1 = screenPos1 + start1 - begin1;
			float end2 = screenPos2 + start2 - begin2;
			begin1 = begin1 + screenPos1 + start1;
			begin2 = begin2 + screenPos2 + start2;
			start1 -= sq_20_2;
			start2 -= sq_20_2;
			
			drawCanvas.DrawLine(begin1, begin2, end1, end2, CIRCLE_WIDTH, ((0xff000000 | color) & 0x50ffffff));
		}
	}
	
	bool SetRadius(float radius_) {
		if (radius_ != radius) {
			this.radius = radius_;
			changed = true;
			needPointUpdate = true;
			return true;
		}
		return false;
	}
	
	void CalcAllCircleIntersections() {
		intersectingAngles.Clear();
		if (!otherCircles)
			return;
  
		for (int i = 0; i < otherCircles.Count(); i++) {
			MapMarkerWrapperCircle circle = otherCircles.Get(i);
			if (circle == this) {
				otherCircles.Remove(i);
				i--;
				continue;
			}
			float d = Math.Sqrt((position[0] - circle.position[0]) * (position[0] - circle.position[0]) + (position[2] - circle.position[2]) * (position[2] - circle.position[2]));
			if (d >= radius + circle.radius || d <= 0) {
				otherCircles.Remove(i);
				i--;
				continue;
			}
			float a = (radius * radius - circle.radius * circle.radius + d * d) / 2 / d;
			float h = Math.Sqrt(radius * radius - a * a);
			float hd = h / d;
			float x3 = position[0] + (circle.position[0] - position[0]) * a / d;
			float y3 = position[2] + (circle.position[2] - position[2]) * a / d;
			
			float x1 = (x3 + hd * (circle.position[2] - position[2]) - position[0]);
			float y1 = (y3 - hd * (circle.position[0] - position[0]) - position[2]);
			
			float x2 = (x3 - hd * (circle.position[2] - position[2]) - position[0]);
			float y2 = (y3 + hd * (circle.position[0] - position[0]) - position[2]);
			
			float dist1 = Math.Sqrt(y1 * y1 + x1 * x1);
			float dist2 = Math.Sqrt(y2 * y2 + x2 * x2);
			if (dist1 <= 0 || dist2 <= 0) {
				otherCircles.Remove(i);
				i--;
				continue;
			}
			float angle1 = Math.Acos(x1 / dist1);
			if (y1 < 0)
				angle1 = Math.PI2 - angle1;
			float angle2 = Math.Acos(x2 / dist2);
			if (y2 < 0)
				angle2 = Math.PI2 - angle2;
			/*
			float diff = Math.AbsFloat(angle1 - angle2);
			float angle12 = angle1 + diff / 2;
			if (angle12 > Math.PI2)
				angle12 -= Math.PI2;
			float angle21 = angle1 - diff / 2;
			if (angle21 > Math.PI2)
				angle21 -= Math.PI2;
			
			float x1_1 = radius * Math.Cos(angle12);
			float y1_1 = -radius * Math.Sin(angle12);
			
			float x2_1 = radius * Math.Cos(angle21);
			float y2_1 = -radius * Math.Sin(angle21);
			
			float d_1 = Math.Sqrt((x1_1 - circle.position[0]) * (x1_1 - circle.position[0]) + (y1_1 - circle.position[2]) * (y1_1 - circle.position[2]));
			float d_2 = Math.Sqrt((x2_1 - circle.position[0]) * (x2_1 - circle.position[0]) + (y2_1 - circle.position[2]) * (y2_1 - circle.position[2]));
			
			if (d_2 < d_1) {
				RLLogger.Debug("Changing Angles " + angle1 + " and " + angle2, "AdvancedGroups");
				float temp = angle1;
				angle1 = angle2;
				angle2 = temp;
			}*/
			
			RLLogger.Verbose("Angles for " + position + ": " + angle1 + " " + angle2 + " " + y1 + " " + x1 + "   " + y2 + " " + x2, "AdvancedGroups");
			
			if (angle1 < angle2) {
				intersectingAngles.Insert(new Param2<float, float>(angle1, angle2));
			} else {
				intersectingAngles.Insert(new Param2<float, float>(-1, angle2));
				intersectingAngles.Insert(new Param2<float, float>(angle1, Math.PI2 + 1));
			}
		}
		//RearrangeIntersections();
	}
	
	void RearrangeIntersections() {
		array<ref Param2<float, float>> recalculated = new array<ref Param2<float, float>>();
		float minStart = -1.0;
		for (int i = 0; i < intersectingAngles.Count(); i++) {
			float end = minStart;
			float start = -1.0;
			foreach (Param2<float, float> inter : intersectingAngles) {
				if ((inter.param1 < start || start == -1) && inter.param1 > minStart) {
					start = inter.param1;
					end = inter.param2;
				}
			}
			if (start == -1)
				break;
			bool changed_ = true;
			while (changed_) {
				changed_ = false;
				foreach (Param2<float, float> inter2 : intersectingAngles) {
					if (inter2.param1 < end && inter2.param1 > start && inter2.param2 > end) {
						end = inter2.param2;
						changed_ = true;
					}
				}
			}
			if (start == end)
				break;
			Param2<float, float> insert = new Param2<float, float>(start, end);
			recalculated.Insert(insert);
			minStart = end;
		}
		if (RLLogger.Verbose()) {
			RLLogger.Verbose("Rearranged Angles from:", "AdvancedGroups");
			foreach (Param2<float, float> parm : intersectingAngles) {
				RLLogger.Verbose("    " + parm.param1 + " " + parm.param2, "AdvancedGroups");
			}
			RLLogger.Verbose("to:", "AdvancedGroups");
			foreach (Param2<float, float> rec : recalculated) {
				RLLogger.Verbose("    " + rec.param1 + " " + rec.param2, "AdvancedGroups");
			}
		}
		intersectingAngles = recalculated;
	}
	
	void SetOtherCircles(array<MapMarkerWrapperCircle> circles) {
		otherCircles.Clear();
		foreach (MapMarkerWrapperCircle circ : circles) {
			otherCircles.Insert(circ);
		}
		needPointUpdate = true;
	}
}
class MapMarkerWrapperObject : MapMarkerWrapper {

	Object obj;
	
	ImageWidget icon;
	TextWidget name;
	
	void Init(Object obj2, int color, string theicon, string thename, Widget iconPane) {
		if (!iconPane)
			return;
		obj = obj2;
		widget = RLLayoutManager.Get().CreateLayout("MapMarker", "RayLab_Groups/gui/layouts/mapmenu/map_marker.layout", iconPane);
		if (widget) {
			widget.Show(true);
			name = TextWidget.Cast(widget.FindAnyWidget("name"));
			icon = ImageWidget.Cast(widget.FindAnyWidget("icon"));
			SetIcon(theicon);
			SetColor(color);
			SetName("  " + thename);
			SetPosition(obj.GetPosition());
			widget.Update();
			widget.GetScreenSize(widgetWidth, widgetHeight);
		}
	}
	
	override void Update(float mapScale, vector mapPos, MapWidget mapWidget, float xOffset, float yOffset) {
		if (isDragged)
			return;
		vector screenPos = mapWidget.MapToScreen(GetPosition());
		
		widget.SetPos(screenPos[0] - widgetHeight / 2.0 - xOffset, screenPos[1] - widgetHeight / 2.0 - yOffset, true);
		if (name)
			name.SetTextExactSize(Math.Clamp(20.0, 10.0, 50.0));
		if (icon) {
			icon.SetSize(widgetHeight, widgetHeight);
		}
	}
	
	override void UpdateVisibility(bool isMap) {
		bool visible = IsMarkerVisible();
		widget.Show(visible);
	}
	
	bool IsMarkerVisible() {
		if (obj && PlayerBase.Cast(obj)) {
			return RLGroupMainConfig.Get && RLGroupMainConfig.Get.canSeeOwnPlayerOnMap;
		}
		return false;
	}
	
	vector GetPosition() {
		if (obj)
			return obj.GetPosition();
		return position;
	}
	
	
	override bool UpdateOptional(bool change = true) {
		if (!change)
			return false;
		if (SetPosition(GetPosition())) {
			return true;
		}
		return false;
	}
	override bool SetColor(int color) {
		if (lastColor != color) {
			name.SetColor(color);
			icon.SetColor(color);
		}
		return super.SetColor(color);
	}
	
	bool SetIcon(string iconpath) {
		if (lasticon != iconpath && icon) {
			icon.LoadImageFile(0, iconpath);
			lasticon = iconpath;
			icon.Show(iconpath.Length() > 0 && FileExist(iconpath));
			return true;
		}
		return false;
	}
	
	bool SetName(string thename) {
		if (lastname != thename) {
			name.SetText(thename);
			lastname = thename;
			return true;
		}
		return false;
	}
}
class MapMarkerWrapperRLMarker : MapMarkerWrapper {

	RLMarker marker;
	ImageWidget icon;
	TextWidget name;
	bool dragable = false;
	Widget iconPane_;
	ref MapMarkerWrapperCircle circle = null;
	
	void Init(RLMarker marker2, Widget iconPane) {
		iconPane_ = iconPane;
		if (!iconPane || !marker2)
			return;
		marker = marker2;
		widget = CreateLayout(iconPane);
		if (widget) {
			widget.Show(true);
			name = TextWidget.Cast(widget.FindAnyWidget("name"));
			icon = ImageWidget.Cast(widget.FindAnyWidget("icon"));
			SetIcon(marker.GetIcon());
			SetColor(marker.GetColorARGB());
			SetName(" " + marker.name);
			SetPosition(marker.position);
			widget.Update();
			widget.GetScreenSize(widgetWidth, widgetHeight);
		}
	}
	
	void ~MapMarkerWrapperRLMarker() {
		if (circle)
			delete circle;
	}
	
	void ReInit() {
		if (widget)
			widget.Unlink();
		lastPosition = vector.Zero;
		lastname = "";
		lasticon = "";
		lastColor = 0;
		Init(marker, iconPane_);
	}
	
	Widget CreateLayout(Widget iconPane) {
		if (dragable && marker && (marker.type == RLMarkerType.GROUP_MARKER || marker.type == RLMarkerType.PRIVATE_MARKER))
			return RLLayoutManager.Get().CreateLayout("MapMarker_Dragable", "RayLab_Groups/gui/layouts/mapmenu/map_marker_dragable.layout", iconPane);
		return RLLayoutManager.Get().CreateLayout("MapMarker", "RayLab_Groups/gui/layouts/mapmenu/map_marker.layout", iconPane);
	}
	
	void SetDragable(bool drag_) {
		if (drag_ != dragable) {
			dragable = drag_;
			ReInit();
		}
	}
	
	override void Update(float mapScale, vector mapPos, MapWidget mapWidget, float xOffset, float yOffset) {
		if (!marker)
			return;
		if (isDragged)
			return;
		vector screenPos = mapWidget.MapToScreen(GetPosition());
		
		widget.SetPos(screenPos[0] - widgetHeight / 2.0 - xOffset, screenPos[1] - widgetHeight / 2.0 - yOffset, true);
		if (name)
			name.SetTextExactSize(Math.Clamp(20.0, 10.0, 50.0));
		if (icon) {
			icon.SetSize(widgetHeight, widgetHeight);
		}
		if (circle)
			circle.Update(mapScale, mapPos, mapWidget, xOffset,yOffset);
	}
	
	override void UpdateVisibility(bool isMap) {
		if (!marker)
			return;
		if (marker && (marker.type == RLMarkerType.SERVER_STATIC || marker.type == RLMarkerType.SERVER_DYNAMIC) && RLLayoutConfig.Get().streamerModeEnabled) {
			widget.Show(false);
			return;
		}
		bool visible = IsMarkerVisible(isMap);
		widget.Show(visible);
		if (circle)
			circle.UpdateVisibility(isMap);
	}
	
	bool IsMarkerVisible(bool isMap) {
		RLGroupMember memberMarker;
		if (!marker)
			return false;
		if (marker.type == RLMarkerType.GROUP_PLAYER_MARKER && Class.CastTo(memberMarker, marker)) {
			string mysteamid = RLAdmins.Get().GetMySteamid();
			if (memberMarker.steamid == mysteamid)
				return false;
		}
		return marker.ShowMarkerMapOrGPS(isMap);
	}
	
	vector GetPosition() {
		if (marker)
			return marker.position;
		return position;
	}
	
	override bool SetColor(int color) {
		if (lastColor != color) {
			name.SetColor(color);
			icon.SetColor(color);
		}
		return super.SetColor(color);
	}
	override bool UpdateOptional(bool change = true) {
		if (!change)
			return false;
		if (marker) {
			if (SetPosition(marker.position) | SetColor(marker.GetColorARGB()) | SetIcon(marker.GetIcon()) | SetName(marker.name) | SetRadius(marker.position, marker.circleRadius, marker.GetCircleColor(), marker.circleStriked)) {
				return true;
			}
		}
		return false;
	}
	
	bool SetRadius(vector pos, float circleRadius, int circleColor, bool striked) {
		if (circleRadius <= 0) {
			if (circle)
				delete circle;
			return false;
		}
		if (circle == null) {
			circle = new MapMarkerWrapperCircle();
			circle.SetDrawCanvas(drawCanvas);
			circle.Init(pos, circleRadius, circleColor, striked);
			circle.SetLayer(marker.uid);
			return true;
		}
		return circle.SetPosition(pos) | circle.SetRadius(circleRadius) | circle.SetColor(circleColor) | circle.SetStriked(striked);
	}
	
	bool SetIcon(string iconpath) {
		if (lasticon != iconpath && icon) {
			icon.LoadImageFile(0, iconpath);
			lasticon = iconpath;
			icon.Show(iconpath.Length() > 0 && FileExist(iconpath));
			return true;
		}
		return false;
	}
	
	bool SetName(string thename) {
		if (lastname != thename) {
			name.SetText(thename);
			lastname = thename;
			return true;
		}
		return false;
	}
}
