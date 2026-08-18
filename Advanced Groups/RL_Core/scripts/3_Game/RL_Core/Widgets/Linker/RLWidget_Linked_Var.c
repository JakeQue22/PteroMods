class RLWidget_Linked_Var : RLWidget_Linker {
	
	private Widget linkedTo, extraOutput;
	private int extraColumn = 0;
	private bool invertBool = false, loadOnly = false;
	
	void RLWidget_Linked_Var(string varPath_, Widget linkedTo_, Class parent_) {
		this.mainParent = parent_;
		SetVarPath(varPath_);
		this.linkedTo = linkedTo_;
	}
	
	RLWidget_Linked_Var SetInvertedBool(bool inverted) {
		this.invertBool = inverted;
		return this;
	}
	
	RLWidget_Linked_Var SetPermission(string perm, array<Widget> hiddenOnHoPerm = null) {
		if (perm == "")
			return this;
		if (RLAdmins.Get().HasPermission(perm))
			return this;
		if (linkedTo)
			linkedTo.Show(false);
		if (!hiddenOnHoPerm)
			return this;
		foreach (Widget w : hiddenOnHoPerm) {
			w.Show(false);
		}
		return this;
	}
	
	RLWidget_Linked_Var SetLoadOnly(bool b) {
		this.loadOnly = b;
		return this;
	}
	
	RLWidget_Linked_Var SetExtraOutput(Widget extraOutput_, int colm = 0) {
		this.extraOutput = extraOutput_;
		this.extraColumn = colm;
		return this;
	}
	
	override bool OnVarChanged(Widget w) {
		if (w != linkedTo || GetConfigVarParent() == null || loadOnly)
			return false;
		CheckBoxWidget chckbx = CheckBoxWidget.Cast(linkedTo);
		if (chckbx) {
			LoadCheckboxValue(chckbx, invertBool);
			TriggerOnChange(w);
			return true;
		}
		EditBoxWidget editbx = EditBoxWidget.Cast(linkedTo);
		if (editbx) {
			LoadEditBoxValue(editbx, extraColumn, extraOutput);
			TriggerOnChange(w);
			return true;
		}
		SliderWidget slider = SliderWidget.Cast(linkedTo);
		if (slider) {
			LoadSliderValue(slider);
			TriggerOnChange(w);
			return true;
		}
		return false;
	}
	
	override bool Load() {
		bool ok = LoadVar(linkedTo, invertBool, extraColumn, extraOutput);
		if (ok)
			TriggerOnChange(linkedTo);
		return ok;
	}
	
}