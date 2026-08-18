class RLInfoPanelPage : RLGroupPage {
	
	Widget mainText;
	int currentHeight = 5;
	int defaultTextSize = 25;
	int defaultLineSpacing = 2;

	override bool InitPage(RLGroupUI parentUI) {
		return super.InitPage(parentUI, 4, 0, "#rl_page_info", true);
	}
	
	override void InitMainWidget() {
		mainText = rootWidget.FindAnyWidget("mainText");
		for (int i = 0; i < 75; i++) {
			CreateTextBox("Test Number " + i);
		}
		CreateTextBox("This text is very long and should be split into multiple lines, if it actually is long enough for DayZ to not be able to render it in one line");
		CreateTextBox("This text is very long and should be split into multiple lines, if it actually is long enough for DayZ to not be able to render it in one line. This line will be even longer and hopefully long enough to be longer, than allowed");
	}
	
	void CreateTextBox(string text) {
		
	}

}
/*
class TextPart {

	string text;
	bool underlined = false, bold = false, crossed = false;
	int align = 0; // 0 = left | 1 = center | 2 = right
	int textSize = 25;
	
	TextWidget w;
	TextPart child = null;
	
	void CreatePart(string text_, int size_ = 25, int align_ = 0, bool underlined_ = false, bool bold_ = false, bool crossed_ = false) {
		this.text = text_;
		this.textSize = size_;
		this.align = align_;
		this.underlined = underlined_;
		this.bold = bold_;
		this.crossed = crossed_;
	}
	
	void CreatePart(TextPart parent) {
		this.text = parent.text;
		this.textSize = parent.textSize;
		this.align = parent.align;
		this.underlined = parent.underlined;
		this.bold = parent.bold;
		this.crossed = parent.crossed;
		
	}
	
	void CreateTextBox(int startindex = 0) {
		TStringArray parts = CustomSplit(text);
		
		RLTextLengthCalculator calc = RLTextLengthCalculator.Get();
		string content = parts[startindex];
		
		w = TextWidget.Cast(GetGame().GetWorkspace().CreateWidgets("RayLab_Groups/gui/layouts/mapmenu/infopanel/text.layout"));
		mainText.AddChild(w);
		
		for (int i = startindex + 1; i < parts.Count(); i++) {
			if (calc.GetTextLength(content + " " + parts[i], defaultTextSize) > 0.99) {
				child = new TextPart();
				child.CreatePart(this);
				child.CreateTextBox(i);
				break;
			}
			content = content + " " + parts[i];
		}
		w.SetText(content);
		w.SetTextExactSize(defaultTextSize);
		w.SetItalic(true);
	}
	
	TStringArray CustomSplit(string text) {
		TStringArray arr = new TStringArray();
		text.Split(" ", arr);
		for (int i = 0; i < arr.Count(); i++) {
			string part = arr[i];
			int start = 0;
			int open = part.IndexOfFrom(start, "[");
			int close = part.IndexOfFrom(start, "]");
			if (open >= 0 && close >= 0) {
				string first = part.Substring(0, open);
				string second = part.Substring(open, close - open);
				string third = part.Substring(close, part.Length() - close);
				arr.RemoveOrdered(i);
				int i2 = i;
				if (first.Length() > 0) {
					arr.InsertAt(i2++, first);
				}
				if (second.Length() > 0) {
					arr.InsertAt(i2++, second);
				}
				if (third.Length() > 0) {
					arr.InsertAt(i2++, third);
				}
			}
		}
		return arr;
	}
	
	bool InterpretPart(string part) {
		
	}

}