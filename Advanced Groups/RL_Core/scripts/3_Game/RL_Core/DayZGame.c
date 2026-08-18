modded class DayZGame {

	void DayZGame() {
		RLWidgetUtils.UpdateScreenDimensions();
	}

	override void OnEvent(EventType eventTypeId, Param params) {
		super.OnEvent(eventTypeId, params);
		
		switch (eventTypeId) {
		case WindowsResizeEventTypeID:
			RLWidgetUtils.UpdateScreenDimensions();
		}
	}
	
}