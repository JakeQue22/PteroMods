class RLChatCommandsRPCs
{
    static ref RLChatCommandsRPCs m_RLChatCommandsRPCs;

    static RLChatCommandsRPCs Get()
    {
        if(!m_RLChatCommandsRPCs)
        {
            m_RLChatCommandsRPCs = new RLChatCommandsRPCs;
        }
        return m_RLChatCommandsRPCs;
    }

    void OnRPCClient(ParamsReadContext ctx)
    {
        int type_ = 0;
        if(!ctx.Read(type_))
            return;
        Print("[Debug RPC Command] Recibed!");
        if(type_ == ChatCommandsRPCsRL.ADMIN_SET_THIRDPERSON)
        {
            Print("[Debug RPC Command] Admin Command Recibed!");
            SetThirdPersonAllowed(ctx);
        }
    }

    void SetThirdPersonAllowed(ParamsReadContext ctx)
    {
        bool state = false;
        if(!ctx.Read(state))
            return;
        Print("[Debug RPC Command] Allowed 3PP RPC");
    }
}