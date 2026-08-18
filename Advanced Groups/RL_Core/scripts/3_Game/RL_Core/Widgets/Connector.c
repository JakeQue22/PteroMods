bool ConnectClassWidgetVariables(Class clazzInstance, Widget classLayoutRootVar, TStringArray ignored = null, TStringArray variableRemappings = null, string debugLayoutPath = "") {
	bool ok = true;
	typename me = clazzInstance.Type();
	int varCount = me.GetVariableCount();
	for (int i = 0; i < varCount; i++) {
		string varName = me.GetVariableName(i);
		string widgetName = varName;
		if (variableRemappings) {
			int index = variableRemappings.Find(widgetName);
			if (index != -1) {
				widgetName = variableRemappings.Get(index + 1);
			}
		}
		if (ignored && ignored.Find(varName) != -1)
			continue;
		typename type = me.GetVariableType(i);
		if (type.IsInherited(Widget)) {
			Widget w = classLayoutRootVar.FindAnyWidget(widgetName);
			if (w) {
				EnScript.SetClassVar(clazzInstance, varName, 0, w);
				RLLogger.Verbose("Found widget for " + varName, "General");
			} else if (varName != "layoutRoot" && varName != "m_AnimAlphaWidget") {
				ok = false;
				RLLogger.Error("Could not find Widget named " + varName + " in layout " + me.ToString() + " (" + debugLayoutPath + ")", "General");
			}
		}
	}
	return ok;
}