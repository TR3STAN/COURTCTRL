COURTCTRL - AUTOMATIC DEMO SCORING

WHAT WAS ADDED
- Scoring & Standings now has an Automatic demo scoring panel after selecting a category.
- Auto-complete Round Robin scores unfinished Round Robin matches.
- Auto-complete Elimination scores playable elimination matches and advances winners through later rounds.
- Generated scores follow each category/round's saved scoring format (games per match, points to win, win-by-two).
- Existing completed matches and any match with existing score rows are preserved.
- Matches with TBD entries remain pending until both entries advance.
- Cancelled and skipped matches are ignored.
- Each action asks for confirmation and shows a summary notification.

RECOMMENDED TEST FLOW
1. Back up your current COURTCTRL folder and database.
2. Extract this ZIP into C:\xampp\htdocs\ and replace the project files.
3. Open the test tournament and choose a category in Scoring & Standings.
4. Save the desired scoring format.
5. Click Auto-complete Round Robin.
6. Confirm that standings are populated.
7. Use the existing Generate Elimination control.
8. Switch to the Elimination Bracket tab.
9. Click Auto-complete Elimination. It processes rounds in order and advances winners where next_match_id/winner_slot are configured.

IMPORTANT
- This feature writes generated results into the selected category's actual matches and match_scores tables. Use a TEST tournament/category, not a live tournament.
- It does not overwrite existing score rows. If you want to rerun from scratch, use a separate test category or restore your database backup.
- This archive was PHP syntax-checked. It was not connected to your local XAMPP database here, so test it on a database backup first.
