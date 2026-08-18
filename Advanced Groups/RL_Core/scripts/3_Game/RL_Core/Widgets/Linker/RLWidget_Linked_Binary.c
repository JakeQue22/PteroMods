
class RLWidget_Linked_Binary : RLWidget_Linker {

	CheckBoxWidget toggleWidget;
	Widget listWidget;
	ref array<int> values = new array<int>();
	
	void RLWidget_Linked_Binary(string varPath_, Widget list, CheckBoxWidget toggle, Class parent_) {
		SetVarPath(varPath_);
		this.mainParent = parent_;
		this.listWidget = list;
		this.reloadTrigger = list;
		this.toggleWidget = toggle;
	}
	
	RLWidget_Linked_Binary AddKeyVal(string key, int value) {
		XComboBoxWidget combo = XComboBoxWidget.Cast(listWidget);
		TextListboxWidget txt = TextListboxWidget.Cast(listWidget);
		if (combo) {
			combo.AddItem(key);
			values.Insert(value);
		} else if (txt) {
			txt.AddItem(key, null, 0);
			values.Insert(value);
		}
		return this;
	}
	
	RLWidget_Linked_Binary AddEnum(typename enumName) {
		int varCount = enumName.GetVariableCount();
		for (int i = 0; i < varCount; i++) {
			int value = 0;
			if (enumName.GetVariableType(i) == int && enumName.GetVariableValue(null, i, value))
			{
				AddKeyVal(enumName.GetVariableName(i), value);
			}
		}
		return this;
	}
	
	override bool Load() {
		int value = GetConfigVarAsInt();
		int selectedValue = values.Get(GetCurrentSelectedIndex(listWidget));
		toggleWidget.SetChecked((value & selectedValue) == selectedValue);
		return true;
	}
	
	override bool OnVarChanged(Widget w) {
		if (w != toggleWidget)
			return false;
		int value = GetConfigVarAsInt();
		int selectedValue = values.Get(GetCurrentSelectedIndex(listWidget));
		if (toggleWidget.IsChecked()) {
			value |= selectedValue;
		} else {
			value = ~((~value) | selectedValue);
		}
		return true;
	}
	
}