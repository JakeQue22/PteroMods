class RayLabConfigMover {

	static void MoveFolder(string from, string to) {
		from = TrimSlashes(from);
		to = TrimSlashes(to);
		if (!FileExist(from))
			return;
		string filename = "";
		FileAttr attr;
		FindFileHandle handle = FindFile(from + "/*", filename, attr, FindFileFlags.ALL);
		if (filename != "") {
			if (attr == FileAttr.DIRECTORY) {
				MoveFolder(from + "/" + filename, to + "/" + filename);
			} else {
				MoveFile(from + "/" + filename, to);
			}
			while (FindNextFile(handle, filename, attr)) {
				if (attr == FileAttr.DIRECTORY) {
					MoveFolder(from + "/" + filename, to + "/" + filename);
				} else {
					MoveFile(from + "/" + filename, to);
				}
			}
			CloseFindFile(handle);
		}
	}
	
	static void MoveFile(string from, string toFolder) {
		toFolder = TrimSlashes(toFolder);
		string fromFolder = GetParentFolder(from);
		string fromFile = from.Substring(fromFolder.Length() + 1, from.Length() - 1 - fromFolder.Length());
		string to = toFolder + "/" + fromFile;
		if (!FileExist(to) && FileExist(from)) {
			CreateFolders(toFolder);
			if (CopyFile(from, to)) {
				RLLogger.Debug("Moved File: " + from + " To: " + to, "Core");
				DeleteFile(from);
			}
		}
		DeleteEmptyFolder(GetParentFolder(from));
	}
	
	static void CreateFolders(string folder) {
		folder = TrimSlashes(folder);
		while (folder != "") {
			CreateParentFolders(folder);
			if (!FileExist(folder)) {
				RLLogger.Debug("Create Folder: " + folder, "Core");
				MakeDirectory(folder);
			}
			folder = GetParentFolder(folder);
		}
		
	}
	
	static void CreateParentFolders(string file) {
		string parent = GetParentFolder(file);
		CreateFolders(parent);
	}
	
	static string GetParentFolder(string path) {
		int length = path.Length();
		int index = length - 1;
		while (index >= 0) {
			string part = path.Substring(index, length - index);
			if (part.Contains("/") || part.Contains("\\"))
				break;
			index--;
		}
		if (index >= 0)
			return path.Substring(0, index);
		return "";
	}
	
	static void DeleteEmptyFolder(string path) {
		string filename = "";
		FileAttr attr;
		FindFileHandle handle = FindFile(path + "/*", filename, attr, FindFileFlags.ALL);
		CloseFindFile(handle);
		if (filename == "") {
			DeleteFile(path);
		}
		
		string parent = GetParentFolder(path);
		if (parent != "")
			DeleteEmptyFolder(parent);
		
	}
	
	static string TrimSlashes(string path) {
		if (path.Length() == 0)
			return path;
		if (path.Substring(path.Length() - 1, 1) == "/" || path.Substring(path.Length() - 1, 1) == "\\") {
			return path.Substring(0, path.Length() - 1);
		}
		return path;
	}
	
}