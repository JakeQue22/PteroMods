class RLTextLengthCalculator {

	private TextWidget widget;
	
	static ref RLTextLengthCalculator g_RLTextLengthCalculator;
	
	static RLTextLengthCalculator Get() {
		if (!g_RLTextLengthCalculator)
			g_RLTextLengthCalculator = new RLTextLengthCalculator();
		return g_RLTextLengthCalculator;
	}
	
	static void Delete() {
		if (g_RLTextLengthCalculator)
			delete g_RLTextLengthCalculator;
	}
	
	void RLTextLengthCalculator() {
		widget = TextWidget.Cast(GetGame().GetWorkspace().CreateWidgets("RayLab_Groups/gui/layouts/textLengthTester.layout", null));
	}
	
	void ~RLTextLengthCalculator() {
		if (widget) {
			widget.Unlink();
		}
	}
	
	float GetTextLength(string text, int size) {
		widget.SetTextExactSize(size);
		widget.SetText(text);
		widget.Update();
		float width, height;
		widget.GetScreenSize(width, height);
		width /= RLWidgetUtils.screenWidth;
		return width;
	}
}