class CfgPatches {
    class Core_Version {
        requiredVersion = 1.0;
        requiredAddons[] = {};
    };
};
class CfgMods {
    class Core_Version {
        dir="Core_Version";
        name="Core_Version";
        type="mod";
        dependencies[]=
        {
            "gamelib"
        };
        class defs
        {
            class gameLibScriptModule
            {
                value="";
                files[]=
                {
                    "RayLab_Core/version/scripts"
                };
            };
        };
    };
};
