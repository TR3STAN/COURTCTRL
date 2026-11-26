BRONZE MATCH RESULT / PODIUM SYNCHRONIZATION FIX

This update addresses the case where the Bronze Match score form reports completion
but the Bronze Match card and podium remain pending.

Changes in all scoring.php copies:
- Verify that the score-saving operation persisted the winner and match status.
- Match placement calculations now use the persisted winner_entry_id as the source
  of truth, rather than requiring the match_status text to exactly equal "Completed".
  This prevents a status-label mismatch from suppressing the bronze and fourth-place
  results after a winner has been recorded.
- The Bronze Match and Championship podium placements require both entrants and a
  recorded winner.

Installation:
1. Back up your project and database.
2. Extract this ZIP over the existing project.
3. Refresh Scoring & Standings with Ctrl+F5.
4. Open the Bronze Match and save/finish its score again if necessary.

No database migration is included. If the existing Bronze Match record has no
winner_entry_id despite saved scores, re-save the score once after replacing files.
