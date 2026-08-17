/*
 * PteroMods - DayZ Standalone Give Money Bridge (Enforce Script)
 *
 * Bundle path in this repository:
 *   game-panel-mods/DayZManager/assets/bridge/pteromods_give_money.c
 *
 * WHAT IT DOES
 * The PteroMods panel queues "give money" requests by writing JSON files to
 *   $profile:PteroMods/give_money_<uid>.json
 * (mirrored to the player's Steam64 file when the two ids differ).
 *
 * This mission script watches those files for online players and, when it finds
 * queued entries for a matching player, adds the requested MoneyRuble items to
 * that player's inventory with CreateInInventory().
 *
 * ACTIVATION
 * Copy this file into the active mission directory and wire it into init.c the
 * same way as the live-map bridge:
 *
 *   #include "$CurrentDir:mpmissions/dayzOffline.chernarusplus/pteromods_give_money.c";
 *
 *   void main()
 *   {
 *       PteroMods_GiveMoney_Init();
 *       ...
 *   }
 *
 * The panel treats the queue file as the delivery acknowledgement. Once this
 * bridge removes a granted entry from the file, the panel removes the matching
 * database row the next time the queue is read or another grant is queued.
 *
 * VERIFIED API USED BY THIS FILE
 *   3_game/global/game.c      ScriptCallQueue GetCallQueue(int call_category)
 *   3_game/tools/tools.c      const int CALL_CATEGORY_SYSTEM = 0
 *   2_gamelib/tools.c         proto void CallLater(func fn, int delay = 0,
 *                                                  bool repeat = false, ...)
 *   2_gamelib/tools.c         proto void Remove(func fn)
 *   1_core/proto/ensystem.c   proto native bool MakeDirectory(string name)
 *   3_game/tools/jsonfileloader.c
 *                             static void JsonLoadFile(string filename, T data)
 *                             static void JsonSaveFile(string filename, T data)
 *   3_game/systems/inventory/inventory.c
 *                             EntityAI CreateInInventory(string type)
 *   3_game/playeridentity.c   string GetPlainId()
 *                             string GetId()
 */
const int PTEROMODS_GIVEMONEY_INTERVAL_MS = 5000;
const string PTEROMODS_GIVEMONEY_DIR = "$profile:PteroMods";
const string PTEROMODS_GIVEMONEY_PREFIX = "$profile:PteroMods/give_money_";

class PteroMods_GiveMoneyEntry
{
    int queue_id;
    string server_id;
    string player_id;
    string player_uid;
    string item_class;
    int quantity;
    string queued_at;
    string callback_base_url;
    string callback_path;
}

class PteroMods_GiveMoneyFulfilCallback : RestCallback
{
    override void OnSuccess(string data, int dataSize)
    {
    }

    override void OnError(int errorCode)
    {
    }

    override void OnTimeout()
    {
    }
}

bool PteroMods_GiveMoney_EntryTargetsPlayer(PteroMods_GiveMoneyEntry entry, string playerId, string playerUid)
{
    if (!entry)
        return false;

    string entryUid = entry.player_uid;
    string entryId = entry.player_id;

    if (entryUid != "" && entryUid == playerUid)
        return true;

    if (entryId != "" && entryId == playerId)
        return true;

    return false;
}

class PteroMods_GiveMoneyBridge
{
    static ref PteroMods_GiveMoneyBridge s_instance;

    protected bool m_Scheduled;
    protected ref map<int, bool> m_FulfilledQueueIds;

    void PteroMods_GiveMoneyBridge()
    {
        m_Scheduled = false;
        m_FulfilledQueueIds = new map<int, bool>;
    }

    void Start()
    {
        if (!GetGame())
            return;

        if (!GetGame().IsServer())
            return;

        if (m_Scheduled)
            return;

        MakeDirectory(PTEROMODS_GIVEMONEY_DIR);
        GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Remove(this.Tick);
        GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(this.Tick, PTEROMODS_GIVEMONEY_INTERVAL_MS, true);
        m_Scheduled = true;
    }

    void Stop()
    {
        if (!GetGame())
            return;

        GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Remove(this.Tick);
        m_Scheduled = false;
    }

    void Tick()
    {
        array<Man> players = new array<Man>;
        GetGame().GetPlayers(players);

        foreach (Man man : players)
        {
            PlayerBase player = PlayerBase.Cast(man);

            if (!player)
                continue;

            ProcessPlayer(player);
        }
    }

    void ProcessPlayer(PlayerBase player)
    {
        PlayerIdentity identity = player.GetIdentity();

        if (!identity)
            return;

        string playerUid = identity.GetId();
        string playerId = identity.GetPlainId();

        ref array<string> keys = new array<string>;

        if (playerUid != "")
            keys.Insert(playerUid);

        if (playerId != "" && playerId != playerUid)
            keys.Insert(playerId);

        string processedKey = "";

        foreach (string key : keys)
        {
            if (ProcessQueueFile(player, key, playerId, playerUid))
            {
                processedKey = key;
                break;
            }
        }

        if (processedKey == "")
            return;

        if (processedKey != playerUid || playerId == "" || playerId == playerUid)
            return;

        foreach (string mirrorKey : keys)
        {
            if (mirrorKey == processedKey)
                continue;

            ref array<ref PteroMods_GiveMoneyEntry> emptyQueue = new array<ref PteroMods_GiveMoneyEntry>;
            JsonFileLoader<array<ref PteroMods_GiveMoneyEntry>>.JsonSaveFile(PTEROMODS_GIVEMONEY_PREFIX + mirrorKey + ".json", emptyQueue);
        }
    }

    bool ProcessQueueFile(PlayerBase player, string key, string playerId, string playerUid)
    {
        ref array<ref PteroMods_GiveMoneyEntry> queue = new array<ref PteroMods_GiveMoneyEntry>;
        string path = PTEROMODS_GIVEMONEY_PREFIX + key + ".json";

        JsonFileLoader<array<ref PteroMods_GiveMoneyEntry>>.JsonLoadFile(path, queue);

        if (!queue || queue.Count() < 1)
            return false;

        ref array<ref PteroMods_GiveMoneyEntry> remaining = new array<ref PteroMods_GiveMoneyEntry>;
        ref array<ref PteroMods_GiveMoneyEntry> fulfilled = new array<ref PteroMods_GiveMoneyEntry>;
        bool changed = false;

        foreach (PteroMods_GiveMoneyEntry entry : queue)
        {
            if (!entry)
                continue;

            if (!PteroMods_GiveMoney_EntryTargetsPlayer(entry, playerId, playerUid))
            {
                remaining.Insert(entry);
                continue;
            }

            if (entry.queue_id > 0 && m_FulfilledQueueIds.Contains(entry.queue_id))
            {
                fulfilled.Insert(entry);
                changed = true;
                continue;
            }

            int originalQuantity = entry.quantity;
            int quantityLeft = GiveEntry(player, entry);

            if (quantityLeft < originalQuantity)
                changed = true;

            if (quantityLeft > 0)
            {
                entry.quantity = quantityLeft;
                remaining.Insert(entry);
            }
            else
            {
                if (entry.queue_id > 0)
                    m_FulfilledQueueIds.Set(entry.queue_id, true);

                fulfilled.Insert(entry);
            }
        }

        if (changed)
            JsonFileLoader<array<ref PteroMods_GiveMoneyEntry>>.JsonSaveFile(path, remaining);

        foreach (PteroMods_GiveMoneyEntry delivered : fulfilled)
        {
            NotifyFulfilled(delivered);
        }

        return true;
    }

    int GiveEntry(PlayerBase player, PteroMods_GiveMoneyEntry entry)
    {
        if (!player || !entry)
            return 0;

        int quantity = entry.quantity;

        if (quantity < 1)
            return 0;

        for (int i = 0; i < quantity; i++)
        {
            EntityAI created = player.GetInventory().CreateInInventory(entry.item_class);

            if (!created)
                return quantity - i;
        }

        return 0;
    }

    void NotifyFulfilled(PteroMods_GiveMoneyEntry entry)
    {
        if (!entry)
            return;

        if (entry.queue_id <= 0)
            return;

        if (entry.callback_base_url == "" || entry.callback_path == "")
            return;

        RestApi restApi = GetRestApi();

        if (!restApi)
            return;

        RestContext context = restApi.GetRestContext(entry.callback_base_url);

        if (!context)
            return;

        // The callback is a one-time, queue-specific signed URL. GET avoids the
        // panel's CSRF middleware, which mission-side REST requests cannot satisfy.
        context.GET(new PteroMods_GiveMoneyFulfilCallback(), entry.callback_path);
    }
}

void PteroMods_GiveMoney_Init()
{
    if (!GetGame())
        return;

    if (!GetGame().IsServer())
        return;

    if (!PteroMods_GiveMoneyBridge.s_instance)
        PteroMods_GiveMoneyBridge.s_instance = new PteroMods_GiveMoneyBridge();

    PteroMods_GiveMoneyBridge.s_instance.Start();
}
