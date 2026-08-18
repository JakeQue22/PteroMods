class RLColorConfig {

	int a, r, g, b;
	
	int GetColorARGB() {
		return ARGB(a, r, g, b);
	}
	
	static RLColorConfig Init(int a1, int r1, int g1, int b1) {
		RLColorConfig cfg = new RLColorConfig();
		cfg.a = a1;
		cfg.r = r1;
		cfg.g = g1;
		cfg.b = b1;
		return cfg;
	}
	
	void WriteToCtx(ParamsWriteContext ctx) {
		ctx.Write(a);
		ctx.Write(r);
		ctx.Write(g);
		ctx.Write(b);
	}
	
	bool ReadFromCtx(ParamsReadContext ctx) {
		if (!ctx.Read(a))
			return false;
		if (!ctx.Read(r))
			return false;
		if (!ctx.Read(g))
			return false;
		if (!ctx.Read(b))
			return false;
		return true;
	}
	
}