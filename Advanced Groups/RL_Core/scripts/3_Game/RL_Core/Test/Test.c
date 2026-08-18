// Basic Class to Test any values for equality with == and inform the test manager and print any error messages.
class RLTest<Class T> {

	static void Assert(T value, T expected, int stackOffset = 0, string extraMessage = "") {
		if (value != expected) {
			RLTestManager.OnError(stackOffset);
			RLTestManager.PrintLine("FAIL " + RLLogger.GetCallingMethodFromStack(stackOffset) + " Expected \"" + expected + "\" got \"" + value + "\"" + extraMessage);
		} else {
			RLTestManager.OnPassed(stackOffset);
			RLTestManager.PrintLine("PASS " + RLLogger.GetCallingMethodFromStack(stackOffset) + " Expected \"" + expected + "\" got \"" + value + "\"" + extraMessage);
		}
	}
	
}
// Class to Test arrays for equality. Will first check the size of both arrays. If they don't match, throw an error and then check each element for equality with ==
class RLTestArray<Class T> {

	static void Assert(array<T> value, array<T> expected, int stackOffset = 0, string extraMessage = "") {
		if (value.Count() != expected.Count()) {
			RLTestManager.OnError(stackOffset);
			RLTestManager.PrintLine("FAIL " + RLLogger.GetCallingMethodFromStack(stackOffset) + " Array Size wrong ! Expected \"" + expected.Count() + "\" got \"" + value.Count() + "\"" + extraMessage);
			return;
		}
		string errorStr = "";
		for (int i = 0; i < value.Count(); ++i) {
			if (value[i] != expected[i]) {
				errorStr = errorStr + " (i: " + i + " E: " + expected[i] + " V: " + value[i] + ")";
			}
		}
		if (errorStr != "") {
			RLTestManager.OnError(stackOffset);
			RLTestManager.PrintLine("FAIL " + RLLogger.GetCallingMethodFromStack(stackOffset) + " Arrays missmatch at" + errorStr + extraMessage);
		} else {
			RLTestManager.OnPassed(stackOffset);
			RLTestManager.PrintLine("PASS " + RLLogger.GetCallingMethodFromStack(stackOffset) + " Expected \"" + expected + "\" got \"" + value + "\" Match" + extraMessage);
		}
	}
	
}
class RLTestMulti<Class T> {

	static ref TStringArray results = new TStringArray();
	static bool passed = true;
	
	static void Append(T value, T expected, int stackOffset = 0, string extraMessage = "") {
		if (value != expected) {
			passed = false;
		}
		results.Insert(" Expected \"" + expected + "\" got \"" + value + "\"" + extraMessage);
	}
	
	static void Assert(T value, T expected, int stackOffset = 0, string extraMessage = "") {
		Append(value, expected, stackOffset + 1, extraMessage);
		string expectedStr = "";
		for (int i = 0; i < results.Count(); ++i) {
			expectedStr = expectedStr + results[i];
		}
		if (!passed) {
			RLTestManager.OnError(stackOffset);
			RLTestManager.PrintLine("FAIL " + RLLogger.GetCallingMethodFromStack(stackOffset) + expectedStr);
		} else {
			RLTestManager.OnPassed(stackOffset);
			RLTestManager.PrintLine("PASS " + RLLogger.GetCallingMethodFromStack(stackOffset) + expectedStr);
		}
		passed = true;
		results.Clear();
	}
	
}