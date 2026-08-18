// Thanks to @Dr Jones for letting me use his Trader Money Code

class RL_ATM_Playerbase : RL_ATM_PlayerbaseBase {
	
	private PlayerBase player;
	
	void RL_ATM_Playerbase(Man pb) {
		player = PlayerBase.Cast(pb);
	}

	PlayerBase GetPlayer() {
		return player;
	}
	
	static int SpawnMoneyOnGround(vector location, int amount, float radius, bool placeOnGround) {
		array<ref RLCurrencyConfigEntry> currencies = RLCurrencyConfig.Get.GetWithdrawableMoney();
		ItemBase item;
		int original = amount;
		
		for (int i = currencies.Count() - 1; i >= 0; i--)
		{

			int failed = 0;
			RLCurrencyConfigEntry entry = currencies.Get(i);
			RLLogger.Verbose("Adding " + entry.itemclassname, "Core");
			int itemMaxAmount = GetItemMaxQuantity(entry.itemclassname);
			if (itemMaxAmount <= 0)
				continue;

			while (amount / entry.value > 0 && failed < 10)
			{
				RLLogger.Verbose("Money left: " + amount, "Core");
				Object entity = GetGame().CreateObject(entry.itemclassname, location);
				Class.CastTo(item, entity);
				if (!item) {
					failed++;
					continue;
				}
				if (radius > 0) {
					float angle = Math.RandomFloat(0, 360);
					float x = Math.Sin(angle) * radius;
					float y = Math.Cos(angle) * radius;
					entity.SetPosition(location + Vector(x, y, 0));
				}
				if (placeOnGround) {
					item.PlaceOnSurface();
				}
				
				if (amount >= itemMaxAmount * entry.value)
				{
					SetItemCount(item, itemMaxAmount);
					amount -= itemMaxAmount * entry.value;

				}
				else
				{
					SetItemCount(item, amount / entry.value);	
					amount -= (amount / entry.value * entry.value);	

				}

				if (amount <= 0)
					break;
			}
		}
		return original - amount;
	}

	override int AddInventoryMoney(int currencyAmount) {
		if (currencyAmount <= 0)
			return 0;

		int original = currencyAmount;
		array<ref RLCurrencyConfigEntry> currencies = RLCurrencyConfig.Get.GetWithdrawableMoney();
		array<EntityAI> inventoryItems = new array<EntityAI>;
		player.GetInventory().EnumerateInventory(InventoryTraversalType.PREORDER, inventoryItems);
		
		for (int i = currencies.Count() - 1; i >= 0; i--) {
			int failed = 0;
			RLCurrencyConfigEntry entry = currencies.Get(i);
			int itemMaxAmount = GetItemMaxQuantity(entry.itemclassname);
			if (itemMaxAmount <= 0)
				continue;

			RLLogger.Verbose("Adding " + entry.itemclassname, "Core");
			while (currencyAmount / entry.value > 0 && failed < 10 + currencies.Count()) {
				RLLogger.Verbose("Money left: " + currencyAmount, "Core");
				int removed;
				if (currencyAmount > itemMaxAmount * entry.value) {
					removed = CreateItemInInventory(entry.itemclassname, itemMaxAmount, inventoryItems, failed);
				} else {
					removed = CreateItemInInventory(entry.itemclassname, currencyAmount / entry.value, inventoryItems, failed);
				}
				if (removed > 0)
					currencyAmount -= removed * entry.value;
			}
		}
		return original - currencyAmount;
	}
	
	override int CreateItemInInventory(string itemType, int amount, array<EntityAI> inventoryItems, out int failed) {

		int original = amount;
		int currentAmount = amount;
		ItemBase item;
		bool worked = false;
		int max = GetItemMaxQuantity(itemType);
		if (max > 1) { // Stackable item ?
			for (int i = 0; i < inventoryItems.Count(); i++) {
				if (currentAmount <= 0) // Nothing more to add ?
					break;
				if (Class.CastTo(item, inventoryItems.Get(i))) {
					int count = RLCurrencyConfig_.GetItemCount(item);
					if (item.IsRuined())
						continue;

					if (itemType == item.GetType() && count < max) {
						worked = true;
						int newCount = Math.Min(currentAmount + count, max);
						SetItemCount(item, newCount);
						currentAmount -= newCount - count;
					}
				}
			}
		} else { // Non Stackable
			original = 1;
			currentAmount = 1;
		}

		if (currentAmount > 0) { // Any amount of items left to add, or all items could be stacked with items in the inventory
			EntityAI newItem = EntityAI.Cast(player.GetInventory().CreateInInventory(itemType));
			if (!newItem) {
				for (int j = 0; j < inventoryItems.Count(); j++) {
					if (!Class.CastTo(item, inventoryItems.Get(j)))
						continue;
					newItem = EntityAI.Cast(item.GetInventory().CreateInInventory(itemType));
					if (newItem)
						break;
				}
			}
			if (!newItem) {
				if (!worked)
					failed++;
				return original - currentAmount;
			}
			ItemBase newItemBase;
			if (Class.CastTo(newItemBase, newItem)) {
				inventoryItems.Insert(newItem);
				worked = true;
				SetItemCount(newItemBase, currentAmount);
				currentAmount = 0;
			}
		}
		if (!worked)
			failed++;
		return original - currentAmount;
	}

	override int RemoveInventoryMoney(int currencyAmount)
	{		
		if (currencyAmount <= 0)
			return 0;
		int before = GetInventoryMoney();
		array<EntityAI> itemsArray = new array<EntityAI>;
		ItemBase item;
		player.GetInventory().EnumerateInventory(InventoryTraversalType.PREORDER, itemsArray);
		
		RLCurrencyConfig_ currencyConfig = RLCurrencyConfig.Get;
		array<ref RLCurrencyConfigEntry> currencies = currencyConfig.GetDepositableMoney();
		
		foreach (RLCurrencyConfigEntry entry : currencies)
		{
			for (int j = 0; j < itemsArray.Count(); j++)
			{
				Class.CastTo(item, itemsArray.Get(j));
				
				if (!item)
					continue;

				if(item.GetType() == entry.itemclassname)
				{
					int itemAmount = RLCurrencyConfig_.GetItemCount(item);

					if(itemAmount * entry.value > currencyAmount)
					{
						if (currencyAmount >= entry.value)
						{
							SetItemCount(item, itemAmount - (currencyAmount / entry.value));

							player.UpdateInventoryMenu(); // RPC-Call needed?
							
							currencyAmount -= (currencyAmount / entry.value) * entry.value;
						}


						if (currencyAmount < entry.value)
						{
							ExchangeMoney(item, currencyAmount, entry.value);

							return before - GetInventoryMoney();
						}
					}
					else
					{
						DeleteItemSave(itemsArray.Get(j));
						
						player.UpdateInventoryMenu(); // RPC-Call needed?
						
						currencyAmount -= itemAmount * entry.value;
					}
				}
			}
		}
		return before - GetInventoryMoney();
	}

	protected void ExchangeMoney(ItemBase item, int currencyAmount, int currencyValue)
	{
		if (!item)
			return;

		if (currencyAmount <= 0)
			return;

		//TraderMessage.PlayerWhite("EXCHANGE " + item.GetType() + " [" + currencyValue + "] " + currencyAmount, this);

		int itemAmount = RLCurrencyConfig_.GetItemCount(item);

		if (itemAmount <= 1)
			DeleteItemSave(item);
		else
			SetItemCount(item, itemAmount - 1);

		AddInventoryMoney(currencyValue - currencyAmount);
	}

	private static bool SetItemCount(ItemBase item, int amount)
	{
		if (!item)
			return false;

		if (amount == -1)
			amount = GetItemMaxQuantity(item.GetType());

		if (amount == -3)
			amount = 0;

		if (amount == -4)
			amount = 0;

		if (amount == -5)
			amount = Math.RandomIntInclusive(GetItemMaxQuantity(item.GetType()) * 0.5, GetItemMaxQuantity(item.GetType()));
		if (item.ConfigGetBool("quantityBar"))
			return true;

		item.SetQuantity(amount);

		return true;
	}

	override protected bool RemoveFromInventory(string itemClassname, int amount)
	{

		string itemPlayerClassname = "";
		int itemAmount = -1;


		array<EntityAI> itemsArray = new array<EntityAI>;
		player.GetInventory().EnumerateInventory(InventoryTraversalType.PREORDER, itemsArray);
		ItemBase item = ItemBase.Cast(player.GetHumanInventory().GetEntityInHands());
		if (item)
			itemsArray.Insert(item);
		
		for (int i = 0; i < itemsArray.Count(); i++)
		{
			Class.CastTo(item, itemsArray.Get(i));
			itemPlayerClassname = "";

			if (!item || item.IsRuined())

			itemPlayerClassname = item.GetType();

			if(itemPlayerClassname == itemClassname && RLCurrencyConfig_.GetItemCount(item) >= amount)
			{
				itemAmount = RLCurrencyConfig_.GetItemCount(item);
				
				if (itemAmount == amount)
				{
					DeleteItemSave(item);
					
					player.UpdateInventoryMenu(); // RPC-Call needed?
					return true;
				}
				else
				{
					SetItemCount(item, itemAmount - amount);
				
					player.UpdateInventoryMenu(); // RPC-Call needed?
					return true;
				}
			}
		}
		
		player.UpdateInventoryMenu(); // RPC-Call needed?
		return false;
	}
}