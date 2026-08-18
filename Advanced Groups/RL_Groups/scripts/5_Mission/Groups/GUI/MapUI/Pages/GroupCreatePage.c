class RLGroupCreatePage : RLGroupPage {
	
	EditBoxWidget input_groupname, input_groupnametag;
	ButtonWidget btn_create,btn_accept;
	TextWidget txt_obfName;

	override bool InitPage(RLGroupUI parentUI) {
		bool worked = super.InitPage(parentUI, 1, 0, "#rl_page_group", false);
		if (buttonWidget)
			parentUI.groupButton = buttonWidget;
		return worked;
	}
	
	override void StoreAllWidgetData(RLDataSerializer data) {
		data.Write(new Param2<string, string>(input_groupname.GetText(), input_groupnametag.GetText()));
	}
	
	override void RestoreAllWidgetData(RLDataSerializer data) {
		Param2<string, string> inputParam = Param2<string, string>.Cast(data.Read());
		input_groupname.SetText(inputParam.param1);
		input_groupnametag.SetText(inputParam.param2);
	}
	
	override bool OnClick(Widget w) {
		if (w == btn_create) {
			string groupname = input_groupname.GetText();
			string grouptag = input_groupnametag.GetText();
			SendCreateGroupRPC(groupname, grouptag);
			return true;
		} else if (w == btn_accept) {
			MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
			if (mission)
				mission.AcceptGroupInvite();
			SetInviteButton(false, "");
		}
		return false;
	}
	
	void SetInviteButton(bool show, string group) {
		btn_accept.Show(show);
		btn_accept.SetText("Accept Invite for " + group);
	}
	
	override bool OnTopButtonClicked(Widget w) {
		if (super.OnTopButtonClicked(w)) {
			PlayerBase pb = PlayerBase.Cast(GetGame().GetPlayer());
			if (!pb)
				return false;
			return pb.GetRLGroup() == null;
		}
		return false;
	}
	
	override bool CanDisplayButton() {
		return RLGroupMainConfig.Get.IsGroupCreationEnabled();
	}
	
	override void InitMainWidget() {
		ConnectClassWidgetVariables(this, rootWidget, {"rootWidget", "buttonWidget"});
		TextWidget groupCostWidget = TextWidget.Cast(rootWidget.FindAnyWidget("groupCreationInfo"));
		groupCostWidget.SetText("#rl_group_create_info " + RLCurrencyConfig.Get.GetFormattedMoneyString(RLGroupMainConfig.Get.groupCreationCost));
		
		txt_obfName.Show(RLGroupMainConfig.Get.groupManagePageObfuscatePlayernames);
		txt_obfName.SetText("#rl_invite_name " + GetObfName());
	}
	
	string GetObfName() {
		string steamid = RLAdmins.Get().GetMySteamid();
		foreach (SyncPlayer player : ClientData.m_PlayerList.m_PlayerList) {
			if (player.m_UID == steamid) {
				return RLGroupMainConfig.Get.ObfuscatePlayerName(player.m_PlayerName, player.m_UID.Hash());
			}
		}
		return "";
	}
	
	void SendCreateGroupRPC(string name, string tag) {
		GetGame().RPCSingleParam(null, RLGroupRPCs.GROUP_CREATE, new Param2<string, string>(name, tag), true);
	}
	
}