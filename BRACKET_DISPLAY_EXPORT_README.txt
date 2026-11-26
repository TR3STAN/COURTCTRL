COURTCTRL BRACKET DISPLAY + EXCEL EXPORT UPDATE
=================================================

What changed
------------
1. The Brackets page now builds the group-card overview from Round Robin matches only.
   Elimination rounds (Round of 16, Quarterfinals, Semifinals, Finals, etc.) are no
   longer incorrectly counted and displayed as extra Round Robin groups.
2. Each group card displays the actual bracket_name stored in the database (for
   example, Group A), instead of generating a separate display label.
3. Added "Export brackets to Excel" on the Brackets page.
4. The workbook contains three sheets:
   - Group Brackets: actual Round Robin group names and assigned entries.
   - Round Robin Matches: match order, teams, aggregate scores, winner and status.
   - Elimination Bracket: separate knockout rounds, TBD entries, scores, winner and status.
5. Export uses the selected tournament/category and the existing PhpSpreadsheet install.
6. No database tables or existing match records are changed by the export.

Why you saw 12 bracket cards
----------------------------
The previous display grouped every match by bracket_id. When elimination was generated,
its bracket rows were included in the same loop as Round Robin groups. They were then
renamed visually as Bracket A, B, C, etc. The updated page filters Round Robin matches
for the group-card display and keeps elimination rounds separate.

How to install
--------------
1. Back up C:\xampp\htdocs\pickleball_tournament and your database.
2. Extract this ZIP into C:\xampp\htdocs\ and replace files when prompted.
3. Open Brackets, select the category, then click "Export brackets to Excel".
4. Check the workbook's three sheets.

Scoring automation explanation
------------------------------
The uploaded scoring.php already contains a "TESTING TOOLS - Automatic demo scoring"
card. It is not automatic when you save a manual score: you must explicitly click one
of its buttons in Scoring & Standings after selecting a category:
- Auto-complete Round Robin: fills only eligible unfinished Round Robin matches.
- Generate elimination: a separate existing action that calculates group standings and
  creates the knockout bracket. It is not triggered by auto-scoring.
- Auto-complete Elimination: fills eligible knockout matches and advances winners.

The helper uses the saved round/category format to generate random legal scores, writes
rows to match_scores, sets the winner and Completed status, and advances the winner to
the linked next match. It skips completed matches, matches with existing score rows,
cancelled/skipped matches, and TBD matches. This is demo data saved as real results.
