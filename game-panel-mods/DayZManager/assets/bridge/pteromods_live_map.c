/*
 * PteroMods - DayZ Standalone Live Map Bridge (Enforce Script)
 *
 * This script is deployed automatically to your DayZ server's mission directory
 * by the PteroMods panel (DayZ Manager -> Live Map -> "Deploy Bridge" button),
 * or by running the panel's install.sh.
 *
 * HOW TO ACTIVATE
 * The PteroMods panel edits your mission's init.c
 * (mpmissions/dayzOffline.<map>/init.c) in two places.
 *
 * 1. At the very top of the file:
 *
 *        #include "$CurrentDir:mpmissions/dayzOffline.<map>/pteromods_live_map.c";
 *
 * 2. Inside the existing main() function (Enforce Script does not allow a bare
 *    call at file scope - that makes the mission fail to compile):
 *
 *        void main()
 *        {
 *            PteroMods_LiveMap_Init();
 *            ...
 *        }
 *
 * The bridge registers a repeating call-queue callback that writes a JSON
 * snapshot of all online players to:
 *
 *     $profile:PteroMods/live_map_players.json
 *
 * which the PteroMods panel reads through the Wings file API as:
 *     /profiles/PteroMods/live_map_players.json
 *
 * STYLE RULES FOR THIS FILE - DO NOT BREAK THEM
 * The mission script module parses this included file line by line: every
 * statement must be written on a SINGLE line.  A statement that is wrapped
 * over several lines produces
 *     "Missing ';' at the end of line" / "Invalid statement '<token>'"
 * even though the same code is valid inside a PBO-compiled mod.  Keep every
 * call, assignment and string concatenation on one line.
 *
 * SCHEDULING
 * The repeating callback uses the engine call queue:
 *     GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(func fn, int delay_ms, bool repeat)
 * Timer.Run() is deliberately NOT used: its signature is
 *     Run(float duration, Class obj, string fn_name, Param params, bool loop)
 * so the loop flag is the FIFTH argument, not the fourth.
 *
 * FILE I/O
 * The bridge uses DayZ Standalone's native file I/O API (OpenFile / FPrint /
 * CloseFile with the $profile: prefix) - no external extensions or mods are
 * required for the file writing itself.
 *
 * PLAYER IDENTITY
 * GetIdentity().GetId() returns the Bohemia Platform ID (not a Steam64 ID).
 * PteroMods normalises this in the "steam64" field; it is still a stable,
 * unique per-player identifier and works for live-map pin display.
 */

// ---- Configuration ---------------------------------------------------------

// How often (in milliseconds) the player snapshot is written.  5000 = 5 s.
const int PTEROMODS_LIVEMAP_INTERVAL_MS = 5000;

// Output path.  The $profile: prefix resolves to the directory passed to the
// server's -profiles command-line argument.
const string PTEROMODS_LIVEMAP_PATH = "$profile:PteroMods/live_map_players.json";

// Directory that must exist before the output file can be written.
const string PTEROMODS_LIVEMAP_DIR = "$profile:PteroMods";

// ---- Helpers ---------------------------------------------------------------

// Makes a player name safe to embed inside a double-quoted JSON string.
string PteroMods_LiveMap_SanitizePlayerName(string value)
{
    string safe = value;
    safe.Replace("\\", "/");
    safe.Replace("\"", "'");
    safe.Replace("\n", " ");
    return safe;
}

// Rounds to two decimals without relying on any formatting helper.
float PteroMods_LiveMap_Round2(float value)
{
    float scaled = Math.Round(value * 100.0);
    return scaled / 100.0;
}

// ---- Bridge class ----------------------------------------------------------

// Server-side periodic bridge.  One instance is created on server startup by
// PteroMods_LiveMap_Init() and kept alive through the static s_instance ref.
class PteroMods_LiveMapBridge extends Managed
{
    // Static ref prevents the garbage collector from collecting the instance.
    static ref PteroMods_LiveMapBridge s_instance;

    // Guards against registering the repeating callback more than once.
    protected bool m_Scheduled;

    void PteroMods_LiveMapBridge()
    {
        m_Scheduled = false;
    }

    // Registers the repeating snapshot callback.  Safe to call twice: the
    // previous registration is removed first and the guard flag stops a second
    // CallLater() from ever queueing a duplicate.
    void Start()
    {
        if (!GetGame())
            return;

        if (!GetGame().IsServer())
            return;

        if (m_Scheduled)
            return;

        GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Remove(this.WriteSnapshot);
        GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(this.WriteSnapshot, PTEROMODS_LIVEMAP_INTERVAL_MS, true);
        m_Scheduled = true;
    }

    // Cancels the repeating callback (used when the bridge is shut down).
    void Stop()
    {
        if (!GetGame())
            return;

        GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Remove(this.WriteSnapshot);
        m_Scheduled = false;
    }

    // Called by the call queue every PTEROMODS_LIVEMAP_INTERVAL_MS ms.
    // Collects positions of all connected players and writes the JSON snapshot
    // to PTEROMODS_LIVEMAP_PATH.
    void WriteSnapshot()
    {
        if (!GetGame())
            return;

        array<Man> players = new array<Man>;
        GetGame().GetPlayers(players);

        string entries = "";
        bool first = true;

        foreach (Man man : players)
        {
            if (!man)
                continue;

            PlayerIdentity identity = man.GetIdentity();
            if (!identity)
                continue;

            string playerId = identity.GetId();
            if (playerId == "")
                continue;

            string playerName = PteroMods_LiveMap_SanitizePlayerName(identity.GetName());
            if (playerName == "")
                playerName = playerId;

            vector pos = man.GetPosition();
            vector orientation = man.GetOrientation();

            float x = PteroMods_LiveMap_Round2(pos[0]);
            float y = PteroMods_LiveMap_Round2(pos[1]);
            float z = PteroMods_LiveMap_Round2(pos[2]);
            float direction = PteroMods_LiveMap_Round2(orientation[0]);

            // GetHealth("", "") returns 0.0 - 1.0; convert to 0 - 100.
            float health = PteroMods_LiveMap_Round2(man.GetHealth("", "") * 100.0);

            string aliveValue = "false";
            if (man.IsAlive())
                aliveValue = "true";

            string entry = "{";
            entry = entry + "\"steam64\":\"" + playerId + "\",";
            entry = entry + "\"name\":\"" + playerName + "\",";
            entry = entry + "\"x\":" + x.ToString() + ",";
            entry = entry + "\"y\":" + y.ToString() + ",";
            entry = entry + "\"z\":" + z.ToString() + ",";
            entry = entry + "\"direction\":" + direction.ToString() + ",";
            entry = entry + "\"alive\":" + aliveValue + ",";
            entry = entry + "\"health\":" + health.ToString();
            entry = entry + "}";

            if (!first)
                entries = entries + ",";

            entries = entries + entry;
            first = false;
        }

        string worldName = "";
        GetGame().GetWorldName(worldName);

        // updated_at is left empty; the PteroMods panel fills it with the
        // current server time when it reads the snapshot (normalizeUpdatedAt()).
        string snapshot = "{\"updated_at\":\"\",";
        snapshot = snapshot + "\"map\":\"" + worldName + "\",";
        snapshot = snapshot + "\"players\":[" + entries + "]}";

        // Ensure the output directory exists.  OpenFile does not create parent
        // directories, so a missing $profile:PteroMods/ folder would silently
        // discard every snapshot write.
        MakeDirectory(PTEROMODS_LIVEMAP_DIR);

        FileHandle fh = OpenFile(PTEROMODS_LIVEMAP_PATH, FileMode.WRITE);
        if (fh != 0)
        {
            FPrint(fh, snapshot);
            CloseFile(fh);
        }
    }
}

// ---- Activation ------------------------------------------------------------

// Entry point called from init.c.  The PteroMods panel injects
// PteroMods_LiveMap_Init(); into the mission's main() function so that this
// runs once when the mission loads on the server.
void PteroMods_LiveMap_Init()
{
    if (!GetGame())
        return;

    if (!GetGame().IsServer())
        return;

    if (!PteroMods_LiveMapBridge.s_instance)
        PteroMods_LiveMapBridge.s_instance = new PteroMods_LiveMapBridge();

    PteroMods_LiveMapBridge.s_instance.Start();
}
