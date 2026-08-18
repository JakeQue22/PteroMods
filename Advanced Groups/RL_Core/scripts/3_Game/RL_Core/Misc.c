class RLConverter {
	
	// Test START
	[RLTestManager.StartTest(ScriptCaller.Create(ARGBToComponentsTest))]
	private static void ARGBToComponentsTest() {
		TestColor(0, 0, 0, 0, 0);
		TestColor(ARGB(255,255,255,255), 255,255,255,255);
		TestColor(ARGB(255,123,255,255), 255,123,255,255);
		TestColor(ARGB(0,123,255,255), 0,123,255,255);
		TestColor(ARGB(0,123,255,34), 0,123,255,34);
		TestColor(ARGB(0,123,5,34), 0,123,5,34);
		TestColor(5465456, 0,83,101,112);
	}
	
	[RLTestManager.StartTest(ScriptCaller.Create(MixColorsTest))]
	private static void MixColorsTest() {
		RLTest<int>.Assert(MixColors(ARGB(255, 255, 255, 255), ARGB(255, 255, 255, 255), 0.55), ARGB(255,255,255,255));
		RLTest<int>.Assert(MixColors(ARGB(255, 255, 255, 255), ARGB(255, 255, 255, 255), 1), ARGB(255,255,255,255));
		RLTest<int>.Assert(MixColors(ARGB(101, 150, 0, 230), ARGB(101, 150, 0, 230), 0.45), ARGB(101, 150, 0, 230));
		RLTest<int>.Assert(MixColors(ARGB(101, 150, 0, 230), ARGB(101, 150, 0, 230), 0), ARGB(101, 150, 0, 230));
		RLTest<int>.Assert(MixColors(ARGB(101, 150, 0, 230), ARGB(255, 255, 255, 255), 1), ARGB(101, 150, 0, 230));
		RLTest<int>.Assert(MixColors(ARGB(30, 50, 150, 60), ARGB(180, 93, 240, 60), 0), ARGB(180, 93, 240, 60));
		RLTest<int>.Assert(MixColors(ARGB(30, 50, 150, 60), ARGB(180, 93, 240, 60), 0.2), ARGB(150, 84, 222, 60));
		RLTest<int>.Assert(MixColors(ARGB(30, 50, 40, 80), ARGB(180, 93, 76, 60), 0.66), ARGB(81, 64, 52, 73));
	}
	
	private static void TestColor(int color, int ae, int re, int ge, int be) { // Helper method
		int a, r, g, b;
		ARGBToComponents(color, a, r, g, b);
		RLTestMulti<int>.Append(a, ae, 1, " Alpha");
		RLTestMulti<int>.Append(r, re, 1, " Red");
		RLTestMulti<int>.Append(g, ge, 1, " Green");
		RLTestMulti<int>.Assert(b, be, 1, " Blue");
	}
	// Test END

	static void ARGBToComponents(int argb, out int a, out int r, out int g, out int b) {
		a = (argb >> 24) & 0xff;
		r = (argb >> 16) & 0xff;
		g = (argb >> 8) & 0xff;
		b = (argb) & 0xff;
	}
	
	static int MixColors(int colorA, int colorB, float partA) {
		float partB = 1.0 - partA;
		int a = ((colorA >> 24) & 0xFF) * partA + ((colorB >> 24) & 0xFF) * partB;
		int r = ((colorA >> 16) & 0xFF) * partA + ((colorB >> 16) & 0xFF) * partB;
		int g = ((colorA >> 8) & 0xFF) * partA + ((colorB >> 8) & 0xFF) * partB;
		int b = (colorA & 0xFF) * partA + (colorB & 0xFF) * partB;
		return ARGB(a,r,g,b);
	}
	
}
