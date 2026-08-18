class RL_RPCHandler : RL_RPCHandler_Base {

	protected PlayerBase player;
	
	protected bool FindPlayer() {
		return GetPlayer() != null;
	}
	
	override void LinkVariable() {
		super.LinkVariable();
		player = null;
	}
	
	private PlayerBase GetPlayer() {
		if (!player)
			player = PlayerBase.Cast(GetMan());
		return player;
	}
	
}