modded class DayZGame {

	private UAInput inputAdminMenu, inputToggleAdminMode;
	private ref RLAdmin_Menu rlAdminMenu;
	private ref TTypenameArray rlRegisteredAdminPages = new TTypenameArray();
	ref map<int, typename> activatePageRPCs = new map<int, typename>();
	
	void DayZGame() {
		RLAdmins.Get();
		#ifndef NO_GUI
		inputAdminMenu = GetUApi().GetInputByName("UARLAdminMenuOpen");
		inputToggleAdminMode = GetUApi().GetInputByName("UARLAdminMenuToggle");
		#endif
	}
	
	override void OnRPC(PlayerIdentity sender, Object target, int rpc_type, ParamsReadContext ctx) {
		super.OnRPC(sender, target, rpc_type, ctx);
		
		typename open;
		if (activatePageRPCs.Find(rpc_type, open)) {
			ShowRayLabAdminMenu();
			rlAdminMenu.SetPageActive(open);
			
		}
		if (rpc_type == RayLab_Core_RPCs.ADMIN_TOGGLE) {
			if (GetGame().IsServer()) {
				Param1<bool> enableParam;
				if (!ctx.Read(enableParam))
					return;
				string steamid = sender.GetPlainId();
				bool enabled = enableParam.param1 && RLAdmins.Get().CanEnableAdminMenu(steamid);
				if (enabled) {
					RLAdmins.Get().EnableAdminModeFor(sender);
				} else {
					RLAdmins.Get().DisableAdminModeFor(sender);
				}
				RLLogger.Info("Admin Mode enabled set to " + enabled + " for " + RLLogger.FormatPlayerIdentity(sender), "AdminConfig");
			}
		} else if (rpc_type == RayLab_Core_RPCs.ADMIN_SYNC) {
			if (GetGame().IsServer()) {
				ScriptRPC rpc = new ScriptRPC();
				RLAdmins.Get().WriteToCtx(rpc, sender);
				if (sender)
					rpc.Send(null, RayLab_Core_RPCs.ADMIN_SYNC, true, sender);
			} else if (!RLAdmins.Get().LoadFromCtx(ctx)) {
				RLLogger.Error("Could not receive RayLab Admin config !", "AdminConfig");
				return;
			} else {
				RLLogger.Info("Successfully received Config !", "AdminConfig");
			}
		} else if (rlAdminMenu) {
			rlAdminMenu.OnRPC(sender, target, rpc_type, ctx);
		}
	}
	
	#ifndef NO_GUI
	override void OnUpdate(bool doSim, float timeslice) {
		RLWarningPopup.OnFrame();
		CheckRLAdminMenuKeys();
		super.OnUpdate(doSim, timeslice);
	}
	
	override void OnKeyPress(int key) {
		super.OnKeyPress(key);
		RLAutoComplete.Get().KeyDown(key);
		if (rlAdminMenu) {
			rlAdminMenu.KeyDown(key);
		}
	}
	
	void CheckRLAdminMenuKeys() {
		if (RLAdmins.Loaded() && RLAdmins.Get().CanEnableAdminMenu()) {
			if (inputAdminMenu && inputAdminMenu.LocalPress()) {
				if (RLAdmins.Get().IsActive() && GetGame().GetUIManager().GetMenu() == null) {
					ShowRayLabAdminMenu();
				}
			} else if (inputToggleAdminMode && inputToggleAdminMode.LocalPress() && GetGame().GetUIManager().GetMenu() == null) {
				RLAdmins.Get().ToggleActive();
				UAInput vppSuppressed = GetUApi().GetInputByName("UAToggleInvis");
				if (vppSuppressed) {
					vppSuppressed.Supress();
				}
				if (RLAdmins.Get().IsActive()) {
					NotificationSystem.AddNotificationExtended(4.0, "RayLab Admin", "Admin Mode enabled", RLIconConfig.Get.info);
				} else {
					NotificationSystem.AddNotificationExtended(4.0, "RayLab Admin", "Admin Mode disabled", RLIconConfig.Get.info);
				}
			}
		}
	}
	
	#endif
	
	bool IsRLAdminMenuOpen() {
		return rlAdminMenu != null;
	}
	
	void RegisterRLAdminMenuPage(typename page, int openRPC = 0) {
		rlRegisteredAdminPages.Insert(page);
		if (openRPC != 0) {
			activatePageRPCs.Insert(openRPC, page);
		}
	}
	
	void ClearRegisteredPages() {
		rlRegisteredAdminPages.Clear();
	}
	
	RLAdmin_Menu_Page GetPage(typename page) {
		return rlAdminMenu.GetPage(page);
	}
	
	RLAdmin_Menu_Page ShowRayLabAdminMenu(string activeCategory = "") {
		if (rlAdminMenu) {
			return rlAdminMenu.SetPageActive(activeCategory);
		} else {
			rlAdminMenu = new RLAdmin_Menu();
			GetUIManager().CloseAll();
			GetUIManager().ShowScriptedMenu(rlAdminMenu, null);
			foreach (typename registeredPage : rlRegisteredAdminPages) {
				rlAdminMenu.RegisterPage(registeredPage);
			}
			return rlAdminMenu.SetPageActive(activeCategory);
		}
	}
	
}

