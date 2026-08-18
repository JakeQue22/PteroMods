#ifndef RL_DISABLE_CHAT
class RLChatHandler {
	
	// Do something when a chat message visible for everyone was sent. Make sure to always call super.OnChatMessage(sender, originalChannelId, cfg, playerPrefix, message, uncensoredMessage);
	// Internally this method is used to send a message to a channel. You could invoke this method to send your own messages or manipulate or even block player send messages.
	// Be aware, that the PlayerIdentity cannot be null ! Otherwise you will get a Nullpointer exception, so you cannot send System messages with this method. Use `SendSimpleChatMessage` for that
	void OnChatMessage(PlayerIdentity sender, int originalChannelId, ChannelCfg cfg, PrefixGroup playerPrefix, string message, string uncensoredMessage);
	/**
	override void OnChatMessage(PlayerIdentity sender, int originalChannelId, ChannelCfg cfg, PrefixGroup playerPrefix, string message, string uncensoredMessage) {
		// Do something here with the message before it is sent to other clients.
		// You could even return here to block the message. I would recommend using SendSimpleChatMessage with an error message to the player to show something went wrong
		super.OnChatMessage(sender, originalChannelId, cfg, playerPrefix, message, uncensoredMessage);
		// Do something after the message was sent to the clients it should be send to
	}
	*/
	
	// Add checks for custom commands there.
	bool ChatCommandExists(string cmd);
	/**
	override bool ChatCommandExists(string cmd) {
		if (super.ChatCommandExists(cmd))
			return true;
		return "mycmd1" == cmd || "mycmd2" == cmd || "mycmd3" == cmd;
	}
	*/
	
	// Add checks if players should be able to execute a command. 
	// By default no player has permissions to execute a command !
	// This method must be overwritten for custom commands to work
	bool HasPermission(PlayerIdentity sender, string cmd);
	/**
	override bool HasPermission(PlayerIdentity sender, string cmd) {
		if (super.HasPermission(sender, cmd))
			return true;
		if (cmd == "mycmd1")
			return true;
		if (cmd == "mycmd2" && IsAdmin(sender))
			return true;
		if (cmd == "mycmd3" && SomeOtherCustomCheck(sender))
			return true;
		return false;
	}
	*/
	
	// This method is called after the ChatCommandExists and HadPermission functions successfully ran and the command should be executed now
	void OnChatCommand(PlayerIdentity sender, string cmd, TStringArray args);
	/**
	override void OnChatCommand(PlayerIdentity sender, string cmd, TStringArray args) {
		super.OnChatCommand(sender, cmd, args);
		if (cmd == "mycmd1") {
			// Do something
		} else if (cmd == "mycmd2") {
			// Do something else
		}
	}
	*/
	
	// This method is used to send a simple message to any player or all players on the server.
	// If you want to send a message to all players, set the player = null and global to true.
	// Here you can see how the RPC is made up and you could send your own messages, by making your own function, but with different values written to the RPC.
	static void SendSimpleChatMessage(PlayerIdentity player, string message, bool global = false) {
		if (!player && !global)
			return;
		int channel = CCSystem; // Ignore these temporary variables. ScriptRPC can act very weird when directly using rpc.Write(0); or similar
		int temp = 0;
		string str = "";
		bool tempB = false;
		ScriptRPC rpc = new ScriptRPC();
		rpc.Write(channel); // Channel the message is sent to. Custom Messages should either use a valid vanilla Channel ID or 4096 for the Advanced Groups custom Channel
		rpc.Write(str); // Name of the Sender
		rpc.Write("+" + message); // Message sent. Don't forget to always add a + infront of the actual message
		rpc.Write(str); // Extra parameter used as param4 in the ChatMessageEventParams. Empty by default
		rpc.Write(str); // Prefix String. In normal messages, this is defined by the PrefixGroup.prefix
		rpc.Write(temp); // Color of the message (ARGB()) int value
		rpc.Write(str); // group Prefix. Same as prefix, but can have it's own color and is between prefix and name ([PREFIX] [GROUP_TAG] [NAME] [MESSAGE])
		rpc.Write(temp); // Color of the Channel. This also defines the color of the Message
		rpc.Write(tempB); // Set to true, then the group Prefix color will be applied to the group prefix, which is set next. Otherwise the channel color is used
		rpc.Write(temp); // Color of the group tag
		rpc.Write(str); // Name of the channel the message is sent to. I would recommend using an existing channel for it so players can mute it.
		// That's the only use of the parameter. Otherwise this channel cannot be muted by players.
		// You can create channel no player can write in by changing the `canReceiveMessagesFromPlayers` parameter to falce for this channel
		rpc.Send(NULL, RLGroupRPCs.RL_GLOBAL_CHAT, true, player);
	}
	
}
#endif