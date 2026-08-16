/*
 * PteroMods - DayZ Standalone Live Map Bridge (Enforce Script)
 *
 * Deployed automatically to the mission directory by the PteroMods panel
 * (DayZ Manager -> Live Map -> Deploy Bridge), or by the panel install.sh.
 *
 * ACTIVATION
 * The panel wires this file into the mission init.c in two places: an include
 * directive at the top of init.c, and a PteroMods_LiveMap_Init(); call placed
 * inside the existing main() function. Enforce Script does not allow a bare
 * call at file scope, so the call must live inside main().
 *
 * OUTPUT
 * A JSON snapshot of all online players is written every 5 seconds to
 *     $profile:PteroMods/live_map_players.json
 * which the PteroMods panel reads through the Wings file API as
 *     /profiles/PteroMods/live_map_players.json
 *
 * WHY THE JSON IS NOT BUILT BY HAND
 * Mission-folder scripts pulled in by init.c are read by the engine CParser,
 * not by the mod/PBO Enforce compiler. The CParser does not accept a
 * backslash-escaped quote inside a string literal: it ends the literal at that
 * quote and then reports
 *     CParser: quoted string not closed
 * followed by cascading errors, including a bogus Can-not-find-file report for
 * this module. There is also no stdlib function that turns a character code
 * back into a character, so a quote character cannot be synthesised either.
 * Therefore this file contains NO backslash escape sequences at all, and the
 * JSON is produced by the engine serialiser through JsonFileLoader, which does
 * all quoting and escaping natively. As a side effect player names with quotes,
 * backslashes or newlines are escaped correctly by the engine.
 *
 * The same CParser is line oriented: an expression wrapped across several
 * source lines produced Missing-semicolon and Invalid-statement errors here, so
 * every statement below is written on a single line.
 *
 * VERIFIED API USED BY THIS FILE (DayZ script sources)
 *   3_game/global/game.c      ScriptCallQueue GetCallQueue(int call_category)
 *   3_game/global/game.c      proto void GetWorldName(out string world_name)
 *   3_game/tools/tools.c      const int CALL_CATEGORY_SYSTEM = 0
 *   2_gamelib/tools.c         proto void CallLater(func fn, int delay = 0,
 *                                                  bool repeat = false, ...)
 *   2_gamelib/tools.c         proto void Remove(func fn)
 *   1_core/proto/ensystem.c   proto native bool MakeDirectory(string name)
 *   3_game/tools/jsonfileloader.c
 *                             static void JsonSaveFile(string filename, T data)
 *   4_world/entities/entityai.c
 *                             EntityAI FindAttachmentBySlotName(string slot_name)
 *   3_game/entities/humaninventory.c
 *                             EntityAI GetEntityInHands()
 *   3_game/global/object.c    string GetType()
 *
 * Timer.Run is deliberately not used. Its signature is
 *     Run(float duration, Managed obj, string fn_name, Param params = NULL,
 *         bool loop = false)
 * so the loop flag is the fifth argument; passing it fourth lands in the Param
 * slot and the callback never repeats.
 *
 * PLAYER IDENTITY
 * identity.GetPlainId() returns the Steam64 ID, while identity.GetId() returns
 * the Bohemia Platform UID (DayZ UID).  PteroMods stores Steam64 in steam64
 * and stores the DayZ UID in player_uid.
 *
 * HEALTH
 * GetHealth("", "") returns the character health on a 0 – 100 scale.  The
 * panel expects the same 0 – 100 scale, so no scaling is applied.
 *
 * INVENTORY
 * Top-level equipped items are collected from common attachment slots plus the
 * item held in the player's hands.  The slot name and item class name are
 * written.  For containers that can hold cargo (Back = backpack, Hips = belt /
 * holster, Legs = pants, Body = vest), items stored inside are also enumerated
 * one level deep and placed in the parent item's "contents" array.
 * PteroMods_LiveMap_CollectContainerContents uses:
 *   EntityAI.GetInventory()                  → GameInventory
 *   GameInventory.GetAttachmentSlotsCount()   → attachment slot count
 *   GameInventory.GetAttachmentFromIndex(int) → item in attachment slot
 *   GameInventory.GetCargo()                  → CargoBase (may be NULL)
 *   CargoBase.GetItemCount()                  → total cargo item count
 *   CargoBase.GetItem(int index)               → item at cargo index
 * FindAttachmentBySlotName returns null for empty slots and is safe to call on
 * every tick.
 */

// ---- Configuration ---------------------------------------------------------

// How often, in milliseconds, the player snapshot is written. 5000 = 5 s.
const int PTEROMODS_LIVEMAP_INTERVAL_MS = 5000;

// Output path. The $profile: prefix resolves to the directory passed to the
// server -profiles command line argument.
const string PTEROMODS_LIVEMAP_PATH = "$profile:PteroMods/live_map_players.json";

// Directory that must exist before the output file can be written.
const string PTEROMODS_LIVEMAP_DIR = "$profile:PteroMods";

// ---- Serialisable snapshot model -------------------------------------------

// Member names become the JSON keys, so they match what the PteroMods panel
// reads in DayZLiveMapService::normalizePlayers().

// One entry per top-level equipped attachment slot (or item inside a container).
class PteroMods_LiveMapItem
{
    string slot;       // Slot name (e.g. "Body", "Back", "Hands")
    string className;  // Item class name (e.g. "CivilianCoat_Black")
    // Items stored inside this container (backpack cargo, vest pockets, pants pockets …).
    // Only one level deep: contents of contents are not serialised.
    ref array<ref PteroMods_LiveMapItem> contents;

    void PteroMods_LiveMapItem()
    {
        contents = new array<ref PteroMods_LiveMapItem>;
    }
}

class PteroMods_LiveMapPlayer
{
    string steam64;     // Steam64 ID from identity.GetPlainId()
    string player_uid;  // Bohemia Platform UID from identity.GetId()
    string name;
    float x;
    float y;
    float z;
    float direction;
    bool alive;
    float health;       // 0 – 100 scale
    bool in_vehicle;
    string vehicle_class;
    ref array<ref PteroMods_LiveMapItem> inventory;  // top-level equipped items

    void PteroMods_LiveMapPlayer()
    {
        in_vehicle = false;
        vehicle_class = "";
        inventory = new array<ref PteroMods_LiveMapItem>;
    }
}

// updated_at is intentionally left empty. The panel substitutes the file
// modification time when it reads the snapshot, in normalizeUpdatedAt().
class PteroMods_LiveMapSnapshot
{
    string updated_at;
    string mapName;
    ref array<ref PteroMods_LiveMapPlayer> players;

    void PteroMods_LiveMapSnapshot()
    {
        updated_at = "";
        mapName = "";
        players = new array<ref PteroMods_LiveMapPlayer>;
    }
}

// ---- Helpers ---------------------------------------------------------------

// Rounds to two decimals to keep the snapshot small.
float PteroMods_LiveMap_Round2(float value)
{
    float scaled = Math.Round(value * 100.0);
    return scaled / 100.0;
}

// Enumerates items stored inside a container (backpack, vest, pants pocket, belt …)
// and appends them to slotItem.contents.  Covers:
//   - direct cargo (food, ammo, clothes stored in a backpack's main compartment)
//   - sub-container cargo (items inside pants pockets, vest internal pockets, etc.)
//   - attachment-slot items that are not sub-containers (weapon rail attachments)
//
// Two levels of nesting are collected to keep the snapshot bounded but still reach
// items inside pants pockets, which are attached as pocket entities whose cargo
// holds the actual items (rather than being stored in the pants' own cargo).
//
// Attachment handling:
//   If an attachment has its own cargo (GetCargo() != null), it is a sub-container
//   (e.g. a pants pocket or vest pouch).  Its cargo items are collected directly.
//   If GetCargo() is null the attachment is a plain item (optic, suppressor, mag)
//   and is reported as-is.
void PteroMods_LiveMap_CollectContainerContents(EntityAI container, PteroMods_LiveMapItem slotItem)
{
    if (!container)
        return;

    GameInventory inv = container.GetInventory();
    if (!inv)
        return;

    // Attachment-slot children.  For each attachment we check whether it is a
    // sub-container (has its own cargo).  If so we collect its cargo items
    // directly so the panel sees the actual items rather than the pocket entity.
    // If it has no cargo it is a real item (optic, suppressor, etc.) and is
    // added as an attachment entry.
    int attachCount = inv.GetAttachmentSlotsCount();
    for (int ai = 0; ai < attachCount; ai++)
    {
        EntityAI attached = inv.GetAttachmentFromIndex(ai);
        if (!attached)
            continue;

        GameInventory attachInv = attached.GetInventory();
        if (attachInv)
        {
            CargoBase attachCargo = attachInv.GetCargo();
            if (attachCargo)
            {
                // Sub-container (e.g. pants pocket, vest pouch): collect its items.
                int subItemCount = attachCargo.GetItemCount();
                for (int si = 0; si < subItemCount; si++)
                {
                    EntityAI subItem = EntityAI.Cast(attachCargo.GetItem(si));
                    if (!subItem)
                        continue;
                    PteroMods_LiveMapItem subContainerItem = new PteroMods_LiveMapItem();
                    subContainerItem.slot = slotItem.slot + ".cargo";
                    subContainerItem.className = subItem.GetType();
                    slotItem.contents.Insert(subContainerItem);
                }
                continue;
            }
        }

        // Plain attached item (optic, suppressor, magazine, etc.).
        PteroMods_LiveMapItem attachSub = new PteroMods_LiveMapItem();
        attachSub.slot = slotItem.slot + ".attach";
        attachSub.className = attached.GetType();
        slotItem.contents.Insert(attachSub);
    }

    // Direct cargo items (the main case for backpacks and holsters).
    // DayZ's CargoBase.GetItemCount() returns the number of distinct item entries;
    // each is accessed via GetItem(index) using a zero-based index.
    CargoBase cargo = inv.GetCargo();
    if (!cargo)
        return;

    int itemCount = cargo.GetItemCount();
    for (int ci = 0; ci < itemCount; ci++)
    {
        EntityAI cargoItem = EntityAI.Cast(cargo.GetItem(ci));
        if (!cargoItem)
            continue;
        PteroMods_LiveMapItem cargoSubItem = new PteroMods_LiveMapItem();
        cargoSubItem.slot = slotItem.slot + ".cargo";
        cargoSubItem.className = cargoItem.GetType();
        slotItem.contents.Insert(cargoSubItem);
    }
}

// ---- Bridge ----------------------------------------------------------------

// Server side periodic bridge. A single instance is created on server startup
// by PteroMods_LiveMap_Init() and kept alive through the static s_instance ref.
class PteroMods_LiveMapBridge
{
    static ref PteroMods_LiveMapBridge s_instance;

    // Guards against registering the repeating callback more than once.
    protected bool m_Scheduled;

    void PteroMods_LiveMapBridge()
    {
        m_Scheduled = false;
    }

    // Registers the repeating snapshot callback. Safe to call repeatedly: any
    // previous registration for this exact function reference is removed first,
    // and the guard flag stops a second CallLater from queueing a duplicate.
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

    // Cancels the repeating callback.
    void Stop()
    {
        if (!GetGame())
            return;

        GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Remove(this.WriteSnapshot);
        m_Scheduled = false;
    }

    // Invoked by the call queue every PTEROMODS_LIVEMAP_INTERVAL_MS ms.
    void WriteSnapshot()
    {
        if (!GetGame())
            return;

        PteroMods_LiveMapSnapshot snapshot = new PteroMods_LiveMapSnapshot();

        string worldName = "";
        GetGame().GetWorldName(worldName);
        snapshot.mapName = worldName;

        array<Man> players = new array<Man>;
        GetGame().GetPlayers(players);

        foreach (Man man : players)
        {
            if (!man)
                continue;

            PlayerIdentity identity = man.GetIdentity();
            if (!identity)
                continue;

            string playerUid = identity.GetId();
            if (playerUid == "")
                continue;

            string playerSteam64 = identity.GetPlainId();
            string playerName = identity.GetName();
            if (playerName == "")
                playerName = playerUid;

            vector pos = man.GetPosition();
            vector orientation = man.GetOrientation();

            PteroMods_LiveMapPlayer entry = new PteroMods_LiveMapPlayer();
            entry.steam64 = playerSteam64; // Steam64 ID (may be empty on some setups)
            entry.player_uid = playerUid;  // Bohemia Platform UID
            entry.name = playerName;
            entry.x = PteroMods_LiveMap_Round2(pos[0]);
            entry.y = PteroMods_LiveMap_Round2(pos[1]);
            entry.z = PteroMods_LiveMap_Round2(pos[2]);
            entry.direction = PteroMods_LiveMap_Round2(orientation[0]);
            entry.alive = man.IsAlive();

            // GetHealth("", "") returns current health on a 0 – 100 scale.
            entry.health = PteroMods_LiveMap_Round2(man.GetHealth("", ""));

            Object parentObj = man.GetParent();
            if (parentObj)
            {
                entry.in_vehicle = true;
                entry.vehicle_class = parentObj.GetType();
            }

            // Collect top-level equipped items from common attachment slots.
            // For containers that can hold items (Back, Body, Hips, Legs), also
            // enumerate their contents one level deep via
            // PteroMods_LiveMap_CollectContainerContents.
            array<string> slotNames = new array<string>;
            slotNames.Insert("Headgear");
            slotNames.Insert("Mask");
            slotNames.Insert("Eyewear");
            slotNames.Insert("Gloves");
            slotNames.Insert("Armband");
            slotNames.Insert("Body");
            slotNames.Insert("Back");
            slotNames.Insert("Hips");
            slotNames.Insert("Feet");
            slotNames.Insert("Legs");
            foreach (string slotName : slotNames)
            {
                EntityAI attachment = man.FindAttachmentBySlotName(slotName);
                if (attachment)
                {
                    PteroMods_LiveMapItem slotItem = new PteroMods_LiveMapItem();
                    slotItem.slot = slotName;
                    slotItem.className = attachment.GetType();

                    // Enumerate container contents for slots that typically hold items.
                    if (slotName == "Back" || slotName == "Body" || slotName == "Hips" || slotName == "Legs")
                        PteroMods_LiveMap_CollectContainerContents(attachment, slotItem);

                    entry.inventory.Insert(slotItem);
                }
            }
            EntityAI heldEnt = man.GetHumanInventory().GetEntityInHands();
            if (heldEnt)
            {
                PteroMods_LiveMapItem handItem = new PteroMods_LiveMapItem();
                handItem.slot = "Hands";
                handItem.className = heldEnt.GetType();
                entry.inventory.Insert(handItem);
            }

            snapshot.players.Insert(entry);
        }

        // OpenFile does not create parent directories, so a missing
        // $profile:PteroMods folder would silently discard every write.
        MakeDirectory(PTEROMODS_LIVEMAP_DIR);

        JsonFileLoader<PteroMods_LiveMapSnapshot>.JsonSaveFile(PTEROMODS_LIVEMAP_PATH, snapshot);
    }
}

// ---- Activation ------------------------------------------------------------

// Entry point called from the mission main() function.
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
