COURTCTRL TOURNAMENT WORKSPACE UPDATE
======================================

What changed
------------
1. Added workspace.php as the landing page for a selected tournament.
2. Dashboard tournament rows now include Open workspace and Edit actions.
3. Workspace includes summary counts for categories, entries, matches, scheduled matches, completed matches, and ongoing matches.
4. The five tournament modules are available from the workspace:
   - Players & Teams
   - Brackets
   - Schedule & Courts
   - Live Feed
   - Scoring & Standings
5. The selected tournament is stored in the PHP session as active_tournament_id.
6. Module pages use the remembered tournament automatically. The repeated tournament dropdowns were removed from module pages; category selectors remain.
7. Category selection now auto-loads in Live Feed and Scoring & Standings. Live Feed date changes also reload the feed.
8. If the remembered tournament was deleted or is no longer available, module pages fall back to the first available tournament.

Install
-------
1. Back up C:\xampp\htdocs\pickleball_tournament.
2. Extract the ZIP contents into C:\xampp\htdocs\, allowing files to merge/replace.
3. Open http://localhost/pickleball_tournament/.
4. On the Dashboard, click Open workspace beside the tournament you want.
5. Use the workspace module cards. To change tournament, use the Active Tournament selector at the top of the workspace.

Notes
-----
- Existing category selectors remain because users still need to choose which category to work on.
- Tournament switching is centralized in the Workspace.
- The selected tournament persists for the current PHP browser session.
- PHP syntax validation passed for workspace.php, index.php, tournaments.php, players.php, brackets.php, schedule.php, live_feed.php, and scoring.php.
- Database behavior and visual rendering should still be tested in the user's local XAMPP environment.
