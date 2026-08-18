modded class MissionGameplay {

	void MissionGameplay() {
		GetDayZGame().RegisterRLAdminMenuPage(RLUpdateCheckerMenu);
		GetDayZGame().RegisterRLAdminMenuPage(CurrenciesAdminMenu);
	}
}