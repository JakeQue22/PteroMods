class RLStringTools {
	
	// TEST START
	[RLTestManager.StartTest(ScriptCaller.Create(CompareTest))]
	private static void CompareTest() {
		RLTest<int>.Assert(Comp("", "", true), 0);
		RLTest<int>.Assert(Comp(" ", "", true), 1);
		RLTest<int>.Assert(Comp("", " ", true), -1);
		RLTest<int>.Assert(Comp("hello", "Hello", true), 32);
        RLTest<int>.Assert(Comp("hello", "Hello", false), 0);
        RLTest<int>.Assert(Comp("hello World", "Hello", false), 6);
        RLTest<int>.Assert(Comp("hello World", "Hello", true), 32);
        RLTest<int>.Assert(Comp("Peter", "Peter Meyer", true), -6);
        RLTest<int>.Assert(Comp("Peter", "peter Meyer ali", false), -10);
        RLTest<int>.Assert(Comp("Herr", "Arnold", false), 7);
        RLTest<int>.Assert(Comp("Affe", "Hugo", true), -7);
        RLTest<int>.Assert(Comp("abc", "abcd", true), -1);
        RLTest<int>.Assert(Comp("abcd", "abc", true), 1);
        RLTest<int>.Assert(Comp("abcdef", "abcd", true), 2);
        RLTest<int>.Assert(Comp("abcdef", "aa", true), 1);
        RLTest<int>.Assert(Comp("ABC", "aa", true), -32);
        RLTest<int>.Assert(Comp("ABC", "ABCDE", true), -2);
	}
	
	[RLTestManager.StartTest(ScriptCaller.Create(ToLowerStringTest))]
	private static void ToLowerStringTest() {
		RLTest<string>.Assert(ToLowerString("Hallo"), "hallo");
		RLTest<string>.Assert(ToLowerString("Hallo "), "hallo ");
		RLTest<string>.Assert(ToLowerString(" "), " ");
		RLTest<string>.Assert(ToLowerString(""), "");
		RLTest<string>.Assert(ToLowerString("PETER"), "peter");
		RLTest<string>.Assert(ToLowerString("already lower"), "already lower");
	}
	
	[RLTestManager.StartTest(ScriptCaller.Create(ToLowerStringFromToTest))]
	private static void ToLowerStringFromToTest() {
		RLTest<string>.Assert(ToLowerStringFromTo("CAPSLOCK",0,8), "capslock");
		RLTest<string>.Assert(ToLowerStringFromTo("CAPSLOCK",1,7), "CapslocK");
		RLTest<string>.Assert(ToLowerStringFromTo("CAPSLOCK",4,8), "CAPSlock");
	}
	// TEST END

	static string ToLowerString(string other) {
		string copy = other + "";
		copy.ToLower();
		return copy;
	}
	
	static string ToLowerStringFromTo(string other, int startIndex, int endIndex) {
		int len = other.Length();
		if (endIndex > len)
			endIndex = len;
		string start = other.Substring(0, startIndex);
		string end = other.Substring(endIndex, len - endIndex);
		string mid = other.Substring(startIndex, endIndex - startIndex);
		mid.ToLower();
		return start + mid + end;
	}
	
	static int Comp(string a, string b, bool caseSensitive) {
		int min = a.Length();
		if (b.Length() < min)
			min = b.Length();
		if (caseSensitive) {
			for (int i = 0; i < min; ++i) {
				int ah = a.Get(i).Hash();
				int bh = b.Get(i).Hash();
				if (ah != bh)
					return ah - bh;
			}
		} else {
			for (i = 0; i < min; ++i) {
				string al = ToLowerString(a.Get(i));
				string bl = ToLowerString(b.Get(i));
				ah = al.Hash();
				bh = bl.Hash();
				if (ah != bh)
					return ah - bh;
			}
		}
		return a.Length() - b.Length();
	}
	
}