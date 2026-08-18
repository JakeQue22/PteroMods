class MapLegendItem {

	string iconPath;
	int iconColorA = 255;
	int iconColorR = 255;
	int iconColorG = 255;
	int iconColorB = 255;
	string name;
	int nameColorA = 255;
	int nameColorR = 255;
	int nameColorG = 255;
	int nameColorB = 255;
	
	[NonSerialized()]
	Widget mainWidget;
	[NonSerialized()]
	ImageWidget iconWidget;
	[NonSerialized()]
	TextWidget nameWidget;
	
	void MapLegendItem(string iconPath_, string name_) {
		this.iconPath = iconPath_;
		this.name = name_;
	}
	
	void ~MapLegendItem() {
		DeleteWidget();
	}
	
	void WriteToCtx(ParamsWriteContext ctx) {
		ctx.Write(iconPath);
		ctx.Write(iconColorA);
		ctx.Write(iconColorR);
		ctx.Write(iconColorG);
		ctx.Write(iconColorB);
		ctx.Write(name);
		ctx.Write(nameColorA);
		ctx.Write(nameColorR);
		ctx.Write(nameColorG);
		ctx.Write(nameColorB);
	}
	
	bool ReadFromCtx(ParamsReadContext ctx) {
		if (!ctx.Read(iconPath))
			return false;
		if (!ctx.Read(iconColorA))
			return false;
		if (!ctx.Read(iconColorR))
			return false;
		if (!ctx.Read(iconColorG))
			return false;
		if (!ctx.Read(iconColorB))
			return false;
		if (!ctx.Read(name))
			return false;
		if (!ctx.Read(nameColorA))
			return false;
		if (!ctx.Read(nameColorR))
			return false;
		if (!ctx.Read(nameColorG))
			return false;
		if (!ctx.Read(nameColorB))
			return false;
		return true;
	}
	
	void DeleteWidget() {
		if (mainWidget)
			mainWidget.Unlink();
	}
	
	void CreateWidget(Widget parent, int i) {
		float w, h;
		DeleteWidget();
		mainWidget = RLLayoutManager.Get().CreateLayout("MapLegendEntry", "RayLab_Groups/gui/layouts/mapmenu/map_marker.layout", parent);
		ConnectClassWidgetVariables(this, mainWidget, {"mainWidget"}, {"nameWidget", "name", "iconWidget", "icon"});
		mainWidget.GetScreenSize(w,h);
		int posY = i * (4 + h) + 5;
		mainWidget.SetPos(5, posY);
		parent.SetSize(1, posY + h + 5);
		
		InitWidgets();
	}
	
	void InitWidgets() {
		iconWidget.LoadImageFile(0, iconPath);
		iconWidget.SetColor(ARGB(iconColorA, iconColorR, iconColorG, iconColorB));
		nameWidget.SetText(" " + name);
		nameWidget.SetColor(ARGB(nameColorA, nameColorR, nameColorG, nameColorB));
	}
	
}