class RLUtils {

	static bool IsPlayerAlive(Man player) {
		return GetGame() && player && player.IsAlive() && !player.IsUnconscious();
	}
	
	static bool IsClientPlayerAlive() {
		return GetGame() && IsPlayerAlive(GetGame().GetPlayer());
	}
	
	static string ToLowerString(string other) {
		string copy = other + "";
		copy.ToLower();
		return copy;
	}
	
	static PlayerIdentity GetPlayerIdentityById(string id) {
		bool steamid = id.Length() == 17;
		array<PlayerIdentity> identities = new array<PlayerIdentity>();
		GetGame().GetPlayerIndentities(identities);
		
		foreach (PlayerIdentity ident : identities) {
			if (!ident)
				continue;
			if (steamid) {
				if (ident.GetPlainId() == id)
					return ident;
			} else {
				if (ident.GetId() == id)
					return ident;
			}
		}
		return null;
	}
}