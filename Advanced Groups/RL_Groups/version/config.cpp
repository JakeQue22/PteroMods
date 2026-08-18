class CfgPatches {
    class Advanced_Groups_Version {
        requiredVersion = 1.0;
        requiredAddons[] = {};
    };
};
class CfgMods {
    class Advanced_Groups_Version {
        dir="Advanced_Groups_Version";
        name="Advanced_Groups_Version";
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
                    "RayLab_Groups/version/scripts"
                };
            };
        };
    };
};
