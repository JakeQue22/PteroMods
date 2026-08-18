class RLGroupPermission {

	int UID, nextGroupUID, previousGroupUID, inheritGroupUID;
	string permName;
	ref array<ref Param2<int, bool>> markerPermissions = new array<ref Param2<int, bool>>();
	bool canUpgrade;
	bool canPromote;
	bool canDemote;
	bool canInvite;
	int promotePower;
	int demotePower;
	int moveSubgroupPower;
	int promoteNeedPower;
	int demoteNeedPower;
	int moveSubgroupNeedPower;
	bool canDoBasebuilding = false;
	bool canPackPlotpole = false;
	bool canCreateSeeATMAccount = false;
	bool canCreateGroupATMAccount = false;
	bool canWithdrawGroupMoney = false;
	bool canDepositGroupMoney = false;
	bool canOpenGroupGarage = false;
	bool tempGroup = false;
	
	[NonSerialized()]
	bool inheritedMarkerPermissions = false;
	
	
	bool CanPromote(RLGroupPermission target) {
		if (!target || target.nextGroupUID == -1)
			return false;
		RLGroupPermission next = target.GetNextGroup();
		if (!next)
			return false;
		return promotePower >= next.promoteNeedPower;
	}
	
	bool CanDemote(RLGroupPermission target) {
		if (!target || target.previousGroupUID == -1)
			return false;
		return demotePower >= target.demoteNeedPower;
	}
	
	bool CanKick(RLGroupPermission target) {
		if (!target)
			return false;
		return demotePower >= target.demoteNeedPower;
	}
	
	bool CanMove(RLGroupPermission target) {
		if (!target)
			return false;
		return moveSubgroupPower >= target.moveSubgroupNeedPower;
	}
	
	bool CanSeeMarkerType(RLMarkerType type) {
		foreach (Param2<int, bool> allowed : markerPermissions) {
			if (allowed.param1 == type)
				return allowed.param2;
		}
		return false;
	}
	
	void FillInherited(RLGroupPermission inherited) {
		canInvite = canInvite || inherited.canInvite;
		canUpgrade = canUpgrade || inherited.canUpgrade;
		canPromote = canPromote || inherited.canPromote;
		canDoBasebuilding = canDoBasebuilding || inherited.canDoBasebuilding;
		canPackPlotpole = canPackPlotpole || inherited.canPackPlotpole;
		canCreateGroupATMAccount = canCreateGroupATMAccount || inherited.canCreateGroupATMAccount;
		canCreateSeeATMAccount = canCreateSeeATMAccount || inherited.canCreateSeeATMAccount;
		canWithdrawGroupMoney = canWithdrawGroupMoney || inherited.canWithdrawGroupMoney;
		canDepositGroupMoney = canDepositGroupMoney || inherited.canDepositGroupMoney;
		canDemote = canDemote || inherited.canDemote;
		canOpenGroupGarage = canOpenGroupGarage || inherited.canOpenGroupGarage;
		promotePower = Math.Max(promotePower, inherited.promotePower);
		demotePower = Math.Max(demotePower, inherited.demotePower);
		moveSubgroupPower = Math.Max(moveSubgroupPower, inherited.moveSubgroupPower);
		promoteNeedPower = Math.Max(promoteNeedPower, inherited.promoteNeedPower);
		demoteNeedPower = Math.Max(demoteNeedPower, inherited.demoteNeedPower);
		moveSubgroupNeedPower = Math.Max(moveSubgroupNeedPower, inherited.moveSubgroupNeedPower);
		if (!inheritedMarkerPermissions) {
			inheritedMarkerPermissions = true;
			foreach (Param2<int, bool> othermarkerPerms : inherited.markerPermissions) {
				bool found = false;
				foreach (Param2<int, bool> mymarkerPerms : markerPermissions) {
					if (othermarkerPerms.param1 == mymarkerPerms.param1) {
						mymarkerPerms.param2 = mymarkerPerms.param2 || othermarkerPerms.param2;
						found = true;
					}
				}
				if (!found) {
					markerPermissions.Insert(new Param2<int, bool>(othermarkerPerms.param1, othermarkerPerms.param2));
				}
			}
		}
	}
	
	void PrintPermission() {
		RLLogger.Debug(permName + " Temp ? " + tempGroup + " (" + UID + ") < " + previousGroupUID + " > " + nextGroupUID + " : " + inheritGroupUID, "AdvancedGroups");
		RLLogger.Debug("canUpgrade: " + canUpgrade + " canPromote: " + canPromote + " canDemote: " + canDemote + " canInvite: " + canInvite + " canPackPlotpole: " + canPackPlotpole + " canDoBasebuilding: " + canDoBasebuilding, "AdvancedGroups");
		RLLogger.Debug("canCreateSeeATMAccount: " + canCreateSeeATMAccount + " canCreateGroupATMAccount: " + canCreateGroupATMAccount + " canWithdrawGroupMoney: " + canWithdrawGroupMoney + " canDepositGroupMoney: " + canDepositGroupMoney, "AdvancedGroups");
		RLLogger.Debug("promotePower: " + promotePower + " demotePower: " + demotePower + " promoteNeedPower: " + promoteNeedPower + " demoteNeedPower: " + demoteNeedPower, "AdvancedGroups");
		foreach (Param2<int, bool> markerPerms : markerPermissions) {
			RLLogger.Debug("Marker Perm: " + markerPerms.param1 + " : " + markerPerms.param2, "AdvancedGroups");
		}
	}
	
	void FillInheritedPermissions() {
		RLGroupPermission inherited = RLGroupPermissions.Get.FindPermissionGroupByUID(inheritGroupUID);
		if (inherited) {
			inherited.FillInheritedPermissions();
			FillInherited(inherited);
		}
	}
	
	RLGroupPermission GetPreviousGroup() {
		return RLGroupPermissions.Get.FindPermissionGroupByUID(previousGroupUID);
	}
	
	RLGroupPermission GetNextGroup() {
		return RLGroupPermissions.Get.FindPermissionGroupByUID(nextGroupUID);
	}
	
}
class RLGroupPermissions : RLConfigLoader<RLGroupPermissions_> {

	override void InitVars() {
		InitVarsInternal("RLGroup", "Permissions.json", RLConfigType.CONFIG, true, "group.permissions.change");
	}
	
}

class RLGroupPermissions_ : RLConfigBase {

	ref array<ref RLGroupPermission> allGroups = new array<ref RLGroupPermission>();
	
	override void JsonLoadVar(string path, bool forceValid, out bool overwriteTest) {
		RLJsonLoader<array<ref RLGroupPermission>>.JsonLoadFile(path, allGroups, forceValid);
	}
	override void JsonSaveVar(string path, out bool overwriteTest) {
		RLJsonLoader<array<ref RLGroupPermission>>.JsonSaveFile(path, allGroups);
	}
	
	override bool OnLoad() {
		LoadInheritence();
		PrintAllPermissionGroups();
		return false;
	}
	
	override void LoadDefault() {
		RLGroupPermission temp = new RLGroupPermission();
		temp.UID = 1;
		temp.nextGroupUID = 3;
		temp.previousGroupUID = -1;
		temp.inheritGroupUID = -1;
		temp.permName = "Temp";
		temp.markerPermissions.Insert(new Param2<int, bool>(RLMarkerType.SERVER_STATIC, true)); // 0
		temp.markerPermissions.Insert(new Param2<int, bool>(RLMarkerType.SERVER_DYNAMIC, true)); // 1
		temp.markerPermissions.Insert(new Param2<int, bool>(RLMarkerType.GROUP_PING, true)); // 2
		temp.markerPermissions.Insert(new Param2<int, bool>(RLMarkerType.GROUP_MARKER, false)); // 3
		temp.markerPermissions.Insert(new Param2<int, bool>(RLMarkerType.PRIVATE_MARKER, true)); // 4
		temp.markerPermissions.Insert(new Param2<int, bool>(RLMarkerType.GROUP_PLAYER_MARKER, true)); // 5
		temp.canPromote = false;
		temp.canDemote = false;
		temp.promotePower = 0;
		temp.demotePower = 0;
		temp.moveSubgroupPower = 0;
		temp.promoteNeedPower = 20;
		temp.demoteNeedPower = 20;
		temp.moveSubgroupNeedPower = 20;
		temp.tempGroup = true;
		temp.canPackPlotpole = false;
		temp.canDoBasebuilding = false;
		temp.canCreateGroupATMAccount = false;
		temp.canCreateSeeATMAccount = false;
		temp.canWithdrawGroupMoney = false;
		temp.canDepositGroupMoney = false;
		allGroups.Insert(temp);
		RLGroupPermission trial = new RLGroupPermission();
		trial.UID = 3;
		trial.nextGroupUID = 5;
		trial.previousGroupUID = 1;
		trial.inheritGroupUID = 1;
		trial.permName = "Trial";
		trial.tempGroup = false;
		allGroups.Insert(trial);
		RLGroupPermission member = new RLGroupPermission();
		member.UID = 5;
		member.nextGroupUID = 7;
		member.previousGroupUID = 3;
		member.inheritGroupUID = 3;
		member.canCreateSeeATMAccount = true;
		member.canDepositGroupMoney = true;
		member.canOpenGroupGarage = true;
		member.permName = "Member";
		member.tempGroup = false;
		member.canDoBasebuilding = true;
		member.markerPermissions.Insert(new Param2<int, bool>(RLMarkerType.GROUP_MARKER, true));
		allGroups.Insert(member);
		RLGroupPermission admin = new RLGroupPermission();
		admin.UID = 7;
		admin.nextGroupUID = 9;
		admin.previousGroupUID = 5;
		admin.inheritGroupUID = 5;
		admin.permName = "Admin";
		admin.tempGroup = false;
		admin.canPromote = true;
		admin.canDemote = true;
		admin.canUpgrade = true;
		admin.canInvite = true;
		admin.canPackPlotpole = true;
		member.canCreateGroupATMAccount = true;
		member.canWithdrawGroupMoney = true;
		admin.promotePower = 30;
		admin.demotePower = 30;
		admin.moveSubgroupPower = 30;
		admin.promoteNeedPower = 40;
		admin.demoteNeedPower = 40;
		admin.moveSubgroupNeedPower = 30;
		allGroups.Insert(admin);
		RLGroupPermission leader = new RLGroupPermission();
		leader.UID = 9;
		leader.nextGroupUID = -1;
		leader.previousGroupUID = 7;
		leader.inheritGroupUID = 7;
		leader.permName = "Leader";
		leader.tempGroup = false;
		leader.promotePower = 40;
		leader.demotePower = 40;
		leader.moveSubgroupPower = 40;
		leader.promoteNeedPower = 50;
		leader.demoteNeedPower = 50;
		leader.moveSubgroupNeedPower = 30;
		allGroups.Insert(leader);
	}
	
	void PrintAllPermissionGroups() {
		foreach (RLGroupPermission perm : allGroups) {
			perm.PrintPermission();
		}
	}
	
	void LoadInheritence() {
		foreach (RLGroupPermission perm : allGroups) {
			perm.FillInheritedPermissions();
		}
	}
	
	RLGroupPermission FindHighestGroup(string groupTag = "") {
		foreach (RLGroupPermission perm : allGroups) {
			if (perm.nextGroupUID == -1)
				return perm;
		}
		return null;
	}
	
	RLGroupPermission FindLowestGroup(string groupTag = "") {
		foreach (RLGroupPermission perm : allGroups) {
			if (perm.previousGroupUID == -1)
				return perm;
		}
		return null;
	}
	
	RLGroupPermission FindPermissionGroupByUID(int uid) {
		if (uid == -1)
			return null;
		foreach (RLGroupPermission perm : allGroups) {
			if (perm.UID == uid)
				return perm;
		}
		return null;
	}
	
	RLGroupPermission GetNextPermission(RLGroupPermission perm) {
		if (!perm)
			return null;
		return FindPermissionGroupByUID(perm.nextGroupUID);
	}
		
	RLGroupPermission GetNextPermission(int uid) {
		return GetNextPermission(FindPermissionGroupByUID(uid));
	}

	RLGroupPermission GetPreviousPermission(RLGroupPermission perm) {
		if (!perm)
			return null;
		return FindPermissionGroupByUID(perm.nextGroupUID);
	}
		
	RLGroupPermission GetPreviousPermission(int uid) {
		return GetPreviousPermission(FindPermissionGroupByUID(uid));
	}
	
}