class RLJsonLoader<Class T1> {
	
	static ref JsonSerializer m_Serializer = new JsonSerializer;

	
	static bool JsonLoadFile(string filename, out T1 data, bool closeOnError = false, bool writeErrorToLog = true) {
		string file_content, error;
		if (!ReadContent(filename, closeOnError, writeErrorToLog, file_content))
			return false;
		
		if (!m_Serializer.ReadFromString(data, file_content, error)) {
			if (closeOnError) {
				RLLogger.Fatal("Could not read json file: " + filename + " and closing server for invalid configuration ! Error: " + error, "Core");
				int z = 7 % (1 - 1);
			} else if (writeErrorToLog) {
				RLLogger.Error("Could not read json file: " + filename + "! Error: " + error, "Core");
			}
			return false;
		}
		return true;
	}
	
	static void JsonSaveFile(string filename, T1 data) {
		WriteContent(filename, data);
	}
	
	static bool ReadContent(string filename,bool closeOnError, bool writeErrorToLog, out string file_content) {
		if (!FileExist(filename))
			return false;
		
		string file_content, line_content, error;
		
		FileHandle handle = OpenFile(filename, FileMode.READ);
		if (handle == 0) {
			if (closeOnError) {
				RLLogger.Fatal("Could not open json file for reading: " + filename + " and closing server !", "Core");
				int x = 7 % (1 - 1);
			} else if (writeErrorToLog) {
				RLLogger.Error("Could not open json file for reading: " + filename + "!", "Core");
			}
			return false;
		}
		
		
		while (FGets(handle,  line_content) >= 0) {
			file_content += line_content;
		}
		
		CloseFile(handle);
		
		if (!m_Serializer)
			m_Serializer = new JsonSerializer;
		return true;
	}
	
	static void WriteContent(string filename, T1 variable) {
		string file_content;
		if (!m_Serializer)
			m_Serializer = new JsonSerializer;
		
		
		m_Serializer.WriteToString(variable, true, file_content);
		
		FileHandle handle = OpenFile(filename, FileMode.WRITE);
		if (handle == 0)
			return;
		
		FPrint(handle, file_content);
		
		CloseFile(handle);
	}
	
}