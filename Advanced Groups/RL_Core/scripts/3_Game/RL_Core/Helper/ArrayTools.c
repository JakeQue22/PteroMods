class RLArrayTools<Class T1> {

	static array<T1> ToArray(set<T1> set_) {
		array<T1> arr = new array<T1>();
		foreach (T1 entry : set_)
			arr.Insert(entry);
		return arr;
	}
	
	static set<T1> ToSet(array<T1> arr_) {
		set<T1> set_ = new set<T1>();
		foreach (T1 entry : arr_)
			set_.Insert(entry);
		return set_;
	}
	
	static void InsertAll(array<T1> to, set<T1> from) {
		foreach (string entry : from)
			to.Insert(entry);
	}
	
	static void InsertAll(set<T1> to, array<T1> from) {
		foreach (string entry : from)
			to.Insert(entry);
	}
	
	static void RemoveDuplicate(array<T1> arr) {
		array<T1> inserted = new array<T1>();
		for (int i = 0; i < arr.Count(); i++) {
			if (inserted.Find(arr[i]) == -1) {
				inserted.Insert(arr[i]);
			} else {
				arr.Remove(i);
				i--;
			}
		}
	}
	
}
class RLStringArrayTools {
	
	// TEST START
	[RLTestManager.StartTest(ScriptCaller.Create(FindBestTest))]
	private static void FindBestTest() {
		RLTest<string>.Assert(FindBestMatch({"abbc", "abc", "ac", "bdf", "fgh"}, "a", false), "abbc");
		RLTest<string>.Assert(FindBestMatch(new TStringArray(), "a", false), "");
		RLTest<string>.Assert(FindBestMatch({"abbc", "abc", "ac", "bdf", "fgh"}, "b", false), "bdf");
		RLTest<string>.Assert(FindBestMatch({"abbc", "abc", "ac", "bdf", "fgh"}, "ab", false), "abbc");
		RLTest<string>.Assert(FindBestMatch({"abc", "abcdef", "ac", "bdf", "fgh"}, "abc", false), "abc");
		RLTest<string>.Assert(FindBestMatch({"abc", "abcdef", "ac", "bdf", "fgh"}, "abcd", false), "abcdef");
		RLTest<string>.Assert(FindBestMatch({"abc", "abcdef", "ac", "bdf", "fgh"}, "f", false), "fgh");
		RLTest<string>.Assert(FindBestMatch({"abc", "abcdef", "ac", "acc", "bdf", "fgh"}, "", false), "abc");
		RLTest<string>.Assert(FindBestMatch({"abc", "abcdef", "ac", "ba", "bc", "bdfff", "bdf", "fgh"}, "z", false), "fgh");
		RLTest<string>.Assert(FindBestMatch({"abc", "abcdef", "ac", "ba", "bc", "bdfff", "fgh"}, "z", false), "fgh");
		RLTest<string>.Assert(FindBestMatch({"abc", "abcdef", "ac", "bdf", "fgh"}, "A", true), "abc");
		RLTest<string>.Assert(FindBestMatch({"ABC", "abcdef", "ac", "bdf", "fgh"}, "A", true), "ABC");
		RLTest<string>.Assert(FindBestMatch({"ABCDE", "ABC", "abcdef", "ac", "bdf", "fgh"}, "ABC", true), "ABCDE");
		RLTest<string>.Assert(FindBestMatch({"ABC", "abcdef", "ac", "bdf", "fgh"}, "@BCDE", true), "ABC");
		RLTest<string>.Assert(FindBestMatch({"ABC", "abcdef", "ac", "bdf", "fgh"}, "a", true), "abcdef");
		RLTest<string>.Assert(FindBestMatch({"ABC", "abcdef", "ac", "bdf", "fgh"}, "AA", true), "ABC");
	}
	
	[RLTestManager.StartTest(ScriptCaller.Create(FindBestMatchStartingTest))]
	private static void FindBestMatchStartingTest() {
		RLTest<string>.Assert(FindBestMatchStarting({"abbc", "abc", "ac", "bdf", "fgh"}, "a", false), "abbc");
		RLTest<string>.Assert(FindBestMatchStarting(new TStringArray(), "a", false), "");
		RLTest<string>.Assert(FindBestMatchStarting({"abbc", "abc", "ac", "bdf", "fgh"}, "b", false), "bdf");
		RLTest<string>.Assert(FindBestMatchStarting({"abbc", "abc", "ac", "bdf", "fgh"}, "ab", false), "abbc");
		RLTest<string>.Assert(FindBestMatchStarting({"abc", "abcdef", "ac", "bdf", "fgh"}, "abc", false), "abc");
		RLTest<string>.Assert(FindBestMatchStarting({"abc", "abcdef", "ac", "bdf", "fgh"}, "abcd", false), "abcdef");
		RLTest<string>.Assert(FindBestMatchStarting({"abc", "abcdef", "ac", "bdf", "fgh"}, "f", false), "fgh");
		RLTest<string>.Assert(FindBestMatchStarting({"abc", "abcdef", "ac", "acc", "bdf", "fgh"}, "", false), "abc");
		RLTest<string>.Assert(FindBestMatchStarting({"abc", "abcdef", "ac", "ba", "bc", "bdfff", "bdf", "fgh"}, "z", false), "");
		RLTest<string>.Assert(FindBestMatchStarting({"abc", "abcdef", "ac", "ba", "bc", "bdfff", "fgh"}, "z", false), "");
		RLTest<string>.Assert(FindBestMatchStarting({"abc", "abcdef", "ac", "bdf", "fgh"}, "A", true), "");
		RLTest<string>.Assert(FindBestMatchStarting({"ABC", "abcdef", "ac", "bdf", "fgh"}, "A", true), "ABC");
		RLTest<string>.Assert(FindBestMatchStarting({"ABCDE", "ABC", "abcdef", "ac", "bdf", "fgh"}, "ABC", true), "ABCDE");
		RLTest<string>.Assert(FindBestMatchStarting({"ABC", "abcdef", "ac", "bdf", "fgh"}, "@BCDE", true), "");
		RLTest<string>.Assert(FindBestMatchStarting({"ABC", "abcdef", "ac", "bdf", "fgh"}, "a", true), "abcdef");
		RLTest<string>.Assert(FindBestMatchStarting({"ABC", "abcdef", "ac", "bdf", "fgh"}, "ac", true), "ac");
		RLTest<string>.Assert(FindBestMatchStarting({"ABC", "abcdef", "ac", "bdf", "fgh"}, "AA", true), "");
	}
	
	[RLTestManager.StartTest(ScriptCaller.Create(QuickSortTest))]
	private static void QuickSortTest() {
		TStringArray arr = { "a", "c", "b", "d", "ab", "cd" };
		RLTestArray<string>.Assert(QuickSort( arr, true ), {"a", "ab", "b", "c", "cd", "d"});
		RLTestArray<string>.Assert(QuickSort( arr, false ), {"a", "ab", "b", "c", "cd", "d"});
		arr = {"A", "b", "hallo", "test", "Proc", "", " ", "empty"};
		RLTestArray<string>.Assert(QuickSort(arr, true), {"", " ", "A", "Proc", "b", "empty", "hallo", "test"});
		RLTestArray<string>.Assert(QuickSort(arr, false), {"", " ", "A", "b", "empty", "hallo", "Proc", "test"});
		arr = {"abc", "abbc", "ac", "bdf", "fgh"};
		RLTestArray<string>.Assert(QuickSort(arr, false), {"abbc", "abc", "ac", "bdf", "fgh"});
		arr = {"abc", "abcdef", "ac", "bdf", "fgh"};
		RLTestArray<string>.Assert(QuickSort(arr, false), {"abc", "abcdef", "ac", "bdf", "fgh"});
		arr = {"ABC", "abcdef", "ac", "bdf", "fgh"};
		RLTestArray<string>.Assert(QuickSort(arr, false), {"ABC", "abcdef", "ac", "bdf", "fgh"});
	}
	
	[RLTestManager.StartTest(ScriptCaller.Create(IsInLowerListTest))]
	private static void IsInLowerListTest() {
		TStringArray lowerList = {"abd", "fhjds", "hey", "", "peter"};
		RLTest<bool>.Assert(IsInLowerList(lowerList, "hey"), true);
		RLTest<bool>.Assert(IsInLowerList(lowerList, "heyy"), false);
		RLTest<bool>.Assert(IsInLowerList(lowerList, "HEY"), true);
		RLTest<bool>.Assert(IsInLowerList(lowerList, " "), false);
		RLTest<bool>.Assert(IsInLowerList(lowerList, ""), true);
		RLTest<bool>.Assert(IsInLowerList(lowerList, "Peter "), false);
		RLTest<bool>.Assert(IsInLowerList(lowerList, "Peter"), true);
		RLTest<bool>.Assert(IsInLowerList(lowerList, "peter"), true);
	}
	// TEST END
	
	static bool IsInLowerList(TStringArray arr, string classname) {
		string lower = classname + "";
		lower.ToLower();
		return arr.Find(lower) != -1;
	}
	
	static TStringArray QuickSort(TStringArray arr, bool caseSensitive) {
		TStringArray copy = new TStringArray();
		copy.InsertArray(arr);
		QuickSort(copy, caseSensitive, 0, copy.Count() - 1);
		return copy;
	}
	
	private static void QuickSort(TStringArray arr, bool caseSensitive, int begin, int end) {
		if (begin < end) {
	        int partitionIndex = Partition(arr, begin, end, caseSensitive);
	
	        QuickSort(arr, caseSensitive, begin, partitionIndex - 1);
	        QuickSort(arr, caseSensitive, partitionIndex + 1, end);
	    }
	}
	
	private static int Partition(TStringArray arr, int begin, int end, bool caseSensitive) {
	    string pivot = arr[end];
	    int i = (begin - 1);
	
	    for (int j = begin; j < end; j++) {
	        if (RLStringTools.Comp(arr[j], pivot, caseSensitive) < 0) {
	            ++i;
	
	            string swapTemp = arr[i];
	            arr[i] = arr[j];
	            arr[j] = swapTemp;
	        }
	    }
	
	    swapTemp = arr[i + 1];
	    arr[i + 1] = arr[end];
	    arr[end] = swapTemp;
	
	    return i + 1;
	}
	
	static string FindBestMatch(TStringArray arr, string text, bool caseSensitive) {
		if (arr.Count() == 0)
			return "";
		int min = 0, max = arr.Count() - 1;
		while (min < max) {
			int mid = min + ((max - min) / 2);
			string midElement = arr.Get(mid);
			int comp = RLStringTools.Comp(midElement, text, caseSensitive);
			if (comp < 0) {
				min = mid + 1;
			} else {
				max = mid;
			}
		}
		return arr.Get(min);
	}
	
	static string FindBestMatchStarting(TStringArray arr, string text, bool caseSensitive) {
		string best = FindBestMatch(arr, text, caseSensitive);
		string bestOriginal = best;
		if (!caseSensitive) {
			best = RLStringTools.ToLowerString(best);
			text = RLStringTools.ToLowerString(text);
		}
		if (best.IndexOf(text) == 0)
			return bestOriginal;
		return "";
			
	}
	
}