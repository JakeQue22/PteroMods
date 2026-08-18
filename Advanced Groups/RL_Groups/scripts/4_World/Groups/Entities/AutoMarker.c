modded class BuildingBase {
	
	bool AddAutoMarker() {
		return false;
	}
	
}
modded class CarScript {
	
	bool AddAutoMarker() {
		return false;
	}
	
}
modded class ItemBase {
	
	bool AddAutoMarker() {
		return false;
	}
	
}
modded class DayZPlayerImplement {
	
	bool AddAutoMarker() {
		return false;
	}
	
}

#ifdef DZ_Expansion_Market
modded class ExpansionTraderNPCBase {

	string GetRLMarkerName() {
		ExpansionTraderObjectBase obj = GetTraderObject();
		if (!obj)
			return "";
		ExpansionMarketTrader market = obj.GetTraderMarket();
		if (!market)
			return "";
		return market.DisplayName;
	}
	
	override bool AddAutoMarker() {
		return true;
	}
	
}
#ifdef ENFUSION_AI_PROJECT
modded class ExpansionTraderAIBase {

	string GetRLMarkerName() {
		ExpansionTraderObjectBase obj = GetTraderObject();
		if (!obj)
			return "";
		ExpansionMarketTrader market = obj.GetTraderMarket();
		if (!market)
			return "";
		return market.DisplayName;
	}
	
	override bool AddAutoMarker() {
		return true;
	}
	
}
#endif
#endif