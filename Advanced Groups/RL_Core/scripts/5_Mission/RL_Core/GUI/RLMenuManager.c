
typedef Param3<int, typename, int> RLForceOpenMenuParam;
class RLMenuManager : Managed {

	static ref RLMenuManager g_RLMenuHandler;
	
	static RLMenuManager Get() {
		if (!g_RLMenuHandler)
			g_RLMenuHandler = new RLMenuManager();
		return g_RLMenuHandler;
	}
	
	ref array<ref RLMenuBase> menuInstances = new array<ref RLMenuBase>();
	ref array<ref RLMenuData> menuParams = new array<ref RLMenuData>();
	ref array<ref RLForceOpenMenuParam> forceOpenMenus = new array<ref RLForceOpenMenuParam>();
	
	ref Timer forceOpenTimer = new Timer();
	
	void RLMenuManager() {
		forceOpenTimer.Run(0.1, this, "TryOpenForcedMenu", null, true);
	}
	
	void ~RLMenuManager() {
		if (forceOpenTimer)
			forceOpenTimer.Stop();
		if (GetGame() && GetGame().GetUIManager()) {
			foreach (RLMenuBase openMenu : menuInstances) {
				if (!openMenu)
					continue;
				GetGame().GetUIManager().HideScriptedMenu(openMenu);
			}
		}
		menuInstances.Clear();
		menuParams.Clear();
		forceOpenMenus.Clear();
	}
	
	RLMenuBase TryOpenForcedMenu() {
		if (!forceOpenMenus || forceOpenMenus.Count() == 0)
			return null;
		RLForceOpenMenuParam mostImportant = null;
		foreach (RLForceOpenMenuParam force : forceOpenMenus) {
			if (mostImportant == null || mostImportant.param1 < force.param1) {
				mostImportant = force;
				RLLogger.Verbose("Force Open found candidate " + mostImportant.param2 + " of " + forceOpenMenus.Count() + " candidates with Parameter " + mostImportant.param3 + " and Prio " + mostImportant.param1, "Core");
			}
		}
		
		RLMenuBase menu = OpenMenu(mostImportant.param2, menuParams.Get(mostImportant.param3), true, null, false);
		if (menu)
			forceOpenMenus.RemoveItem(mostImportant);
		return menu;
	}
	
	void ForceOpenMenu(int prio, typename menu, RLMenuBase rlMenu) {
		int index = menuInstances.Find(rlMenu);
		forceOpenMenus.Insert(new RLForceOpenMenuParam(prio, menu, index));
	}
	
	RLMenuBase OpenMenu(typename menu, RLMenuData openParameter = null, bool forceOpen = false, UIScriptedMenu parent = null, bool doForceOpenCheck = true) {
		RLLogger.Verbose("Opening Menu: " + menu + " with Parameter " + openParameter + " Force Open: " + forceOpen + " Parent: " + parent, "Core");
		if (!menu.IsInherited(RLMenuBase))
			return null;
		Class obj = menu.Spawn();
		if (!obj)
			return null;
		RLMenuBase rlMenu = RLMenuBase.Cast(obj);
		if (doForceOpenCheck) {
			if (forceOpen) {
				menuInstances.Insert(rlMenu);
				menuParams.Insert(openParameter);
				ForceOpenMenu(rlMenu.GetPriority(), menu, rlMenu);
			}
			RLMenuBase force = TryOpenForcedMenu();
			if (force)
				return force;
		}
		if (!CanOpenMenu(rlMenu))
			return null;
		GetGame().GetUIManager().CloseAll();
		menuInstances.Insert(rlMenu);
		menuParams.Insert(openParameter);
		GetGame().GetUIManager().ShowScriptedMenu(rlMenu, parent);
		rlMenu.SetForceOpen(forceOpen);
		rlMenu.openParameter = openParameter;
		rlMenu.OnDataReceived();
		return rlMenu;
	}
	
	private bool CanOpenMenu(RLMenuBase menu) {
		if (!GetGame() || !GetGame().GetUIManager())
			return false;
		RLMenuBase openMenu = GetOpenRLMenu();
		return !openMenu || openMenu.GetPriority() <= menu.GetPriority();
	}
	
	RLMenuBase GetOpenRLMenu() {
		return RLMenuBase.Cast(GetGame().GetUIManager().GetMenu());
	}
	
}