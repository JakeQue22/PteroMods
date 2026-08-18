#ifdef EXPANSIONMODMISSIONS
modded class ExpansionAirdropContainerManager {
    int markerUID = 0;

    override void CreateServerMarker() {
		super.CreateServerMarker();

        string markerName = "#STR_EXPANSION_AIRDROP_SYSTEM_TITLE";
		if ( GetExpansionSettings().GetAirdrop().ShowAirdropTypeOnMarker )
            markerName = m_Container.GetDisplayName();

        RLServerMarker marker = RLStaticMarkerManager.Get().AddTempServerMarker( markerName, m_Container.GetPosition(), "RayLab_Groups\\gui\\icons\\circle.paa", ARGB(255, 235, 59, 90) );
        markerUID = marker.uid;
		RLLogger.Debug("Added Airdrop Marker at " + m_Container.GetPosition() + ". Name: " + markerName, "AdvancedGroups");

    }

    override void RemoveServerMarker() {
		super.RemoveServerMarker();
		RLServerMarker marker = RLStaticMarkerManager.Get().FindTempMarker(markerUID);
		string pos = "Unknown Position";
		if (marker)
			pos = "" + marker.position;
        RLStaticMarkerManager.Get().RemoveServerMarker(markerUID);
		RLLogger.Debug("Removed Airdrop Marker at " + pos, "AdvancedGroups");
    }
}
#endif