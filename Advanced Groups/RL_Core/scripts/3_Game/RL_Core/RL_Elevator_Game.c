class RL_Elevator {

	private static ref RL_Elevator g_RL_Elevator;
	
	static RL_Elevator Get() {
		return g_RL_Elevator;
	}
	
	static void Set(RL_Elevator instance) {
		g_RL_Elevator = instance;
	}
	
}

class RL_Elevator_Game : RL_Elevator {
	
	RL_ATM_PlayerbaseBase LoadATMPlayer(Man player);
	
}
int rl_elevator_init_game = RL_ElevatorInitGame();
int RL_ElevatorInitGame() {
	RL_Elevator.Set(new RL_Elevator_Game());
	return 0;
}
RL_Elevator_Game GetElevatorGame() {
	return RL_Elevator_Game.Cast(RL_Elevator.Get());
}