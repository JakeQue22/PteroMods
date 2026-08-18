class RLPlayers {
	
	static ref array<Man> players = new array<Man>();
	static ref array<PlayerIdentity> identities = new array<PlayerIdentity>();
	
	static PlayerIdentity GetIdentityBySteamid(string steamid) {
		foreach (PlayerIdentity player : identities) {
			if (player && player.GetPlainId() == steamid)
				return player;
		}
		return null;
	}
	
	static Man GetPlayerBySteamid(string steamid) {
		foreach (Man player : players) {
			if (player && player.GetIdentity() && player.GetIdentity().GetPlainId() == steamid)
				return player;
		}
		return null;
	}
	
}