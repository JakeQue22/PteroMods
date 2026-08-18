/*class ActionInvitePlayerToGroup : ActionInteractBase {
	
	void ActionInvitePlayerToGroup()
	{
		m_CommandUID = DayZPlayerConstants.CMD_ACTIONMOD_INTERACTONCE;
		m_StanceMask = DayZPlayerConstants.STANCEMASK_ERECT | DayZPlayerConstants.STANCEMASK_CROUCH;
	}
	
	override void CreateConditionComponents()  
	{		
		m_ConditionTarget = new CCTMan(UAMaxDistances.DEFAULT);
		m_ConditionItem = new CCINone;
	}
	
	override string GetText()
	{
		return "Invite Player";
	}
	
	override bool ActionCondition( PlayerBase player, ActionTarget target, ItemBase item ) {
		return false;
		RLGroup grp = player.GetRLGroup();
		if (!grp)
			return false;
		RLGroupPermission perms = player.GetPermission();
		if (!perms)
			return false;
		PlayerBase targetPlayer = PlayerBase.Cast(target.GetObject());
		return perms.canInvite && targetPlayer && targetPlayer.GetIdentity();
	}
	
	override void OnExecuteServer( ActionData action_data ) {
		
	}
}
*/