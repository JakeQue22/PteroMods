class RL_Elevator_Mission : RL_Elevator_World {
}
int rl_elevator_init_mission = RL_ElevatorInitMission();
int RL_ElevatorInitMission() {
	RL_Elevator.Set(new RL_Elevator_Mission());
	return 0;
}
RL_Elevator_Mission GetElevatorMission() {
	return RL_Elevator_Mission.Cast(RL_Elevator.Get());
}