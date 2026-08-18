class RL_Elevator_World : RL_Elevator_Game {
	
	override RL_ATM_PlayerbaseBase LoadATMPlayer(Man player) {
		return new RL_ATM_Playerbase(player);
	}
	
}
int rl_elevator_init_world = RL_ElevatorInitWorld();
int RL_ElevatorInitWorld() {
	RL_Elevator.Set(new RL_Elevator_World());
	return 0;
}
RL_Elevator_World GetElevatorWorld() {
	return RL_Elevator_World.Cast(RL_Elevator.Get());
}