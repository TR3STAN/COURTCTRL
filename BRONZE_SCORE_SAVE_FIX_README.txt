BRONZE MATCH SCORE-SAVING FIX

Updated all three scoring.php copies in this project archive.

Changes:
- Bronze Match score forms now submit a dedicated save_bronze_scores action.
- The scoring handler routes that action through the same validated score-saving transaction as other elimination matches.
- The handler verifies that the selected match is a Bronze Match and that both semifinal-loser entries are assigned before accepting scores.
- Existing score validation, match completion, winner recording, and podium/ranking calculations are retained.

Installation:
1. Back up the current project and database.
2. Extract this archive.
3. Replace the existing project files with the included files.
4. Refresh Scoring & Standings with Ctrl+F5.
5. Save the Bronze Match score again.

No database schema change is required.
