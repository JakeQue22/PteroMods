modded class IngameHud {

	override bool IsHudVisible() {
		return ( !m_HudHidePlayer && !m_HudHideUI && m_HudState );
	}

}