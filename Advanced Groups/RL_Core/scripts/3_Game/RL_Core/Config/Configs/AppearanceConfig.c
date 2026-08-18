class RLAppearanceConfig : RLConfigLoader<RLAppearanceConfig_> {

	override void InitVars() {
		InitVarsInternal("Common", "Appearance.json", RLConfigType.CONFIG, true, "appearance.change");
	}
	
}
// This file contains the path to your logo, which will be displayed on some mod menus. The default path is set to `RL_Server_Logo/gui/images/logo.paa`
// A PBO with the logo inside at this location can easily be generated via [The Logo Generator](https://RayLab.de/logo/)
// Follow the instructions there to generate the PBO
class RLAppearanceConfig_ : RLConfigBase {

	private string logoPath = "RL_Server_Logo/gui/images/logo.paa"; // Path to the logo displayed in some mod menus. Currently only used by Advanced Groups and Virtual Garage.
	
	void LoadLogo(ImageWidget widget) {
		if (!widget)
			return;
		bool exists = FileExist(logoPath);
		Print("Loading Logo: " + logoPath + ". Exists ? " + exists);
		widget.LoadImageFile(0, logoPath);
		widget.Show(exists);
	}
	
}