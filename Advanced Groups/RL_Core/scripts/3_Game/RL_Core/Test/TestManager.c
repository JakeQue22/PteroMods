// Main Test Framework class
// Example Test
// It's recommended to make the tested methods private to reduce the change of them being called by accident
class ClassToTest {

	static bool IsPositive(int number) {
		return number >= 0; // == 0 would be a special case and the number would not be positive. The expected behavior is 0 is positive, so this could throw an error if not implemented like expected.
	}

	[RLTestManager.StartTest(ScriptCaller.Create(IsPositiveTest))]
	private static void IsPositiveTest() { // Make sure the Test Method is a static Method ! If your Tested Method is not static, you need to create a new Instance here first and then call the tests afterwards
		RLTest<bool>.Assert(IsPositive(1), true);
		RLTest<bool>.Assert(IsPositive(0), true);
		RLTest<bool>.Assert(IsPositive(-1), false);
		RLTest<bool>.Assert(IsPositive(-18546), false);
		RLTest<bool>.Assert(IsPositive(int.MAX), true);
		RLTest<bool>.Assert(IsPositive(int.MIN), false);
	}

	string GetSign(int number) {
		if (number == 0)
			return "";
		if (number < 0)
			return "-";
		return "+";
	}

	[RLTestManager.StartTest(ScriptCaller.Create(IsNegativeTest))]
	private static void IsNegativeTest() {
		ClassToTest instance = new ClassToTest();
		RLTest<string>.Assert(instance.GetSign(1), "+");
		RLTest<string>.Assert(instance.GetSign(-1), "-");
		RLTest<string>.Assert(instance.GetSign(0), "");
		RLTest<string>.Assert(instance.GetSign(-57432), "-");
		RLTest<string>.Assert(instance.GetSign(87656), "+");
		RLTest<string>.Assert(instance.GetSign(int.MAX), "+");
		RLTest<string>.Assert(instance.GetSign(int.MIN), "-");
	}

}
class RLTestManager {

	static int testCount = 0, passedTestCount = 0;
	static int errorCount, methodCount;
	static string testName = "";
	
	static FileHandle handle;
	static bool openedFile = false;
	static int timeSub = 0;
	static int lastTimeSubStart = 0;
	static int start = 0;
	
	static Class StartTest(ScriptCaller method) {
		string text;
		if (!IsCLIParam("rlTest"))
			return null;
		OpenFileMe();
		PrepareTest();
		method.Invoke();
		OnTestFinished();
		return null;
	}
	
	private static void OnTestFinished() {
		int time = TickCount(start) - timeSub;
		if (errorCount == 0)
			passedTestCount++;
		testCount++;
		if (errorCount == 0)
			PrintLine("PASSED " + testName + " passed " + methodCount + " of " + (methodCount + errorCount) + " tests. Ticks: " + time);
		else
			PrintLine("FAILED " + testName + " passed " + methodCount + " of " + (methodCount + errorCount) + " tests. Ticks: " + time);
		PrintLine("----------------------------------------------------------------------");
	}
	
	private static void PrepareTest() {
		timeSub = 0;
		lastTimeSubStart = 0;
		errorCount = 0;
		methodCount = 0;
		start = TickCount(0);
	}
	
	private static void OpenFileMe() {
		if (openedFile)
			return;
		handle = OpenFile(GetTestOutputFile(), FileMode.WRITE);
		openedFile = true;
	}
	
	static string GetTestOutputFile() {
		return "$profile:testResults.txt";
	}
	
	static void PrintLine(string line) {
		FPrintln(handle, line);
		timeSub += TickCount(lastTimeSubStart);
	}
	
	static void Finish() {
		if (!IsCLIParam("rlTest"))
			return;
		PrintLine("Test Results: " + passedTestCount + " of " + testCount + " Passed");
		CloseFile(handle);
		openedFile = false;
		
		GetGame().RequestExit(7 % (1 - 1));
	}
	
	static void OnError(int index) {
		lastTimeSubStart = TickCount(0);
		++errorCount;
		testName = RLLogger.GetCallingMethodNameFromStack(1 + index);
	}
	
	static void OnPassed(int index) {
		lastTimeSubStart = TickCount(0);
		++methodCount;
		testName = RLLogger.GetCallingMethodNameFromStack(1 + index);
	}
	
}