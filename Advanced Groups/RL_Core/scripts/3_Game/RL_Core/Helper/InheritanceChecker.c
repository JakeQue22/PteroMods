class RLInherit {

	private ref map<string, ref RLInheritEntry> allEntries = new map<string, ref RLInheritEntry>();
	private ref map<string, RLInheritEntry> cache = new map<string, RLInheritEntry>();
	private ref array<RLInheritEntry> roots = new array<RLInheritEntry>();
	
	private static const ref TStringArray searchClasses = {"CfgVehicles", "CfgWeapons", "CfgAmmo", "CfgMagazines", "CfgNonAIVehicles"};
	static ref RLInherit instance;
	
	static RLInherit Get() {
		if (instance == null)
			Load();
		return instance;
	}
	
	private static void Load() {
		instance = new RLInherit();
		instance.Init();
	}
	
	private void Init() {
		int start = TickCount(0);
		CreateRoots();
		foreach (string category : searchClasses) {
			InitCategory(category);
		}
		foreach (RLInheritEntry entry : allEntries)
			entry.Init();
		Print("Loaded " + allEntries.Count() + " Inheritance Class entries in " + (TickCount(start) / 10000) + "ms (Cache Size: " + cache.Count() + ")");
		cache.Clear();
		if (RLLogger.Verbose())
			PrintEntries();
	}
	
	void CreateRoots() {
		string cat = "configFile";
		int childCount = GetGame().ConfigGetChildrenCount(cat);
		for (int i = 0; i < childCount; i++) {
			string childName;
			if (GetGame().ConfigGetChildName(cat, i, childName))
				CreateRoot(cat, childName);
		}
	}
	
	void CreateRoot(string category_, string name_) {
		RLInheritEntry entry = new RLInheritEntry(category_, name_);
		entry.SetInitialized();
		AddRoot(entry);
		allEntries.Insert(entry.classname, entry);
	}
	
	private void PrintEntries() {
		foreach (RLInheritEntry root : roots) {
			root.PrintChildren();
		}
	}
	
	private void InitCategory(string category) {
		int childCount = GetGame().ConfigGetChildrenCount(category);
		for (int i = 0; i < childCount; i++) {
			string childName;
			GetGame().ConfigGetChildName(category, i, childName);
			RLInheritEntry entry = new RLInheritEntry(category, childName);
			allEntries.Insert(entry.classname, entry);
		}
	}
	
	bool IsChildOf(string child, string parent) {
		RLInheritEntry parentEntry = GetEntry(parent);
		if (parentEntry == null) {
			RLLogger.Error("Could not Check If " + child + " is child of " + parent + ", because " + parent + " does not exist", "Core");
			return false;
		}
		return parentEntry.HasChild(child);
	}
	
	bool IsChildOf(string child, TStringArray parents) {
		foreach (string parent : parents) {
			if (IsChildOf(child, parent))
				return true;
		}
		return false;
	}
	
	bool IsChildOf(string child, TStringSet parents) {
		foreach (string parent : parents) {
			if (IsChildOf(child, parent))
				return true;
		}
		return false;
	}
	
	RLInheritEntry GetEntry(string name) {
		RLInheritEntry entry;
		if (cache.Find(name, entry) && entry)
			return entry;
		entry = allEntries.Get(RLStringTools.ToLowerString(name));
		cache.Insert(name, entry);
		return entry;
	}
	
	RLInheritEntry GetEntryNoCache(string name) {
		return allEntries.Get(RLStringTools.ToLowerString(name));
	}
	
	TStringSet GetAllChildren(TStringArray parents, bool lowerCase = false, bool recursive = false, bool includeParentClassname = false, int scope = -1) {
		TStringSet output = new TStringSet();
		foreach (string parent : parents) {
			RLInheritEntry entry = GetEntry(parent);
			if (entry)
				output.InsertSet(entry.GetChildren(lowerCase, recursive, includeParentClassname, scope));
			else
				RLLogger.Error("Could not find Inheritance entry of " + parent, "Core");
		}
		return output;
	}
	
	void AddRoot(RLInheritEntry entry) {
		roots.Insert(entry);
	}
	
}
class RLInheritEntry {

	private bool initialized = false;
	RLInheritEntry parent;
	string category;
	string classname, caseSensitiveClassname;
	ref array<RLInheritEntry> directChildren = new array<RLInheritEntry>();
	int scope = -1;
	
	void RLInheritEntry(string category_, string name_) {
		this.category = category_;
		this.caseSensitiveClassname = name_;
		this.classname = RLStringTools.ToLowerString(name_);
	}
	
	void PrintChildren(string prefix = "") {
		Print(prefix + caseSensitiveClassname);
		foreach (RLInheritEntry child : directChildren) {
			child.PrintChildren(prefix + "\t");
		}
	}
	
	void Init() {
		if (initialized) {
			return;
		}
		SetInitialized();
		string parentName;
		scope = GetGame().ConfigGetInt(category + " " + caseSensitiveClassname + " scope");
		if (!GetGame().ConfigGetBaseName(category + " " + caseSensitiveClassname, parentName)) {
			RLInherit.instance.AddRoot(this);
			return;
		}
		parent = RLInherit.instance.GetEntry(parentName);
		if (!parent) {
			Error("Could not find parent: " + parentName + " of " + caseSensitiveClassname + " in category " + category);
		} else {
			parent.directChildren.Insert(this);
		}
	}
	
	void SetInitialized() {
		initialized = true;
	}
	
	TStringSet GetChildren(bool lowerCase = false, bool recursive = false, bool includeParentClassname = false, int scope_ = -1) {
		TStringSet output = new TStringSet();
		if (includeParentClassname)
			AddToList(output, lowerCase, scope_);
		GetChildrenInternal(lowerCase, recursive, scope_, output);
		return output;
	}
	
	bool IsInherited(string other) {
		RLInheritEntry entry = RLInherit.instance.GetEntry(other);
		if (!entry) {
			Error("Could not find inheritance Entry for Classname " + other);
			return false;
		}
		return entry.HasChildInternal(classname);
	}
	
	bool HasChild(string name) {
		return HasChildInternal(RLStringTools.ToLowerString(name));
	}
	
	private bool HasChildInternal(string name) {
		if (classname == name)
			return true;
		foreach (RLInheritEntry entry : directChildren) {
			if (entry.HasChildInternal(name))
				return true;
		}
		return false;
	}
	
	private void GetChildrenInternal(bool lowerCase, bool recursive, int scope_, TStringSet output) {
		if (recursive) {
			foreach (RLInheritEntry entry : directChildren) {
				entry.AddToList(output, lowerCase, scope_);
				entry.GetChildrenInternal(lowerCase, true, scope_, output);
			}
		} else {
			foreach (RLInheritEntry entry2 : directChildren) {
				entry2.AddToList(output, lowerCase, scope_);
			}
		}
	}
	
	void AddToList(TStringSet output, bool lowerCase, int scope_) {
		if (scope_ != -1 && scope_ != scope)
			return;
		if (lowerCase)
			output.Insert(classname);
		else
			output.Insert(caseSensitiveClassname);
	}
	
	
}