/*
 * PteroMods – DayZ Live Map Bridge
 * ──────────────────────────────────────────────────────────────────────────────
 * This script is deployed automatically to your DayZ server's profiles directory
 * by the PteroMods panel (DayZ Manager → Live Map → "Deploy Bridge" button), or
 * by running the panel's install.sh.
 *
 * ── HOW TO ACTIVATE ──────────────────────────────────────────────────────────
 * Add the following line to your mission's init.sqf (server-side only):
 *
 *     if (isServer) then {
 *         execVM "profiles\PteroMods\pteromods_live_map.sqf";
 *     };
 *
 * The script runs as a background loop and writes a JSON snapshot of all online
 * players to:
 *
 *     profiles\PteroMods\live_map_players.json
 *
 * The PteroMods panel reads that file through the Wings file API and displays
 * the player positions on the Live Map page.
 *
 * ── FILE I/O ─────────────────────────────────────────────────────────────────
 * DayZ SQF does not provide a native command for writing arbitrary files, so
 * the bridge relies on a lightweight server-side extension for file output.
 * The following community extensions are supported (tried in order):
 *
 *   • FileIO   (https://github.com/Arkensor/DayZCommunityOfflineMode – extension)
 *   • CF_File  (Community Framework – CF_File.Write)
 *   • RTP      (Real Time Player extension – used by several popular mods)
 *
 * If none of the above is available the snapshot is written to the server RPT
 * log prefixed with "[PteroMods][LiveMap]" and the panel falls back to the
 * ingest API endpoint instead of the file path.
 *
 * Alternatively, configure a bridge secret in DayZ Manager → Settings and use
 * the HTTP ingest endpoint directly:
 *     POST /api/server/{server}/dayz/live-map/ingest
 *     Header: X-DayZ-Bridge-Signature: <hmac-sha256>
 *
 * ── CONFIGURATION ────────────────────────────────────────────────────────────
 * Edit the variables at the top of the "Configuration" section below to adjust
 * the update interval and output path.
 * ─────────────────────────────────────────────────────────────────────────────
 */

if (!isServer) exitWith {};

// ── Configuration ─────────────────────────────────────────────────────────────

// How often (in seconds) the snapshot is written.  5 s is a good default.
PTEROMODS_LIVEMAP_INTERVAL = 5;

// Output path relative to the DayZ server profiles directory (-profiles flag).
// Must match the path the PteroMods panel reads from the container.
PTEROMODS_LIVEMAP_PATH = "PteroMods\live_map_players.json";

// Set to true to write debug messages to the RPT log.
PTEROMODS_LIVEMAP_DEBUG = false;

// ── Helpers ───────────────────────────────────────────────────────────────────

// Escapes the minimal set of characters required inside a JSON string.
PTEROMODS_fnc_jsonEscape = {
    params ["_s"];
    _s = [_s, "\",  "\\\\"] call BIS_fnc_replaceString;
    _s = [_s, """", "\\"""]  call BIS_fnc_replaceString;
    _s = [_s, "/",  "\\/"]   call BIS_fnc_replaceString;
    _s
};

// Serialises a single player object to a JSON string.
PTEROMODS_fnc_playerJson = {
    params ["_unit"];

    private _pos    = getPosASL _unit;
    private _uid    = getPlayerUID _unit;
    private _name   = [name _unit] call PTEROMODS_fnc_jsonEscape;
    private _dir    = round (getDir _unit);
    private _alive  = if (alive _unit) then { "true" } else { "false" };
    private _health = round ((1 - getDamage _unit) * 100 * 10) / 10;
    private _x      = round ((_pos select 0) * 100) / 100;
    private _y      = round ((_pos select 2) * 100) / 100;
    private _z      = round ((_pos select 1) * 100) / 100;

    format [
        "{""steam64"":""%1"",""name"":""%2"",""x"":%3,""y"":%4,""z"":%5,""direction"":%6,""alive"":%7,""health"":%8}",
        _uid, _name, _x, _y, _z, _dir, _alive, _health
    ]
};

// Returns an ISO-8601-like UTC timestamp based on the server's real-time clock.
// Uses the extension clock when CF_Date is available; otherwise derives elapsed
// seconds from serverTime (accurate for uptime, but not for wall-clock UTC).
PTEROMODS_fnc_timestamp = {
    if (isClass (configFile >> "CfgPatches" >> "CF_Root")) then {
        // Community Framework: CF_Date provides a real UTC clock.
        private _dt = [] call CF_Date_fnc_now;
        format ["%1-%2-%3T%4:%5:%6Z",
            _dt select 0,
            ["0", _dt select 1] joinString "" select [count (str (_dt select 1)) - 2],
            ["0", _dt select 2] joinString "" select [count (str (_dt select 2)) - 2],
            ["0", _dt select 3] joinString "" select [count (str (_dt select 3)) - 2],
            ["0", _dt select 4] joinString "" select [count (str (_dt select 4)) - 2],
            ["0", round (_dt select 5)] joinString "" select [count (str (round (_dt select 5))) - 2]
        ]
    } else {
        // Fallback: derive from daytime (server local – not true UTC).
        private _t = daytime * 3600;
        private _h = floor (_t / 3600) mod 24;
        private _m = floor (_t / 60) mod 60;
        private _s = floor (_t mod 60);
        format ["1970-01-01T%1:%2:%3Z",
            ["0", _h] joinString "" select [count (str _h) - 2],
            ["0", _m] joinString "" select [count (str _m) - 2],
            ["0", _s] joinString "" select [count (str _s) - 2]
        ]
    }
};

// Attempts to write _content to _path using available file-I/O extensions.
// Returns true on success, false when no extension is available.
PTEROMODS_fnc_writeFile = {
    params ["_path", "_content"];

    // ── Community Framework CF_File ───────────────────────────────────────────
    if (isClass (configFile >> "CfgPatches" >> "CF_Root")) then {
        [_path, _content] call CF_File_fnc_write;
        true
    } else {

        // ── FileIO extension ("FileIO" callExtension) ─────────────────────────
        private _result = "FileIO" callExtension format ["WriteFile|%1|%2", _path, _content];
        if (_result == "OK" || _result == "1" || _result == "true") exitWith { true };

        // ── RTP extension ─────────────────────────────────────────────────────
        _result = "RTP" callExtension format ["writeFile:%1:%2", _path, _content];
        if (_result == "OK" || _result == "1" || _result == "true") exitWith { true };

        // ── No extension available ────────────────────────────────────────────
        false
    }
};

// ── Main loop ─────────────────────────────────────────────────────────────────

if (PTEROMODS_LIVEMAP_DEBUG) then {
    diag_log "[PteroMods][LiveMap] Bridge started.";
};

while { true } do {
    private _players = allPlayers select { isPlayer _x && getPlayerUID _x != "" };
    private _entries = _players apply { [_x] call PTEROMODS_fnc_playerJson };

    private _snapshot = format [
        "{""updated_at"":""%1"",""map"":""%2"",""players"":[%3]}",
        [] call PTEROMODS_fnc_timestamp,
        worldName,
        _entries joinString ","
    ];

    private _written = [PTEROMODS_LIVEMAP_PATH, _snapshot] call PTEROMODS_fnc_writeFile;

    if (!_written) then {
        // No file I/O extension – emit to RPT as a fallback signal.
        diag_log format ["[PteroMods][LiveMap] %1", _snapshot];
    } else {
        if (PTEROMODS_LIVEMAP_DEBUG) then {
            diag_log format ["[PteroMods][LiveMap] Snapshot written (%1 player(s)).", count _players];
        };
    };

    sleep PTEROMODS_LIVEMAP_INTERVAL;
};
