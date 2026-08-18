#ifndef RL_DISABLE_CHAT
modded class ChatLine {
	
	TextWidget m_NameTag;
	TextWidget m_GroupTag;
	
	static int currentColor;
	static int battleyeColor;
	bool changeGroupTagColor = false;
	static int currentLineMessageColor = ARGB(255,255,255,255);
	
	void ChatLine(Widget root_widget)
	{
		if (m_RootWidget)
			m_RootWidget.Unlink();
		m_RootWidget	= RLLayoutManager.Get().CreateLayout("ChatItem", "RayLab_Groups/gui/layouts/day_z_chat_item.layout", root_widget);
	
		m_NameTag		= TextWidget.Cast( m_RootWidget.FindAnyWidget( "ChatItemSenderTagWidget" ) );
		m_GroupTag		= TextWidget.Cast( m_RootWidget.FindAnyWidget( "ChatItemSenderGroupTagWidget" ) );
		m_NameWidget	= TextWidget.Cast( m_RootWidget.FindAnyWidget( "ChatItemSenderWidget" ) );
		m_TextWidget	= TextWidget.Cast( m_RootWidget.FindAnyWidget( "ChatItemTextWidget" ) );
	}

    override void Set(ChatMessageEventParams params) {
        super.Set(params);
        int channel = params.param1;
		//RLLogger.Debug("Set Chat Line: " + ChatLine.battleyeColor + " " + params.param3, "AdvancedGroups");
		if( channel & CCSystem ) {
			if(params.param2 != "")
			{
				m_NameWidget.SetText("");
			} 
			SetColour(GAME_TEXT_COLOUR);
 		}
		if (channel & CCAdmin) {
			if(params.param2 != "")
			{
				m_NameWidget.SetText("");
			} 
			SetColour(ADMIN_TEXT_COLOUR);
		}

		if (channel & CCBattlEye) {
			if(params.param2 != "")
			{
				m_NameWidget.SetText("");
			} 
			SetColour(battleyeColor);
		}

        if (channel == 4096) {
			if (params.param2.Length() > 0)
				m_NameWidget.SetText(params.param2 + ": ");
			m_TextWidget.SetText(params.param3);
			SetColour(currentColor);
        }
		int size = RLMarkerVisibilityManager.Get().GetChatSize();
		m_NameWidget.SetTextExactSize(size);
		m_TextWidget.SetTextExactSize(size);
		m_TextWidget.SetColor(currentLineMessageColor);
		m_GroupTag.SetTextExactSize(size);
    }
	
	override void SetColour(int colour) {
		super.SetColour(colour);
		if (!changeGroupTagColor)
			m_GroupTag.SetColor(colour);
	}
}
#endif