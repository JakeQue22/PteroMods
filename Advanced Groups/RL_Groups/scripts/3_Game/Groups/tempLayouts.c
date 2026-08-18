modded class RLLayoutManager {
	
	override string GetLayoutPathOriginal(string name) {
		if (name == "Map Page 0 0") return "RayLab_Groups/gui/layouts/mapmenu/pages/page_0_0_default.layout";
		if (name == "Map Page 1 0") return "RayLab_Groups/gui/layouts/mapmenu/pages/page_1_0_default.layout";
		if (name == "Map Page 1 1") return "RayLab_Groups/gui/layouts/mapmenu/pages/page_1_1_default.layout";
		if (name == "Map Page 2 0") return "RayLab_Groups/gui/layouts/mapmenu/pages/page_2_0_default.layout";
		if (name == "Map Page 3 0") return "RayLab_Groups/gui/layouts/mapmenu/pages/page_3_0_default.layout";
		if (name == "Map Page 4 0") return "RayLab_Groups/gui/layouts/mapmenu/pages/page_4_0_default.layout";
		if (name == "Map Page 5 0") return "RayLab_Groups/gui/layouts/mapmenu/pages/page_5_0_default.layout";
		if (name == "Map Page 6 0") return "RayLab_Groups/gui/layouts/mapmenu/pages/page_6_0_default.layout";
		if (name == "Map Page 7 0") return "RayLab_Groups/gui/layouts/mapmenu/pages/page_7_0_default.layout";
		if (name == "Map Page 8 0") return "RayLab_Groups/gui/layouts/mapmenu/pages/page_8_0_default.layout";
		if (name == "Map Page 9 0") return "RayLab_Groups/gui/layouts/mapmenu/pages/page_9_0_default.layout";
		
		return super.GetLayoutPathOriginal(name);
	}
	
}