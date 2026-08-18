class CurrenciesAdminMenu : RLAdmin_Menu_Page {

	EditBoxWidget input_prefix, input_suffix, input_itemname, input_value;
	CheckBoxWidget chckbx_depositable_only;
	ButtonWidget btn_add, btn_delete, btn_save;
	TextListboxWidget list_currencies;
	ItemPreviewWidget item_preview;
	EntityAI lastPreview = null;
	
	override void InitWidgets() {
		super.InitWidgets();
	}
	
	override void OnShow() {
		linked.LoadLinkedVars();
	}
	
	override void OnHide() {
		RLCurrencyConfig.Loader.Load();
		if (lastPreview) {
			GetGame().ObjectDelete(lastPreview);
		}
	}
	
	override void OnRPC(PlayerIdentity sender, Object target, int rpc_type, ParamsReadContext ctx) {
		if (rpc_type == 42454776) {
			GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Call(linked.LoadLinkedVars);
		}
	}
	
	override void RegisterAllLinkedVars() {
		linked.RegisterLinkedVar("GetSelectedCurrency().itemclassname", input_itemname).SetExtraOutput(list_currencies, 0).SetReloadTrigger(list_currencies).SetChangeTrigger("UpdateCurrencies()");
		linked.RegisterLinkedVar("GetSelectedCurrency().value", input_value).SetReloadTrigger(list_currencies);
		linked.RegisterLinkedVar("GetSelectedCurrency().depositableOnly", chckbx_depositable_only).SetReloadTrigger(list_currencies);
		linked.RegisterLinkedVar("GetCurrencyConfig().currencyPrefix", input_prefix);
		linked.RegisterLinkedVar("GetCurrencyConfig().currencySuffix", input_suffix);
		linked.RegisterLinkedList("GetCurrencyConfig().currencyValues", list_currencies, btn_add, btn_delete, null).SetFunctions("RemoveEntry", "InsertNewEntry", "CountEntries", "GetEntry").SetChangeTrigger("UpdateCurrencies()");
		TStringSet allItems = RLInherit.Get().GetEntry("All").GetChildren(false, true, false, 2);
		RLAutoComplete.Get().AddHandler(input_itemname, RLArrayTools<string>.ToArray(allItems));
	}
	
	void UpdateCurrencies() {
		UpdateSelectedCurrency();
		for (int i = 0; i < list_currencies.GetNumItems(); i++) {
			RLCurrencyConfigEntry entry = RLCurrencyConfig.Get.GetEntryClass(i);
			string path = "CfgVehicles " + entry.itemclassname;
			TStringArray fullPath = new TStringArray();
			GetGame().ConfigGetFullPath(path, fullPath);
			if (fullPath.Count() <= 0) {
				list_currencies.SetItemColor(i, 0, ARGB(255, 255, 0,0));
			} else {
				list_currencies.SetItemColor(i, 0, ARGB(255, 0, 255,0));
			}
		}
	}
	
	int GetEntryColor(int index) {
		
	}
	
	RLCurrencyConfig_ GetCurrencyConfig() {
		return RLCurrencyConfig.Get;
	}
	
	override bool OnClick(Widget w, int x, int y, int button) {
		super.OnClick(w, x, y, button);
		RLCurrencyConfigEntry entry;
		if (w == btn_save) {
			RLCurrencyConfig.Loader.Save();
		}
		return false;
	}
	
	override bool OnChange(Widget w, int x, int y, bool finished) {
		bool changed = super.OnChange(w, x, y, finished);
		RLAutoComplete.Get().OnChange(w, x, y, finished);
		return changed;
	}
	
	RLCurrencyConfigEntry GetSelectedCurrency() {
		
		int row = list_currencies.GetSelectedRow();
		if (row < 0 || row >= list_currencies.GetNumItems())
			return null;
		return RLCurrencyConfig.Get.GetEntryClass(row);
	}
	
	void UpdateSelectedCurrency() {
		RLCurrencyConfigEntry entry = GetSelectedCurrency();
		if (!entry)
			return;
		if (lastPreview) {
			item_preview.SetItem(null);
			GetGame().ObjectDelete(lastPreview);
		}
		
		Object obj = GetGame().CreateObjectEx(entry.itemclassname, vector.Zero, ECE_LOCAL);
		lastPreview = EntityAI.Cast(obj);
		if (!lastPreview && obj) {
			GetGame().ObjectDelete(obj);
		} else {
			item_preview.SetItem(lastPreview);
		}
	}
	
	override string GetButtonName() {
		return "Currencies";
	}
	
	override string GetPageShowPermission() {
		return "currencies.change";
	}
	
}