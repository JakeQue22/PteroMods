/*
 * PteroMods – DayZ Standalone Live Map Bridge (EnfScript)
 * ──────────────────────────────────────────────────────────────────────────────
 * This script is deployed automatically to your DayZ server's mission directory
 * by the PteroMods panel (DayZ Manager → Live Map → "Deploy Bridge" button), or
 * by running the panel's install.sh.
 *
 * ── HOW TO ACTIVATE ──────────────────────────────────────────────────────────
 * The PteroMods panel automatically appends the following block to your mission's
 * init.c (mpmissions/dayzOffline.chernarusplus/init.c):
 *
 *     #include "pteromods_live_map.c"
 *     PteroMods_LiveMap_Init();
 *
 * The bridge registers a periodic callback that writes a JSON snapshot of all
 * online players to:
 *
 *     $profile:PteroMods/live_map_players.json
 *
 * which the PteroMods panel reads through the Wings file API as:
 *     /profiles/PteroMods/live_map_players.json
 *
 * ── FILE I/O ─────────────────────────────────────────────────────────────────
 * The bridge uses DayZ Standalone's native file I/O API (OpenFile / FPrint /
 * CloseFile with the $profile: prefix) — no external extensions or mods are
 * required for the file writing itself.
 *
 * ── PLAYER IDENTITY ──────────────────────────────────────────────────────────
 * GetIdentity().GetId() returns the Bohemia Platform ID (not a Steam64 ID).
 * PteroMods normalises this in the "steam64" field; it is still a stable,
 * unique per-player identifier and works for live-map pin display.
 *
 * ── CONFIGURATION ────────────────────────────────────────────────────────────
 * Edit the constants in the Configuration section below to adjust the
 * update interval and output path.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Configuration ─────────────────────────────────────────────────────────────

// How often (in milliseconds) the player snapshot is written.  5000 = 5 s.
const int PTEROMODS_LIVEMAP_INTERVAL_MS = 5000;

// Output path.  The $profile: prefix resolves to the directory passed to the
// server's -profiles command-line argument.
const string PTEROMODS_LIVEMAP_PATH = "$profile:PteroMods/live_map_players.json";

// ── Bridge class ──────────────────────────────────────────────────────────────

/**
 * Server-side periodic bridge.  One instance is created on server startup by
 * PteroMods_LiveMap_Init() and kept alive through the static s_instance ref.
 */
class PteroMods_LiveMapBridge : Managed
{
    // Static ref prevents the garbage collector from collecting the instance.
    static ref PteroMods_LiveMapBridge s_instance;

    // ── Constructor ───────────────────────────────────────────────────────────
    void PteroMods_LiveMapBridge()
    {
        if ( GetGame() && GetGame().IsServer() )
        {
            GetGame().GetCallQueue( CALL_CATEGORY_GAMEPLAY ).CallLater(
                WriteSnapshot,
                PTEROMODS_LIVEMAP_INTERVAL_MS,
                true    // repeat: run every PTEROMODS_LIVEMAP_INTERVAL_MS ms
            );
        }
    }

    // ── Snapshot writer ───────────────────────────────────────────────────────

    /**
     * Called on a timer.  Collects positions of all connected players and
     * writes the JSON snapshot to PTEROMODS_LIVEMAP_PATH.
     */
    void WriteSnapshot()
    {
        if ( !GetGame() )
            return;

        array<Man> players = new array<Man>();
        GetGame().GetPlayers( players );

        string entries = "";
        bool first = true;

        foreach ( Man man : players )
        {
            if ( !man )
                continue;

            PlayerIdentity identity = man.GetIdentity();
            if ( !identity )
                continue;

            string playerId   = identity.GetId();
            string playerName = identity.GetName();

            if ( playerId == "" || playerName == "" )
                continue;

            vector pos   = man.GetPosition();
            float  yaw   = man.GetOrientation()[0];
            bool   alive = man.IsAlive();

            // GetHealth( "", "" ) returns 0.0 – 1.0; convert to 0 – 100.
            float health = Math.Round( man.GetHealth( "", "" ) * 10000.0 ) / 100.0;

            // Minimal JSON string escaping.
            playerName = playerName.Replace( "\\", "\\\\" );
            playerName = playerName.Replace( "\"", "\\\"" );

            string entry = string.Format(
                "{\"steam64\":\"%1\",\"name\":\"%2\",\"x\":%3,\"y\":%4,\"z\":%5,\"direction\":%6,\"alive\":%7,\"health\":%8}",
                playerId,
                playerName,
                Math.Round( pos[0] * 100.0 ) / 100.0,
                Math.Round( pos[1] * 100.0 ) / 100.0,
                Math.Round( pos[2] * 100.0 ) / 100.0,
                Math.Round( yaw   * 100.0 ) / 100.0,
                alive ? "true" : "false",
                health
            );

            if ( !first )
                entries += ",";
            entries += entry;
            first = false;
        }

        string worldName = "";
        if ( GetGame().GetWorld() )
            worldName = GetGame().GetWorld().GetName();

        // updated_at is left empty; the PteroMods panel fills it with the
        // current server time when it reads the snapshot (see normalizeUpdatedAt()).
        string snapshot = string.Format(
            "{\"updated_at\":\"\",\"map\":\"%1\",\"players\":[%2]}",
            worldName,
            entries
        );

        FileHandle fh = OpenFile( PTEROMODS_LIVEMAP_PATH, FileMode.WRITE );
        if ( fh != 0 )
        {
            FPrint( fh, snapshot );
            CloseFile( fh );
        }
    }
}

// ── Activation ────────────────────────────────────────────────────────────────

/**
 * Entry point called from init.c.
 * The PteroMods panel appends  PteroMods_LiveMap_Init();  to init.c so that
 * this function is invoked once when the mission loads on the server.
 */
void PteroMods_LiveMap_Init()
{
    if ( !GetGame() || !GetGame().IsServer() )
        return;

    if ( !PteroMods_LiveMapBridge.s_instance )
        PteroMods_LiveMapBridge.s_instance = new PteroMods_LiveMapBridge();
}
