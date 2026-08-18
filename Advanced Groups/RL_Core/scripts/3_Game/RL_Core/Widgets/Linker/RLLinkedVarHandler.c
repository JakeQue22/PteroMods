class RLLinkedVarHandler {
	
	private ref array<ref RLWidget_Linker> linkedObjs = new array<ref RLWidget_Linker>();
	private ref map<string, ref array<int>> searchIdices = new map<string, ref array<int>>();
	private Class parent;
	
	void RLLinkedVarHandler(Class parentClass) {
		this.parent = parentClass;
	}
	
	void LoadLinkedVars() {
		foreach (RLWidget_Linker linked : linkedObjs) {
			linked.Load();
		}
		foreach (RLWidget_Linker linked2 : linkedObjs) {
			linked2.TriggerColorChange();
		}
	}
	
	RLWidget_Linked_Var RegisterLinkedVar(string varPath, Widget linkedTo) {
		RLWidget_Linked_Var var = new RLWidget_Linked_Var(varPath, linkedTo, parent);
		linkedObjs.Insert(var);
		return var;
	}
	
	RLWidget_Linked_Binary RegisterLinkedBinaryVar(string varPath, Widget list, CheckBoxWidget change) {
		RLWidget_Linked_Binary var = new RLWidget_Linked_Binary(varPath, list, change, parent);
		linkedObjs.Insert(var);
		return var;
	}
	
	RLWidget_Linked_List RegisterLinkedList(string varPath, Widget listOutput, ButtonWidget addButton = null, ButtonWidget removeButton = null, EditBoxWidget inputField = null) {
		RLWidget_Linked_List list = new RLWidget_Linked_List(varPath, listOutput, addButton, removeButton, inputField, parent, this);
		linkedObjs.Insert(list);
		return list;
	}
	
	array<RLWidget_Linked_Var> RegisterLinkedColor(SliderWidget w_a_, SliderWidget w_r_, SliderWidget w_g_, SliderWidget w_b_, string colorCfgPath, Widget output = null) {
		array<RLWidget_Linked_Var> added = new array<RLWidget_Linked_Var>();
		RLWidget_Linked_Var var;
		if (w_a_) {
			added.Insert(RegisterLinkedVar(colorCfgPath + ".a", w_a_));
		}
		if (w_r_) {
			added.Insert(RegisterLinkedVar(colorCfgPath + ".r", w_r_));
		}
		if (w_g_) {
			added.Insert(RegisterLinkedVar(colorCfgPath + ".g", w_g_));
		}
		if (w_b_) {
			added.Insert(RegisterLinkedVar(colorCfgPath + ".b", w_b_));
		}
		if (output) {
			RLWidget_ColorSetter setter = new RLWidget_ColorSetter(w_a_, w_r_, w_g_, w_b_, output);
			foreach (RLWidget_Linked_Var var2 : added) {
				var2.colorSetter = setter;
			}
		}
		return added;
	}
	
	bool OnVarChange(Widget w) {
		bool ok = false;
		foreach (RLWidget_Linker linked : linkedObjs) {
			if (linked.CheckReloadTrigger(w)) {
				linked.Load();
				ok = true;
			} else if (linked.OnVarChanged(w)) {
				linked.TriggerColorChange();
				ok = true;
			}
		}
		RLAutoComplete.Get().OnChange(w, -1, -1, false);
		return ok;
	}
	
	bool OnKeyDown(Widget w, int x, int y, int key) {
		RLAutoComplete.Get().OnKeyDown(w, x, y, key);
		return false;
	}
	
	void TriggerLoad(string varPath) {
		foreach (RLWidget_Linker linked : linkedObjs) {
			if (linked.IsVarPath(varPath)) {
				linked.Load();
			}
		}
	}
	
	array<int> GetSearchIndices(Widget widget_) {
		if (!widget_)
			return null;
		string name = widget_.GetName();
		array<int> found;
		if (searchIdices.Find(name, found))
			return found;
		found = new array<int>();
		searchIdices.Insert(name, found);
		return found;
	}
	
	int SearchedIndexToListIndex(Widget widget_, int searchIndex) {
		array<int> list = GetSearchIndices(widget_);
		Print("List: " + list + " for Widget " + widget_ + " at index: " + searchIndex + " List Entries: " + list.Count() + " at: " + list[searchIndex]);
		if (list.Count() > searchIndex)
			return list[searchIndex];
		return -1;
	}
	
}

class RLWidget_ColorSetter {
	
	private Widget target_;
	private SliderWidget w_a, w_r, w_g, w_b;
	
	void RLWidget_ColorSetter(SliderWidget w_a_, SliderWidget w_r_, SliderWidget w_g_, SliderWidget w_b_, Widget target) {
		target_ = target;
		w_a = w_a_;
		w_r = w_r_;
		w_g = w_g_;
		w_b = w_b_;
	}
	
	void SetColor() {
		float a = 255;
		if (w_a)
			a = w_a.GetCurrent();
		float r = 255;
		if (w_r)
			r = w_r.GetCurrent();
		float g = 255;
		if (w_g)
			g = w_g.GetCurrent();
		float b = 255;
		if (w_b)
			b = w_b.GetCurrent();
		target_.SetColor(ARGB(a,r,g,b));
	}
	
}