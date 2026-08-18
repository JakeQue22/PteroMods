class RLAdminsGroup {

	string name; // Name of the permission group
	ref map<string, int> permissions = new map<string, int>(); // List of granted permissions (`"name.of.the.permission": 1`) for granting permissions or (`"name.of.the.permission": 0`) for explicitly revoking a permission, but it's not necessary since all permissions are revoked by default
	
	bool Contains(string permission) {
		return permissions.Contains(permission);
	}
	
	void Insert(string permission, int level) {
		permissions.Insert(permission, level);
	}
	
	int Count() {
		return permissions.Count();
	}
	
	void Clear() {
		permissions.Clear();
	}
	
}