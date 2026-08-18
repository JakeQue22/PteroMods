class RLWidget_Linked_Map_Handler_String_Bool : RLWidget_Linked_Map_Handler {

	ref map<string, bool> map_;
	
	void RLWidget_Linked_Map_Handler_Gen(map<string, bool> mapVal) {
		map_ = mapVal;
	}
	
	override TStringArray GetKeys() {
		return map_.GetKeyArray();
	}
	
	override string GetMapKey(int index) {
		return map_.GetKey(index);
	}
	
	override string GetMapValue(int index) {
		return map_.GetElement(index).ToString();
	}
	
	override void SetMapValue(int index, string value) {
		if (index < 0 || index > map_.Count())
			return;
		string key_ GetMapKey(index);
		map_.Set(key_, value == "true" || value == "1");
	}
	
}