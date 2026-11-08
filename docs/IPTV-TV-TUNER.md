# Staging TV tuner channels

The Channels & Guide screen can record channels already scanned on a room TV. Choose **TV tuner (draft)**, give the channel a guest-facing number and enter its actual TV tuner number. The hotel must supply its own verified local and DStv channel names and numbers; numbers can differ between TVs. Confirm that hotel distribution is licensed.

These mappings are **hidden drafts**: saving one does not scan a TV, control a tuner, or make the channel playable. The existing network-stream path remains available. M3U export includes active network streams only; import skips any channel number or name matched to a tuner draft, rather than replacing it. Keep an independent record of tuner mappings before deleting them. Database rollback is blocked while any tuner number remains, including on channels changed back to a stream.

Before implementing live tuning, obtain the Samsung model, firmware, deployment method (URL launcher or signed TV application), and the channel map on each model. Verify with Samsung's supported APIs and privileges on the actual device. For example, Samsung's hospitality Broadcast API requires a partner-level privilege and its direct RF tuning parameters can include frequency and service ID—not just the displayed number. A normal browser page cannot assume that API is present or authorised. Test switching, presentation of video, errors, return navigation and channel numbering on the deployed TVs before exposing drafts to guests. Do not assume the coax channels are IP streams or bypass DStv access controls.

Reference: https://developer.samsung.com/smarttv/develop/api-references/samsung-product-api-references/broadcast-api.html?device=htv
