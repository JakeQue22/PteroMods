class RLMenuBase : UIScriptedMenu {

	bool forceMenuOpen = false;
	ref RLMenuData openParameter;
	ref map<string, ref Param2<float, float>> originalWidgetSize = new map<string, ref Param2<float, float>>();
	
	void SetForceOpen(bool forceOpen) {
		forceMenuOpen = forceOpen;
	}
	
	override Widget Init() {
		layoutRoot = RLLayoutManager.Get().CreateLayout(GetLayoutName());
		ConnectClassWidgetVariables(this, layoutRoot);
		OnInit();
		return layoutRoot;
	}
	
	void OnDataReceived() {}
	void OnInit() {}
	
	Widget GetCloseButton() {return null;}
	string GetLayoutName() {return "";}
	int GetPriority() {return 100;}
	bool RLCloseOnEscape() {return true;}
	bool DisableCharacterControl() {return true;}
	bool DisableHud() {return true;}
	bool DisableCursor() {return false;}
	void CloseMe(bool forceClose = false) {
		if (forceClose || !forceMenuOpen) {
			SetForceOpen(false);
			Close();
		}
	}
	
	void OnClickRL(Widget w) {}
	
	override bool OnClick(Widget w, int x, int y, int button) {
		if (super.OnClick(w, x, y, button))
			return true;
		if (w && w == GetCloseButton()) {
			CloseMe();
			return true;
		}
		OnClickRL(w);
		return false;
	}
	
	private void GetOriginalImageSize(ImageWidget widget, out float width, out float height, int overwriteWidth, int overwriteHeight) {
		string name = widget.ToString();
		Param2<float, float> dimensions;
		if (originalWidgetSize.Find(name, dimensions)) {
			width = dimensions.param1;
			height = dimensions.param2;
		} else {
			bool visible = widget.IsVisible();
			widget.Show(true);
			widget.GetScreenSize(width, height);
			widget.Show(visible);
			Print("Original Size of " + widget + ": " + width + " " + height);
			dimensions = new Param2<float, float>(width, height);
			if (overwriteWidth != -1)
				dimensions.param1 = overwriteWidth;
			if (overwriteHeight != -1)
				dimensions.param2 = overwriteHeight;
			originalWidgetSize.Insert(name, dimensions);
		}
		
		if (overwriteWidth != -1)
			width = overwriteWidth;
		if (overwriteHeight != -1)
			height = overwriteHeight;
	}
	
	bool SetImageFit(ImageWidget img, string path, bool hideWhenNotFound = true, int overwriteWidth = -1, int overwriteHeight = -1) {
		bool found = FileExist(path) && path != "";
		RLLogger.Verbose("Setting Image " + path + " for " + img + ". Found ? " + found, "Core");
		if (found) {
			img.Show(true);
			img.LoadImageFile(0, path);
			UpdateImageProportions(img, overwriteWidth, overwriteHeight);
			return true;
		} else if (hideWhenNotFound) {
			img.Show(false);
		}
		return false;
	}
	
	private void UpdateImageProportions(ImageWidget img, int overwriteWidth = -1, int overwriteHeight = -1) {
		int width, height;
		img.GetImageSize(0, width, height);
		float nWidth, nHeight, screenWidth, screenHeight;
		GetOriginalImageSize(img, screenWidth, screenHeight, overwriteWidth, overwriteHeight);
		Print("Image Size: " + width + "x" + height + " Image Widget Size: " + screenWidth + "x" + screenHeight);
		if (width <= screenWidth && height <= screenHeight) {
			img.SetScreenSize(width, height);
		} else if (width <= screenWidth && height >= screenHeight) {
			nWidth = ((float) width) * screenHeight / height;
			img.SetScreenSize(nWidth, height);
		} else if (height <= screenHeight && width >= screenWidth) {
			nHeight = ((float) height) * screenWidth / width;
			img.SetScreenSize(width, nHeight);
		} else {
			float scaleW = screenWidth / width;
			float scaleH = screenHeight / height;
			float min = Math.Min(scaleW, scaleH);
			img.SetScreenSize(width * min, height * min);
		}
	}
	
	override void OnShow() {
		super.OnShow();
		RLLogger.Verbose("Showing Menu " + Type() + "...", "Core");
		GetGame().GetGame().GetUIManager().ShowUICursor(!DisableCursor());
        Mission mission = GetGame().GetMission();
        if (mission) {
			if (DisableCharacterControl()) {
				mission.PlayerControlDisable(INPUT_EXCLUDE_INVENTORY);
			}
			if (DisableHud()) {
	            IngameHud hud = IngameHud.Cast( mission.GetHud() );
	            if (hud) {
	                hud.ShowQuickbarUI( false );
	                hud.ShowHud( false );
	            }
			}
        }
	}
	
	override void OnHide() {
		super.OnHide();
		RLLogger.Verbose("Hiding Menu " + Type() + " force open: " + forceMenuOpen, "Core");
		Mission mission = GetGame().GetMission();
        if (mission) {
			if (DisableHud()) {
	            IngameHud hud = IngameHud.Cast( mission.GetHud() );
	            if (hud) {
	                hud.ShowQuickbarUI( true );
	                hud.ShowHud( true );
	            }
			}
			if (DisableCharacterControl()) {
				mission.PlayerControlEnable(true);
			}
        }
		if (forceMenuOpen) {
			RLMenuManager.Get().ForceOpenMenu(GetPriority(), Type(), this);
		}
	}
	
	override void Update(float timeslice) {
		super.Update(timeslice);
		if( GetGame() && GetGame().GetInput() && GetGame().GetInput().LocalPress("UAUIBack", false) ) {
			CloseMe(false);
		}
		
	}
	
}