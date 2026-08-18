
class RLWidget_Linker {
	
	ref RLWidget_ColorSetter colorSetter;
	protected string varPath; // can be a.b.c or only a
	protected ref TStringArray varParts = new TStringArray();
	protected string varPartLast = "";
	protected Class mainParent;
	protected Widget reloadTrigger;
	protected string onChangeTrigger = "";
	private string setterFunction = "";
	
	private string string_;
	private int int_;
	private float float_;
	private bool bool_;
	private Class class_;
	
	bool IsVarPath(string other) {
		return varPath == other;
	}
	
	protected void SetVarPath(string varPath_) {
		this.varPath = varPath_;
		this.varPath.Split(".", varParts);
		Print(varParts);
		Print(varPath);
		this.varPartLast = varParts.Get(varParts.Count() - 1);
	}
	
	protected Class GetConfigVarParent() {
		Class found = mainParent;
		int len = varParts.Count() - 1;
		for (int i = 0; i < len; i++) {
			string varName = varParts.Get(i);
			Class temp = null;
			if (varName.IndexOf("()") == varName.Length() - 2) {
				varName = varName.Substring(0, varName.Length() - 2);
				GetGame().GameScript.CallFunction(found, varName, temp, null);
			} else {
				EnScript.GetClassVar(found, varName, 0, temp);
			}
			found = temp;
			if (temp == null)
				return null;
		}
		return found;
	}
	
	void TriggerColorChange() {
		if (colorSetter)
			colorSetter.SetColor();
	}
	
	protected bool GetConfigVar(out typename type_) {
		Class parent = GetConfigVarParent();
		if (parent == null)
			return false;
		typename parentType = parent.Type();
		if (varPartLast.IndexOf("()") == varPartLast.Length() - 2 && varPartLast.Length() > 2) {
			string funcName = varPartLast.Substring(0, varPartLast.Length() - 2);
			Print("Config Var is a function call. Calling function " + funcName);
			string value;
			GetGame().GameScript.CallFunction(parent, funcName, value, null);
			Print("String Function call returned value " + value);
			type_ = string;
			string_ = value;
			return true;
		}
		int varIndex = GetVariableIndex(parentType, varPartLast);
		if (varIndex == -1) {
			return false;
		}
		type_ = parentType.GetVariableType(varIndex);
		if (type_ == int) {
			EnScript.GetClassVar(parent, varPartLast, 0, int_);
			return true;
		} else if (type_ == string) {
			EnScript.GetClassVar(parent, varPartLast, 0, string_);
			return true;
		} else if (type_ == float) {
			EnScript.GetClassVar(parent, varPartLast, 0, float_);
			return true;
		} else if (type_ == bool) {
			EnScript.GetClassVar(parent, varPartLast, 0, bool_);
			return true;
		} else {
			EnScript.GetClassVar(parent, varPartLast, 0, class_);
			type_ = Class;
			return true;
		}
		return false;
	}
	
	protected Class GetConfigVarAsClass() {
		typename type;
		if (!GetConfigVar(type))
			return null;
		if (type.IsInherited(Class))
			return class_;
		return null;
	}
	
	protected string GetConfigVarAsString() {
		typename type;
		if (!GetConfigVar(type))
			return "";
		if (type == string)
			return string_;
		if (type == int)
			return int_.ToString();
		if (type == float)
			return float_.ToString();
		if (type == bool)
			return bool_.ToString();
		return class_.ToString();
	}
	
	protected bool GetConfigVarAsBool() {
		typename type;
		if (!GetConfigVar(type))
			return false;
		if (type == bool)
			return bool_;
		if (type == int)
			return int_;
		if (type == float)
			return float_;
		if (type == string)
			return string_ == "true" || string_ == "1";
		return false;
	}
	
	protected int GetConfigVarAsInt() {
		typename type;
		if (!GetConfigVar(type))
			return false;
		if (type == bool)
			return bool_;
		if (type == int)
			return int_;
		if (type == float)
			return float_;
		if (type == string)
			return string_.ToInt();
		return 0;
	}
	
	protected float GetConfigVarAsFloat() {
		typename type;
		if (!GetConfigVar(type))
			return false;
		if (type == bool)
			return bool_;
		if (type == int)
			return int_;
		if (type == float)
			return float_;
		if (type == string)
			return string_.ToFloat();
		return 0;
	}
	
	RLWidget_Linker SetSetter(string function) {
		setterFunction = function;
		if (setterFunction.IndexOf("()") == setterFunction.Length() - 2) {
			setterFunction = setterFunction.Substring(0, setterFunction.Length() - 2);
		}
		return this;
	}
	
	protected void LoadCheckboxValue(CheckBoxWidget chckbx, bool invertBool) {
		bool selected = chckbx.IsChecked();
		if (invertBool)
			selected = !selected;
		SetBoolValue(selected);
		//Print("Variable Changed: " + chckbx + " Selected: " + selected + " Set: " + GetConfigVarAsBool());
	}
	
	protected bool LoadEditBoxValue(EditBoxWidget editbx, int extraColumn, Widget extraOutput) {
		string text = editbx.GetText();
		typename varType;
		if (!GetConfigVar(varType))
			return false;
		if (varType == int) {
			int intVal = text.ToInt();
			EnScript.SetClassVar(GetConfigVarParent(), varPartLast, 0, intVal);
		} else if (varType == float) {
			float floatVal = text.ToFloat();
			EnScript.SetClassVar(GetConfigVarParent(), varPartLast, 0, floatVal);
		} else if (varType == string) {
			SetStringValue(text);
		} else {
			Error("Could not find matching Variable for " + varPath + " and text " + text);
			return false;
		}
		LoadVar(extraOutput, false, extraColumn, null);
		//Print("Variable Changed: " + editbx + " Selected: " + text + " Set: " + GetConfigVarAsString());
		return true;
	}
	
	protected bool LoadSliderValue(SliderWidget slider) {
		float value = slider.GetCurrent();
		typename varType;
		if (!GetConfigVar(varType))
			return false;
		if (varType == int) {
			SetIntValue((int) value);
		} else {
			SetFloatValue(value);
		}
		//Print("Variable Changed: " + slider + " Selected: " + value + " Set: " + GetConfigVarAsFloat());
		return true;
	}
	
	protected void SetSliderValue(SliderWidget slider) {
		slider.SetCurrent(GetConfigVarAsFloat());
		//Print("Variable Read: " + slider + " -> " + GetConfigVarAsFloat());
	}
	
	protected void SetCheckboxValue(CheckBoxWidget chckbx, bool invertBool) {
		bool checked = GetConfigVarAsBool();
		if (invertBool)
			checked = !checked;
		chckbx.SetChecked(checked);
		//Print("Variable Read: " + chckbx + " -> " + GetConfigVarAsBool());
	}
	
	protected void SetEditBoxValue(EditBoxWidget editbx) {
		string value = GetConfigVarAsString();
		editbx.SetText(value);
		//Print("Variable Read: " + editbx + " -> " + value);
	}
	
	protected void SetComboBoxValue(XComboBoxWidget combo) {
		int selected = combo.GetCurrentItem();
		if (selected < 0 || selected >= combo.GetNumItems())
			return;
		combo.SetItem(selected, GetConfigVarAsString());
		//Print("Variable Read: " + combo + " (" + selected + ") -> " + GetConfigVarAsString());
	}
	
	protected void SetTextListboxValue(TextListboxWidget txtList, int extraColumn) {
		int selected = txtList.GetSelectedRow();
		if (selected < 0 || selected >= txtList.GetNumItems())
			return;
		Class userData;
		txtList.GetItemData(selected, extraColumn, userData);
		txtList.SetItem(selected, GetConfigVarAsString(), userData, extraColumn);
		//Print("Variable Read: " + txtList + " (" + selected + ") -> " + GetConfigVarAsString());
	}
	
	protected void SetTextValue(TextWidget text) {
		text.SetText(GetConfigVarAsString());
	}
	
	protected bool LoadVar(Widget w, bool invertBool, int extraColumn, Widget extraWidget) {
		if (!w)
			return false;
		CheckBoxWidget chckbx = CheckBoxWidget.Cast(w);
		if (chckbx) {
			SetCheckboxValue(chckbx, invertBool);
			return true;
		}
		EditBoxWidget editbx = EditBoxWidget.Cast(w);
		if (editbx) {
			SetEditBoxValue(editbx);
			return true;
		}
		XComboBoxWidget combo = XComboBoxWidget.Cast(w);
		if (combo) {
			SetComboBoxValue(combo);
			return true;
		}
		TextListboxWidget txtList = TextListboxWidget.Cast(w);
		if (txtList) {
			SetTextListboxValue(txtList, extraColumn);
			return true;
		}
		SliderWidget slider = SliderWidget.Cast(w);
		if (slider) {
			SetSliderValue(slider);
			return true;
		}
		TextWidget text = TextWidget.Cast(w);
		if (text) {
			SetTextValue(text);
			return true;
		}
		return false;
	}
	
	protected void SetBoolValue(bool val) {
		if (setterFunction != "") {
			GetGame().GameScript.CallFunctionParams(GetConfigVarParent(), setterFunction, null, new Param1<bool>(val));
		} else {
			int index = varPartLast.IndexOf("()");
			if (index != -1 && index == varPartLast.Length() - 2) {
				string funcName = varPartLast.Substring(0, varPartLast.Length() - 2);
				GetGame().GameScript.CallFunction(GetConfigVarParent(), funcName, null, val);
			} else {
				EnScript.SetClassVar(GetConfigVarParent(), varPartLast, 0, val);
			}
		}
	}
	
	protected void SetIntValue(int val) {
		if (setterFunction != "") {
			GetGame().GameScript.CallFunctionParams(GetConfigVarParent(), setterFunction, null, new Param1<int>(val));
		} else {
			int index = varPartLast.IndexOf("()");
			if (index != -1 && index == varPartLast.Length() - 2) {
				string funcName = varPartLast.Substring(0, varPartLast.Length() - 2);
				GetGame().GameScript.CallFunctionParams(GetConfigVarParent(), funcName, null, new Param1<int>(val));
			} else {
				EnScript.SetClassVar(GetConfigVarParent(), varPartLast, 0, val);
			}
		}
	}
	
	protected void SetFloatValue(float val) {
		if (setterFunction != "") {
			GetGame().GameScript.CallFunctionParams(GetConfigVarParent(), setterFunction, null, new Param1<float>(val));
		} else {
			int index = varPartLast.IndexOf("()");
			if (index != -1 && index == varPartLast.Length() - 2) {
				string funcName = varPartLast.Substring(0, varPartLast.Length() - 2);
				GetGame().GameScript.CallFunctionParams(GetConfigVarParent(), funcName, null, new Param1<float>(val));
			} else {
				EnScript.SetClassVar(GetConfigVarParent(), varPartLast, 0, val);
			}
		}
	}
	
	protected void SetStringValue(string val) {
		if (setterFunction != "") {
			GetGame().GameScript.CallFunctionParams(GetConfigVarParent(), setterFunction, null, new Param1<string>(val));
		} else {
			int index = varPartLast.IndexOf("()");
			if (index != -1 && index == varPartLast.Length() - 2) {
				string funcName = varPartLast.Substring(0, varPartLast.Length() - 2);
				GetGame().GameScript.CallFunctionParams(GetConfigVarParent(), funcName, null, new Param1<string>(val));
			} else {
				EnScript.SetClassVar(GetConfigVarParent(), varPartLast, 0, val);
			}
		}
	}
	
	protected int GetVariableIndex(typename parent, string name) {
		for (int i = 0; i < parent.GetVariableCount(); i++) {
			if (parent.GetVariableName(i) == name) {
				//Print("Type of " + name + " at index " + i + " is " + parent.GetVariableType(i));
				return i;
			}
		}
		return -1;
	}
	
	bool CheckReloadTrigger(Widget w) {
		return (w == reloadTrigger && w != null);
	}
	
	void TriggerOnChange(Widget w) {
		if (w)
			RLAutoComplete.Get().OnChange(w, -1, -1, false);
		//Print("Trigger OnChange: " + onChangeTrigger);
		if (onChangeTrigger == "")
			return;
		TStringArray triggerParts = new TStringArray();
		onChangeTrigger.Split(".", triggerParts);
		Class found = mainParent;
		int len = triggerParts.Count();
		for (int i = 0; i < len; i++) {
			string varName = triggerParts.Get(i);
			Class temp = null;
			if (varName.IndexOf("()") == varName.Length() - 2) {
				varName = varName.Substring(0, varName.Length() - 2);
				GetGame().GameScript.CallFunction(found, varName, temp, null);
			} else {
				EnScript.GetClassVar(found, varName, 0, temp);
			}
			found = temp;
			if (temp == null)
				return;
		}
	}
	
	RLWidget_Linker SetReloadTrigger(Widget reloadTrigger_) {
		this.reloadTrigger = reloadTrigger_;
		return this;
	}
	
	RLWidget_Linker SetChangeTrigger(string calledFunction) {
		onChangeTrigger = calledFunction;
		if (onChangeTrigger.Length() < 2 || onChangeTrigger.Substring(onChangeTrigger.Length() - 2, 2) != "()") {
			onChangeTrigger = onChangeTrigger + "()";
		}
		return this;
	}
	
	protected static bool SelectEntry(Widget list, int num = -2) {
		XComboBoxWidget combo = XComboBoxWidget.Cast(list);
		if (combo) {
			if (num == -2)
				combo.SetCurrentItem(combo.GetNumItems() - 1);
			else
				combo.SetCurrentItem(num);
			return true;
		}
		TextListboxWidget listbox = TextListboxWidget.Cast(list);
		if (listbox) {
			if (num == -2) {
				listbox.SelectRow(listbox.GetNumItems() - 1);
				listbox.EnsureVisible(listbox.GetNumItems() - 1);
			} else {
				listbox.SelectRow(num);
				listbox.EnsureVisible(num);
			}
			return false;
		}
		return false;
	}
	
	protected static int GetCurrentSelectedIndex(Widget list) {
		XComboBoxWidget combo = XComboBoxWidget.Cast(list);
		if (combo) {
			return combo.GetCurrentItem();
		}
		TextListboxWidget listbox = TextListboxWidget.Cast(list);
		if (listbox) {
			return listbox.GetSelectedRow();
		}
		return -1;
	}
	
	bool OnVarChanged(Widget w);
	bool Load();
	
}