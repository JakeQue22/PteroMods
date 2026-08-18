class RL_PlayerBase_Utils {
	
	static bool HasItemsInInventory(Man player, TStringArray findItems) {
		if (!player)
			return false;
		array<EntityAI> items = new array<EntityAI>();
		player.GetInventory().EnumerateInventory(InventoryTraversalType.PREORDER ,items);
		foreach (EntityAI item : items) {
			if (!item)
				continue;
			string type = item.GetType();
			if (findItems.Find(type) != -1)
				return true;
		}
		return false;
	}
	
	static vector GetHeadPosition(Man player) {
		if (!player)
			return vector.Zero;
		int bone = player.GetBoneIndex("Spine2");
		return player.GetBonePositionWS(bone);
	}
	
}