class RLWidget_Linked_List : RLWidget_Linker {
	
	private EditBoxWidget searchBar;
	private Widget list;
	private ButtonWidget add, remove;
	private EditBoxWidget input;
	private string funcRemove, funcInsertNew, funcCount, funcGetString, funcSearch, funcOnLoadStart, funcOnLoadEntry;
	private bool functionsSet = false, loadFunctionsSet = false;
	private RLLinkedVarHandler parentHandler;
	private int columnCount = 1;
	private ref array<Widget> searchRefreshTrigger;
	
	void RLWidget_Linked_List(string varPath_, Widget listOutput, ButtonWidget addButton, ButtonWidget removeButton, EditBoxWidget inputField, Class parent_, RLLinkedVarHandler parentHandler_) {
		this.mainParent = parent_;
		SetVarPath(varPath_);
		this.list = listOutput;
		this.add = addButton;
		this.remove = removeButton;
		this.input = inputField;
		this.parentHandler = parentHandler_;
	}
	
	override bool CheckReloadTrigger(Widget w) {
		if (super.CheckReloadTrigger(w))
			return true;
		if (searchRefreshTrigger) {
			foreach (Widget trigg : searchRefreshTrigger) {
				if (w == trigg)
					return true;
			}
		}
		return (w == searchBar && w != null);
	}
	
	RLWidget_Linked_List SetLoadFunctions(string funcOnLoadStart_, string funcOnLoadEntry_) {
		this.funcOnLoadStart = funcOnLoadStart_;
		this.funcOnLoadEntry = funcOnLoadEntry_;
		this.loadFunctionsSet = true;
		return this;
	}
	
	RLWidget_Linked_List SetFunctions(string funcRemove_, string funcInsertNew_, string funcCount_, string funcGetString_) {
		this.funcRemove = funcRemove_;
		this.funcInsertNew = funcInsertNew_;
		this.funcCount = funcCount_;
		this.funcGetString = funcGetString_;
		this.functionsSet = true;
		return this;
	}
	
	RLWidget_Linked_List SetSearchBar(EditBoxWidget searchBar_, string funcSearch_, array<Widget> searchRefreshTrigger_ = null) {
		this.searchBar = searchBar_;
		this.funcSearch = funcSearch_;
		this.searchRefreshTrigger = searchRefreshTrigger_;
		return this;
	}
	
	RLWidget_Linked_List SetColumnCount(int count) {
		if (count > 0) {
			columnCount = count;
		}
		return this;
	}
	
	override bool OnVarChanged(Widget w) {
		if (w == add) {
			AddEntry();
			return true;
		} else if (w == remove) {
			RemoveEntry();
			return true;
		}
		return false;
	}
	
	private string GetSearchString() {
		if (searchBar == null || funcSearch == "")
			return "";
		return searchBar.GetText();
	}
	
	private bool IsSearched(int index, string search, Class parent_) {
		if (search == "")
			return true;
		bool searched = false;
		GetGame().GameScript.CallFunctionParams(mainParent, funcSearch, searched, new Param2<int, string>(index, search));
		return searched;
	}
	
	private void CallOnLoadStart() {
		if (!loadFunctionsSet)
			return;
		GetGame().GameScript.CallFunction(mainParent, funcOnLoadStart, null, null);
	}
	
	private void CallOnEntryLoaded(int index) {
		if (!loadFunctionsSet)
			return;
		GetGame().GameScript.CallFunctionParams(mainParent, funcOnLoadEntry, null, new Param1<int>(index));
	}
	
	override bool Load() {
			
		XComboBoxWidget combo = XComboBoxWidget.Cast(list);
		TextListboxWidget listbox = TextListboxWidget.Cast(list);
		Class parent = GetConfigVarParent();
		Print("List: Combo: " + combo + " Or TestListBox: " + listbox);
		int lastSelected = GetCurrentSelectedIndex(list);
		int listSize = Count(parent);
		string search = GetSearchString();
		CallOnLoadStart();
		array<int> searchIndices;
		if (searchBar) {
			searchIndices = parentHandler.GetSearchIndices(searchBar);
			Print("Loading Search Indices: " + searchIndices);
			searchIndices.Clear();
		}
		if (combo) {
			combo.ClearAll();
			for (int i = 0; i < listSize; i++) {
				if (!IsSearched(i, search, parent))
					continue;
				Print("Converting Entry " + i + " of " + listSize);
				string str = GetEntry(parent, i, 0);
				Print("Addding item " + str + " to " + combo);
				combo.AddItem(str);
				CallOnEntryLoaded(i);
				if (searchBar)
					searchIndices.Insert(i);
			}
			
		}
		if (listbox) {
			listbox.ClearItems();
			for (i = 0; i < listSize; i++) {
				int row = 0;
				if (!IsSearched(i, search, parent))
					continue;
				for (int col = 0; col < columnCount; col++) {
					string str2 = GetEntry(parent, i, col);
					Print("Addding item " + str2 + " to " + listbox);
					if (col == 0)
						row = listbox.AddItem(str2, null, 0);
					else
						listbox.SetItem(row, str2, null, col);
					CallOnEntryLoaded(i);
				}
				if (searchBar)
					searchIndices.Insert(i);
			}
		}
		if (lastSelected < 0) {
			lastSelected = 0;
		}
		if (lastSelected >= listSize) {
			lastSelected = listSize - 1;
		}
		if (SelectEntry(list, lastSelected))
			parentHandler.OnVarChange(list);
		TriggerOnChange(combo);
		return true;
	}
	
	private void RemoveEntry() {
		int row = GetCurrentSelectedIndex(list);
		if (row < 0)
			return;
		if (functionsSet) {
			Class parent = GetConfigVarParent();
			GetGame().GameScript.CallFunction(parent, funcRemove, null, row);
		} else {
			Class arr = GetConfigVarAsClass();
			TStringArray txtArr = TStringArray.Cast(arr);
			if (txtArr) {
				txtArr.RemoveOrdered(row);
			}
		}
		Load();
	}
	
	private void AddEntry() {
		Class parent = GetConfigVarParent();
		string text = "";
		if (input) {
			text = input.GetText();
			input.SetText("");
		}
		if (functionsSet) {
			GetGame().GameScript.CallFunction(parent, funcInsertNew, null, text);
		} else {
			Class arr = GetConfigVarAsClass();
			TStringArray txtArr = TStringArray.Cast(arr);
			if (txtArr) {
				txtArr.Insert(text);
			}
		}
		Load();
		SelectEntry(list);
	}
	
	private int Count(Class parent) {
		int count;
		if (functionsSet) {
			GetGame().GameScript.CallFunction(parent, funcCount, count, null);
		} else {
			Class arr = GetConfigVarAsClass();
			TStringArray txtArr = TStringArray.Cast(arr);
			if (txtArr) {
				return txtArr.Count();
			}
		}
		return count;
	}
	
	private string GetEntry(Class parent, int index, int column) {
		string str = "NULL";
		if (functionsSet) {
			int ret = GetGame().GameScript.CallFunctionParams(parent, funcGetString, str, new Param2<int, int>(index, column));
			Print("Getting Entry with function returned " + ret + ". Parent: " + parent + " Function: " + funcGetString + " at " + index + ":" + column);
		} else {
			Class arr = GetConfigVarAsClass();
			TStringArray txtArr = TStringArray.Cast(arr);
			if (txtArr) {
				str = txtArr.Get(index);
			}
		}
		return str;
	}
}