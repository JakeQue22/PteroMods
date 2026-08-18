#ifndef RL_DISABLE_CHAT
modded class ChatInputMenu {
	
	override Widget Init()
	{
		Widget old = super.Init(); // Call super to init "m_BackInputWrapper" which causes many nullpointers otherwise
		old.Unlink(); // Immediately destroy the Widget, which has been created using super.Init();
		RLLogger.Debug("Init Chat Menu ", "AdvancedGroups");
		layoutRoot = RLLayoutManager.Get().CreateLayout("ChatInput", "RayLab_Groups/gui/layouts/day_z_chat_input.layout");
		m_edit_box = EditBoxWidget.Cast( layoutRoot.FindAnyWidget("InputEditBoxWidget") );
		
		UpdateChannel();
		RLLogger.Debug("Finished Init Chat Menu", "AdvancedGroups");
		return layoutRoot;
	}

    override bool OnChange (Widget w, int x, int y, bool finished) {
		UpdateChannel();
        if (UIScriptedWindow.GetActiveWindows()) {
            for (int i = 0; i < UIScriptedWindow.GetActiveWindows().Count(); i++) {
                if (UIScriptedWindow.GetActiveWindows().GetElement(i).OnChange(w, x, y, finished))
					return true;
            }
        }
		if (!finished)
			return false;

        string text = m_edit_box.GetText();
		if (text.Length() > 200)
			text = text.Substring(0, 200);
        if (text != "") {
			if (text[0] == "2" || text[0] == "+")
				text = "2" + text;
			MissionGameplay.Cast(GetGame().GetMission()).SendChatMessage(text);
        }

        m_close_timer.Run(0.1, this, "Close");

        GetUApi().GetInputByName("UAPersonView").Supress();

        return true;
    }
	
	override void OnShow() {
		super.OnShow();
		UpdateChannel();
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		mission.DisplayVoiceLevels(false);
		// Thanks to @DaOne and his VPPAdminTools for breaking my mod and having to make a workaround to get it working again.
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(UpdateChannel, 20, true);
	}
	
	override void OnHide() {
		super.OnHide();
		UpdateChannel();
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		mission.DisplayVoiceLevels(true);
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Remove(UpdateChannel);
	}
	
	override void UpdateChannel() {
		TextWidget channel_text = TextWidget.Cast( layoutRoot.FindAnyWidget("ChannelText") );
		channel_text.Show(m_edit_box.GetText().Length() == 0);
		string channel = "";
		MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
		if (mission)
			channel = mission.GetCurrentChannel();
		channel_text.SetText(channel);
	}
	
}
#endif