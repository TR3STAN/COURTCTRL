LIVE BRONZE MATCH STATUS + PUBLIC DISPLAY FIX

This patch addresses the mismatch shown in the screenshots:
- The organizer can have a saved winner_entry_id while match_status is not exactly the string "Completed".
- The organizer elimination match card now treats a match with a recorded winner as completed.
- Public elimination rankings and podium now use the saved winner_entry_id as the authoritative completion signal, rather than requiring an exact match_status string.
- Public display automatically reloads every 5 seconds while visible, so bronze podium/ranking updates appear without manually refreshing.

Updated all three copies of scoring.php and all three copies of display_standings.php in the supplied project archive.

Installation:
1. Back up the current project and database.
2. Extract this ZIP over the existing project, preserving the folder structure.
3. Hard-refresh the organizer and public display tabs (Ctrl+F5).
4. Check that the Bronze Match shows "Completed · Edit" and that the public podium shows the bronze winner.
No database schema change is required.
