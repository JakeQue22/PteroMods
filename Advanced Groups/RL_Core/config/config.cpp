class CfgPatches {
	
	class RayLab_Core {
		units[]={};
		weapons[]={};
		requiredVersion=0.1;
		requiredAddons[]={
			"DZ_Data"
		};
	};
	
};
class CfgMods
{
	class RayLab_Core
	{
		dir="RayLab_Core";
		name="RayLab Core Library";
		version="3.0";
		type="mod";
		author="RayLab";
		credits="RayLab";
		authorID="";
		hideName=0;
		hidePicture=0;
		tooltip="RayLabs Core Script Files Library";
		action="";
		inputs="RayLab_Core/data/inputs.xml";
		dependencies[]=
		{
			"Game",
			"World",
			"Mission"
		};
		class defs
		{
			class widgetStyles
			{
				files[]=
				{
					"RayLab_Core/gui/styles/rlstyles.styles"
				};
			};
			class imageSets
			{
				files[]=
				{
					"RayLab_Core/gui/imagesets/rl_core_set.imageset"
				};
			};
			class gameScriptModule
			{
				value="";
				files[]=
				{
					"RayLab_Core/scripts/3_Game"
				};
			};
			class worldScriptModule
			{
				value="";
				files[]=
				{
					"RayLab_Core/scripts/4_World"
				};
			};
			class missionScriptModule
			{
				value="";
				files[]=
				{
					"RayLab_Core/scripts/5_Mission"
				};
			};
		};
	};
};