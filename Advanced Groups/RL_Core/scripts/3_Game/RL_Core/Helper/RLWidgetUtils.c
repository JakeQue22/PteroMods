class RLWidgetUtils {

	const static int V_LEFT = 0x00;
	const static int V_CENTER = 0x140;
	const static int V_RIGHT = 0x100;
	const static int V_BITS = 0x1C0;
	const static int H_TOP = 0x00;
	const static int H_CENTER = 0xA00;
	const static int H_BOTTOM = 0x800;
	const static int H_BITS = 0xE00;
	const static int CLEAR_FLAGS = 0xFC0;
	
	static int screenWidth;
	static int screenHeight;
	static float heightScale;
	static float widthScale;
	
	static ref ScriptInvoker Event_OnScreenSizeChanged = new ScriptInvoker();
	
	static void UpdateScreenDimensions() {
		GetScreenSize(screenWidth, screenHeight);
		heightScale = ((float) screenHeight) / 1080.0;
		widthScale = ((float) screenWidth) / 1920.0;
		Event_OnScreenSizeChanged.Invoke();
	}
	
	static int WidthToPixel01(float value) {
		return (int) (value * screenWidth);
	}
	
	static int HeightToPixel01(float value) {
		return (int) (value * screenHeight);
	}
	
	static int WidthToPixel(float hdPixel) {
		return (int) (hdPixel * widthScale);
	}
	
	static int HeightToPixel(float hdPixel) {
		return (int) (hdPixel * heightScale);
	}
	
	static void SetWidgetAlignment(Widget w, int align = 0) {
		if (!w)
			return;
		w.ClearFlags(CLEAR_FLAGS);
		w.SetFlags(align & CLEAR_FLAGS);
		w.Update();
	}
	
	static void SetWidgetAlignmentIndex(Widget w, int index = 0) {
		int value = FromIndex(index);
		SetWidgetAlignment(w, value);
	}
	
	static void SetWidgetPosition(Widget w, vector pos, int align = 0) {
		if (!w)
			return;
		float x = pos[0];
		float y = pos[1];
		if (!IsLeft(align))
			x = 1.0 - x;
		if (!IsTop(align))
			y = 1.0 - y;
		w.SetPos(x,y);
		w.Update();
	}
	
	static void SetWidgetPositionIndex(Widget w, vector pos, int index = 0) {
		int value = FromIndex(index);
		SetWidgetPosition(w, pos, value);
	}
	
	static bool IsLeft(int align) {
		return (align & V_BITS) == V_LEFT || align == 0;
	}
	
	static bool IsRight(int align) {
		return (align & V_BITS) == V_RIGHT;
	}
	
	static bool IsVCenter(int align) {
		return (align & V_BITS) == V_CENTER;
	}
	
	static bool IsTop(int align) {
		return (align & H_BITS) == H_TOP || align == 0;
	}
	
	static bool IsBottom(int align) {
		return (align & H_BITS) == H_BOTTOM;
	}
	
	static bool IsHCenter(int align) {
		return (align & H_BITS) == H_CENTER;
	}
	
	static int FromIndex(int index) {
		int h = index / 3;
		int v = index % 3;
		int result = 0;
		if (v == 0) {
			result |= V_LEFT;
		} else if (v == 1) {
			result |= V_CENTER;
		} else {
			result |= V_RIGHT;
		}
		if (h == 0) {
			result |= H_TOP;
		} else if (h == 1) {
			result |= H_CENTER;
		} else {
			result |= H_BOTTOM;
		}
		return result;
	}
	
	static int ToIndex(int value) {
		int result = 0;
		if (IsLeft(value)) {
			result += 0; // Not needed, but for the completeness
		} else if (IsVCenter(value)) {
			result += 1;
		} else {
			result += 2;
		}
		if (IsTop(value)) {
			result += 0;
		} else if (IsHCenter(value)) {
			result += 3;
		} else {
			result += 6;
		}
		return result;
	}
	
	static void TestIndexConversion() {
		for (int i = 0; i < 9; i++) {
			int value = FromIndex(i) + 8192;
			int index = ToIndex(value);
			if (index != i) {
				RLLogger.Debug("Failed test for Value: " + i + " Got: " + value + " and Index: " + index, "AdvancedGroups");
			} else {
				RLLogger.Debug("Test Passed for Value: " + i + " Got: " + value + " and Index: " + index, "AdvancedGroups");
			}
		}
	}

}