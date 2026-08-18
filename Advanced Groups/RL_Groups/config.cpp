class CfgPatches
{
	class RayLab_Groups
	{
		units[]={};
		weapons[]={};
		requiredVersion=0.1;
		requiredAddons[]={
			"DZ_Data",
			"DZ_Gear_Navigation",
			#ifdef PVEZ
			"PVEZ",
			#endif
			#ifdef THKOTH
			"KingOfTheHillCore",
			#endif
			#ifdef VPPADMINTOOLS
			"DZM_VPPAdminTools",
			#endif
			#ifdef EXPANSIONMODMISSIONS
			"DayZExpansion_Missions_Scripts",
			#endif
			#ifdef ND_MISSIONS
			"ND_MISSIONS",
			#endif
			#ifdef ND_RP
			"ND_RP",
			#endif
			#ifdef CarePackage
			"CarePackage",
			#endif
			#ifdef CarePackageV2
			"CarePackageV2",
			#endif
			#ifdef DZ_Expansion_Market
			"DayZExpansion_Market_Scripts",
			#endif
			"DZ_Scripts",
			"RayLab_Core"
		};
	};
	
	class RayLab_GroupDLCPlotpole {};
};
class CfgMods
{
	class RayLab_Groups
	{
		dir="RayLab_Groups";
		name="Advanced Groups";
		
		version="2.0";
		type="mod";
		author="RayLab";
		credits="RayLab";
		authorID="";
		hideName=0;
		hidePicture=0;
		picture="";
		logoSmall="";
		logo="";
		logoOver="";
		tooltip="";
		overview="";
		action="";
		defines[]= {
			"RLGroup_SYSTEM",
			"RLGroup_SYSTEM_NEW"
		};
		inputs="RayLab_Groups/inputsRayLab.xml";
		dependencies[]=
		{
			"Game",
			"World",
			"Mission"
		};
		class defs
		{
			class gameScriptModule
			{
				value="";
				files[]=
				{
					"VPPAdminTools/Definitions",
					"KingOfTheHillAssets/scripts/Common",
					"DayZExpansion/Missions/Scripts/Common",
					"0_DayZExpansion_Missions_Preload/Common",
					"RayLab_Groups/scripts/3_Game"
				};
			};
			class worldScriptModule
			{
				value="";
				files[]=
				{
					"VPPAdminTools/Definitions",
					"KingOfTheHillAssets/scripts/Common",
					"DayZExpansion/Missions/Scripts/Common",
					"0_DayZExpansion_Missions_Preload/Common",
					"RayLab_Groups/scripts/4_World"
				};
			};
			class missionScriptModule
			{
				value="";
				files[]=
				{
					"VPPAdminTools/Definitions",
					"KingOfTheHillAssets/scripts/Common",
					"DayZExpansion/Missions/Scripts/Common",
					"0_DayZExpansion_Missions_Preload/Common",
					"RayLab_Groups/scripts/5_Mission"
				};
			};
		};
	};
};
