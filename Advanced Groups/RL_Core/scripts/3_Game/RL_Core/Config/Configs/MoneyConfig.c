class RLCurrencyConfig : RLConfigLoader<RLCurrencyConfig_> {
	
	override void InitVars() {
		InitVarsInternal("Common", "Currencies.json", RLConfigType.CONFIG, true, "currencies.change");
	}
	
}

// Shared config between all RayLab Mods for all setting up money items

class RLCurrencyConfig_ : RLConfigBase {

	override int GetCurrentVersion() {
		return 2;
	}
	
	private string currencyPrefix = "$"; // Some currencies like $ put the symbol before the number. This can be set here
	private string currencySuffix = ""; // Some currencies like € put the symbol after the number. This can be set here
	private ref array<ref RLCurrencyConfigEntry> currencyValues = new array<ref RLCurrencyConfigEntry>(); // List of items, which can be used as currencies (make sure the Item has a quantity and can be stacked. Otherwise the Item will not be recognized)
	
	override void LoadDefault() {
		currencyValues.Insert(RLCurrencyConfigEntry.Init("MoneyRuble1", 1));
		currencyValues.Insert(RLCurrencyConfigEntry.Init("MoneyRuble5", 5));
		currencyValues.Insert(RLCurrencyConfigEntry.Init("MoneyRuble10", 10));
		currencyValues.Insert(RLCurrencyConfigEntry.Init("MoneyRuble25", 25));
		currencyValues.Insert(RLCurrencyConfigEntry.Init("MoneyRuble50", 50));
		currencyValues.Insert(RLCurrencyConfigEntry.Init("MoneyRuble100", 100));
	}
	
	override void UpdateVersion() {
		if (version <= 0) {
			currencyPrefix = "$";
		}
	}
	
	override bool OnLoad() {
		SortCurrencyValues();
		CheckCurrenciesSpawnable();
		return false;
	}
	
	override void OnReceivedFromRPC(PlayerIdentity sender) {
		SortCurrencyValues();
		CheckCurrenciesSpawnable();
	}
	
	void RemoveEntry(int index) {
		currencyValues.RemoveOrdered(index);
	}
	
	void InsertNewEntry(string text) {
		currencyValues.Insert(RLCurrencyConfigEntry.Init("Itemname", 10));
	}
	
	int CountEntries() {
		return currencyValues.Count();
	}
	
	string GetEntry(int index, int column) {
		return currencyValues.Get(index).itemclassname;
	}
	
	RLCurrencyConfigEntry GetEntryClass(int index) {
		return currencyValues.Get(index);
	}
	
	void CheckCurrenciesSpawnable() {
		bool save = false;
		foreach (RLCurrencyConfigEntry currency : currencyValues) {
			string path = "CfgVehicles " + currency.itemclassname;
			TStringArray fullPath = new TStringArray();
			GetGame().ConfigGetFullPath(path, fullPath);
			if (fullPath.Count() <= 0) {
				RLLogger.Fatal("Could not find Currency with the Itemname: \"" + currency.itemclassname + "\" (" + currency.value + ")", "Core");
				continue;
			}
			string last = fullPath.Get(0);
			if (last != currency.itemclassname) {
				RLLogger.Info("Updated Currency Itemname from " + currency.itemclassname + " (" + currency.value + ") to " + last, "Core");
				currency.itemclassname = last;
				save = true;
			} else {
				RLLogger.Info("Currency " + currency.itemclassname + " (" + currency.value + ") was configured properly", "Core");
			}
		}
		if (save)
			RLCurrencyConfig.Loader.Save();
	}
	
	array<ref RLCurrencyConfigEntry> GetDepositableMoney() {
		return currencyValues;
	}
	
	array<ref RLCurrencyConfigEntry> GetWithdrawableMoney() {
		array<ref RLCurrencyConfigEntry> arr = new array<ref RLCurrencyConfigEntry>();
		foreach (RLCurrencyConfigEntry entry : currencyValues) {
			if (!entry.depositableOnly)
				arr.Insert(entry);
		}
		return arr;
	}
	
	void ClearValues() {
		this.currencyValues.Clear();
	}
	
	void AddValue(RLCurrencyConfigEntry entry) {
		currencyValues.Insert(entry);
	}
	
	void SortCurrencyValues() {
		bool sorted = false;
		while (!sorted) {
			sorted = true;
			for (int i = 0; i < currencyValues.Count() - 1; i++) {
				RLCurrencyConfigEntry first = currencyValues.Get(i);
				RLCurrencyConfigEntry second = currencyValues.Get(i + 1);
				if (first.value > second.value) {
					currencyValues.Set(i, second);
					currencyValues.Set(i + 1, first);
					sorted = false;
				}
			}
		}
	}
	
	int GetCurrencyValue(string classname) {
		foreach (RLCurrencyConfigEntry entry : currencyValues) {
			if (entry && entry.itemclassname == classname)
				return entry.value;
		}
		return 0;
	}
	
	string GetFormattedMoneyString(int amount) {
		return currencyPrefix + amount + currencySuffix;
	}
	
	string GetCurrencyPrefix() {
		return currencyPrefix;
	}
	
	string GetCurrencySuffix() {
		return currencySuffix;
	}
	
	void SetPrefix(string prefix) {
		currencyPrefix = prefix;
	}
	
	void SetSuffix(string suffix) {
		currencySuffix = suffix;
	}
	
	int GetPlayerMoney() {
		return GetInventoryMoney(GetGame().GetPlayer());
	}
	
	string GetPlayerMoneyFormatted() {
		return GetFormattedMoneyString(GetPlayerMoney());
	}
	
	int GetInventoryMoney(EntityAI inventoryOwner) {
		return GetInventoryMoney(inventoryOwner.GetInventory());
	}
	
	int GetInventoryMoney(GameInventory inventory) {
		
		array<EntityAI> itemsArray = new array<EntityAI>;
		inventory.EnumerateInventory(InventoryTraversalType.PREORDER, itemsArray);

		int currencyAmount = 0;
		foreach (EntityAI item : itemsArray) {
			currencyAmount += GetItemValue(item);
		}
		
		return currencyAmount;
	}
	
	int GetItemValue(EntityAI item) {
		if (item)
			return GetItemCount(item) * GetCurrencyValue(item.GetType());
		return 0;
	}
	
	static int GetItemCount(EntityAI item) {
		if (item) {
			if (item.ConfigGetBool("quantityBar"))
				return 1;
			int max = item.GetQuantityMax();
			if (max > 0)
				return item.GetQuantity();
			return 1;
		}
		return 0;
	}
	
}
class RLCurrencyConfigEntry {

	string itemclassname; // The Classname (case sensitive) of the Item
	int value; // Value **one** Item is worth (not a whole stack). For example there is a 1 Dollar bill and you can stack them up to 500, then the value is 1 and not 500
	bool depositableOnly = false; // Set if the currency can only be deposited, for example to deposit something like Gold Bars at the ATM, but don't give them back when withdrawing money
	
	static RLCurrencyConfigEntry Init(string classname, int val, bool depositableOnly_ = false) {
		RLCurrencyConfigEntry entry = new RLCurrencyConfigEntry();
		entry.itemclassname = classname;
		entry.value = val;
		entry.depositableOnly = depositableOnly_;
		return entry;
	}
	
	void WriteToCtx(ParamsWriteContext ctx) {
		ctx.Write(itemclassname);
		ctx.Write(value);
		ctx.Write(depositableOnly);
	}
	
	bool ReadFromCtx(ParamsReadContext ctx) {
		if (!ctx.Read(itemclassname))
			return false;
		if (!ctx.Read(value))
			return false;
		if (!ctx.Read(depositableOnly))
			return false;
		return true;
	}
	
}
