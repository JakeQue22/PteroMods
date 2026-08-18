class RLWidget_Linked_Map : RLWidget_Linker {
	
	private ref RLWidget_Linked_Map_Handler mapHandler;
	private Widget listWidget, valueWidget;

	void RLWidget_Linked_Map(string varPath_, Widget list, Widget value, RLWidget_Linked_Map_Handler handler, Class parent_) {
		SetVarPath(varPath_);
		this.mainParent = parent_;
		this.mapHandler = handler;
		this.listWidget = list;
		this.valueWidget = value;
		this.reloadTrigger = list;
	}
	
}