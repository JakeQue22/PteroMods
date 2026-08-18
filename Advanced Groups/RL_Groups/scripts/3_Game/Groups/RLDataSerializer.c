class RLDataSerializer {

	private int index = 0;
	private ref array<ref Param> data = new array<ref Param>();
	
	void Write(Param param) {
		data.Insert(param);
	}
	
	Param Read() {
		if (index >= 0 && index < data.Count())
			return data.Get(index++);
		return null;
	}
		
}
	