modded class PlayerBase {
	
	void PlayerBase() {
		if (RLPlayers.players) {
			RLPlayers.players.Insert(this);
			RLLogger.Debug("Added player to list: " + this.ToString(), "AdvancedGroup");
		}
	}
	
	void ~PlayerBase() {
		if (RLPlayers.players) {
			RLPlayers.players.RemoveItem(this);
			RLLogger.Debug("Removed player from list: " + this.ToString(), "PlayerBase");
		}
	}
	
}