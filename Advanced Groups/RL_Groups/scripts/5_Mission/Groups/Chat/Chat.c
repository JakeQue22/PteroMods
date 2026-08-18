#ifndef RL_DISABLE_CHAT
modded class Chat {

	bool addedPrefix = false;
	Widget rootChatWidget;
	float prefixLength = 0;
	
	bool added = false;
	
	private string nextPrefix, nextGroupPrefix;
	
	override void Init(Widget root_widget) {
		RLPositionManager.Event_OnPositionChange.Remove(UpdatePosition);
		Widget other = RLLayoutManager.Get().CreateLayout("Chat", "RayLab_Groups/gui/layouts/chatmain.layout", null);
		if (other) {
			Widget other2 = other.FindAnyWidget("ChatFrameWidget");
			if (other2)
				root_widget = other2;
		}
		super.Init(root_widget);
		rootChatWidget = root_widget;
		RLPositionManager.Event_OnPositionChange.Insert(UpdatePosition);
		UpdatePosition();
	}
	
	void ~Chat() {
		if (RLPositionManager.Event_OnPositionChange)
			RLPositionManager.Event_OnPositionChange.Remove(UpdatePosition);
	}
	
	void AddRLChat(int channel, string channelName, string name, string message, string extra, string prefix, int prefixColor, string groupPrefix, int channelColor, int groupPrefixColor, bool changeGroupTagColor) {
		if (channel & CCDirect) {
			MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
			channelName = mission.GetDirectChannelName();
		}
		if (RLMarkerVisibilityManager.Get().IsChannelHidden(channelName))
			return;
		
		SetNextPrefix(prefix, prefixColor, groupPrefix, channelColor, groupPrefixColor, changeGroupTagColor);
		addedPrefix = true;
		RLTextLengthCalculator calc = RLTextLengthCalculator.Get();
		int size = RLMarkerVisibilityManager.Get().GetChatSize();
		prefixLength = calc.GetTextLength(groupPrefix + " " + prefix, size);
		Add(new ChatMessageEventParams(channel, name, message, extra));
		if (added) {
			prefixLength = 0;
			addedPrefix = false;
			ChatLine.currentColor = ARGB(255, 255, 255, 255);
		} else {
			SetNextPrefix("", 0, "", 0, 0, false);
		}
	}
	
	void UpdatePosition() {
		vector pos = RLPositionManager.Get().GetPosition("Chat");
		int index = RLPositionManager.Get().GetIndex("Chat");
		RLWidgetUtils.SetWidgetAlignmentIndex(rootChatWidget, index);
		RLWidgetUtils.SetWidgetPositionIndex(rootChatWidget, pos, index);
	}
	
	void UpdateChatVisibility() {
		IngameHud hud = IngameHud.Cast(GetGame().GetMission().GetHud());
		if (hud && rootChatWidget) {
			bool visible = hud.IsHudVisible() && RLUtils.IsClientPlayerAlive();
			rootChatWidget.Show(visible);
		}
	}
	
	override void Add(ChatMessageEventParams params)
	{
		added = false;
		string message = params.param3;
		if (message.Length() == 0)
			return;
		if (message[0] == "+" || message[0] == "!")
			return;
		if (message[0] == "2") {
			params.param3 = message.Substring(1, message.Length() - 1);
		}
		int channel =  params.param1;
		RLLogger.Debug("Received Chat Message in Channel " + channel, "AdvancedGroups");
		RLLogger.Debug("CCSystem=" + CCSystem + " CCBattlEye=" + CCBattlEye + " CCAdmin=" + CCAdmin + " CCDirect=" + CCDirect + " CCMegaphone=" + CCMegaphone + " CCTransmitter=" + CCTransmitter + " CCPublicAddressSystem=" + CCPublicAddressSystem, "AdvancedGroups");
		
		ChatLine.battleyeColor = MissionGameplay.battleyeChatColor.GetColorARGB();
		int count = ("" + message).Replace(":", "");
		if ((channel & CCBattlEye) && (message.Length() >= 10) && (count >= 4) && (message[0] == "R") && (message[1] == "G") && (message[2] == "B")) {
			int first = message.IndexOf(":") + 1;
			int second = message.IndexOfFrom(first, ":") + 1;
			int third = message.IndexOfFrom(second, ":") + 1;
			int fourth = message.IndexOfFrom(third, ":") + 1;
			//RLLogger.Debug("Message: " + message + "  " + first + " " + second + " " + third + " " + fourth, "AdvancedGroups");
			int r = message.Substring(first, second - first - 1).ToInt();
			int g = message.Substring(second, third - second - 1).ToInt();
			int b = message.Substring(third, fourth - third - 1).ToInt();
			//RLLogger.Debug("" + r + " " + g + " " + b + " ARGB:" + ARGB(255,r,g,b), "AdvancedGroups");
			ChatLine.battleyeColor = ARGB(255,r,g,b);
			message = message.Substring(fourth, message.Length() - fourth);
			params.param3 = message;
			//RLLogger.Debug("Color: " + ChatLine.battleyeColor + " Message: " + params.param3, "AdvancedGroups");
		}
		
		RLTextLengthCalculator calc = RLTextLengthCalculator.Get();
		
		int size = RLMarkerVisibilityManager.Get().GetChatSize();
		float name_lenght = calc.GetTextLength(params.param2, size);
		float text_lenght = calc.GetTextLength(params.param3, size);
		float total_lenght = text_lenght + name_lenght + prefixLength;

		if( channel & CCSystem || channel & CCBattlEye) //TODO separate battleye bellow
 		{
			if( g_Game.GetProfileOption( EDayZProfilesOptions.GAME_MESSAGES ) )
				return;
 		}
		//TODO add battleye filter to options
		/*else if( channel & CCBattlEye ) 
		{
			if( g_Game.GetProfileOption( EDayZProfilesOptions.BATTLEYE_MESSAGES ) )
				return;
		}*/
		else if( channel & CCAdmin )
		{
			if( g_Game.GetProfileOption( EDayZProfilesOptions.ADMIN_MESSAGES ) )
				return;
		}
		else if(channel == 0 || channel & CCDirect || channel & CCMegaphone || channel & CCTransmitter || channel & CCPublicAddressSystem ) 
		{
			if( g_Game.GetProfileOption( EDayZProfilesOptions.PLAYER_MESSAGES ) )
				return;
			MissionGameplay mission = MissionGameplay.Cast(GetGame().GetMission());
			if (RLMarkerVisibilityManager.Get().IsChannelHidden(mission.GetDirectChannelName()))
				return;
		}
		else if( channel == 4096 ) 
		{
			if( g_Game.GetProfileOption( EDayZProfilesOptions.PLAYER_MESSAGES ) )
				return;
		}
		bool firstLine = true;
		float max_length = 0.4;
		if (total_lenght > max_length)
		{
			string preMessage = "";
			if (addedPrefix) {
				preMessage = nextPrefix + " " + nextGroupPrefix + " ";
			}
			preMessage = preMessage + params.param2;
			float preLength = calc.GetTextLength(preMessage, size);
			TStringArray parts = new TStringArray();
			params.param3.Split(" ", parts);
			int i = 0;
			
			ChatMessageEventParams tmp = new ChatMessageEventParams(params.param1, params.param2, "", params.param4);
			
			while (i < parts.Count())
			{
				string mess = "";
				while (calc.GetTextLength(mess + " " + parts[i], size) + preLength < max_length && i < parts.Count()) {
					mess = mess + " " + parts[i];
					i++;
				}
				if (mess.Length() <= 0) {
					string part = parts[i];
					int l = 1;
					for (int a = 0; a < part.Length(); a++) {
						if (calc.GetTextLength(part.Substring(0, a + 1), size) + preLength >= max_length) {
							l = a;
							break;
						}
					}
					mess = part.Substring(0, l);
					parts[i] = part.Substring(l, part.Length() - l);
				}
				tmp.param3 = mess;
				preLength = 0;
				
				if (!addedPrefix)
					SetNextPrefix("", 0, "", ARGB(255, 255, 255, 255), 0, false);
				else if (!firstLine)
					SetNextPrefix("", 0, "", ChatLine.currentColor, 0, false);
				firstLine = false;
				AddInternal(tmp);
				added = true;
				
				tmp.param2 = "";
			}
		}
		else
		{
			if (!addedPrefix) {
				SetNextPrefix("", 0, "", ARGB(255, 255, 255, 255), 0, false);
			}
			AddInternal(params);
			added = true;
		}
		addedPrefix = false;
	}
	
	void ResetTextSizes() {
		int size = RLMarkerVisibilityManager.Get().GetChatSize();
		foreach (ChatLine line : m_Lines) {
			line.m_NameTag.SetTextExactSize(size);
			line.m_NameWidget.SetTextExactSize(size);
			line.m_TextWidget.SetTextExactSize(size);
			line.m_GroupTag.SetTextExactSize(size);
		}
	}
	
	void SetNextPrefix(string prefix, int prefixColor, string groupPrefix, int channelColor, int groupPrefixColor, bool changeGroupTagColor) {
		ChatLine line = m_Lines.Get((m_LastLine + 1) % m_Lines.Count());
		int size = RLMarkerVisibilityManager.Get().GetChatSize();
		line.m_NameTag.SetTextExactSize(size);
		line.m_NameTag.SetColor(prefixColor);
		line.m_NameTag.SetText(prefix);
		line.m_GroupTag.SetText(groupPrefix);
		if (changeGroupTagColor)
			line.m_GroupTag.SetColor(groupPrefixColor);
		line.changeGroupTagColor = changeGroupTagColor;
		ChatLine.currentColor = channelColor;
		
		nextPrefix = prefix;
		nextGroupPrefix = groupPrefix;
	}
	
}
#endif
