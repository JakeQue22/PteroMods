class RLAutoComplete : ScriptedWidgetEventHandler {

	static ref RLAutoComplete g_RLAutoComplete;
	
	static RLAutoComplete Get() {
		if (!g_RLAutoComplete)
			g_RLAutoComplete = new RLAutoComplete();
		return g_RLAutoComplete;
	}
	
	ref map<EditBoxWidget, ref Param2<EditBoxWidget, ref TStringArray>> widgets = new map<EditBoxWidget, ref Param2<EditBoxWidget, ref TStringArray>>();
	ref array<ref TStringArray> arrays = new array<ref TStringArray>();
	
	void AddHandler(EditBoxWidget editBox, TStringArray data, bool replaceHandler = false) {
		RemoveNull();
		EditBoxWidget txt = EditBoxWidget.Cast(GetGame().GetWorkspace().CreateWidgets("RayLab_Core/gui/layouts/autocompleteoverlay.layout", editBox));
		if (replaceHandler)
			editBox.SetHandler(this);
		
		TStringArray copy = new TStringArray();
		copy.InsertArray(data);
		copy.Sort();
		arrays.Insert(copy);
		widgets.Insert(editBox, new Param2<EditBoxWidget, ref TStringArray>(txt, copy));
		CopyMetadata(editBox, txt);
		UpdateText(editBox, txt, copy);
	}
	
	override bool OnChange(Widget w, int x, int y, bool finished) {
		if (super.OnChange(w, x, y, finished))
			return true;
		if (w) {
			foreach (EditBoxWidget editBox, Param2<EditBoxWidget, ref TStringArray> data : widgets) {
				if (w == editBox) {
					UpdateText(editBox, data.param1, data.param2);
				}
			}
		}
		return false;
	}
	
	void KeyDown(int key) {
		Widget w = GetFocus();
		if (w && key == KeyCode.KC_TAB) {
			foreach (EditBoxWidget editBox, Param2<EditBoxWidget, ref TStringArray> data : widgets) {
				if (w == editBox) {
					CompleteText(editBox, data.param1, data.param2);
				}
			}
		}
	}
	
	void UpdateText(EditBoxWidget editBox, EditBoxWidget txt, TStringArray list) {
		string enteredText = editBox.GetText();
		string best = RLStringArrayTools.FindBestMatchStarting(list, enteredText, false);
		if (best.Length() > enteredText.Length()) {
			txt.SetText(enteredText + best.Substring(enteredText.Length(), best.Length() - enteredText.Length()));
			Print("Best: " + best + " for " + enteredText);
		} else {
			txt.SetText("");
		}
	}
	
	void CompleteText(EditBoxWidget editBox, EditBoxWidget txt, TStringArray list) {
		string enteredText = editBox.GetText();
		string best = RLStringArrayTools.FindBestMatchStarting(list, enteredText, false);
		if (best != "")
			editBox.SetText(best);
		UpdateText(editBox, txt, list);
	}
	
	private static void CopyMetadata(EditBoxWidget editBox, EditBoxWidget txt) {
		txt.SetColor(editBox.GetColor());
		txt.SetTextColor(Darker(editBox.GetColor()));
		txt.SetTextOutline(editBox.GetTextOutlineSize(), editBox.GetTextOutlineColor());
		txt.SetTextShadow(editBox.GetTextShadowSize(), editBox.GetTextShadowColor(), editBox.GetTextShadowOpacity(), editBox.GetTextShadowOffsetX(), editBox.GetTextShadowOffsetY());
		txt.SetTextItalic(editBox.GetTextItalic());
		txt.SetTextBold(editBox.GetTextBold());
		txt.SetAlpha(editBox.GetAlpha() * 0.7);
	}
	
	private static int Brighter(int color) {
		int a,r,g,b;
		RLConverter.ARGBToComponents(color, a, r, g, b);
		return ARGB(a, Math.Clamp(r * 1.3, 0, 255), Math.Clamp(g * 1.3, 0, 255), Math.Clamp(b * 1.3, 0, 255));
	}
	
	private static int Darker(int color) {
		int a,r,g,b;
		RLConverter.ARGBToComponents(color, a, r, g, b);
		return ARGB(a, Math.Clamp(r * 0.7, 0, 255), Math.Clamp(g * 0.7, 0, 255), Math.Clamp(b * 0.7, 0, 255));
	}
	
	private void RemoveNull() {
		for (int i = 0; i < widgets.Count(); ++i) {
			if (!widgets.GetKey(i)) {
				widgets.RemoveElement(i);
				--i;
			}
		}
	}
	
}