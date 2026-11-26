COURTCTRL - TV / Full-Screen Display Update

Features:
- Full Screen button uses the browser Fullscreen API.
- Display auto-refreshes every 3 seconds so scoring/status/scheduling changes appear quickly.
- Four-column responsive TV layout; court cards fit into the available viewport on typical 1080p displays, including two rows for up to eight courts.
- Larger category/bracket/round information on the display.
- Manage Next Game Assignments panel lets the organizer select an upcoming scheduled match and assign it to a specific court.
- A manually selected next game is prioritized on the selected court. The match is moved from its previous court, not duplicated.
- Existing now-playing and next-game display remains.

Install:
1. Back up the current COURTCTRL folder and database.
2. Extract this ZIP.
3. Copy the included pickleball_tournament folder into C:\xampp\htdocs\ and replace the existing files.
4. Start Apache and MySQL.
5. Open http://localhost/pickleball_tournament/display_screen.php?tournament_id=YOUR_ID
6. Click FULL SCREEN on the TV/monitor.
7. To assign the next game, expand MANAGE NEXT GAME ASSIGNMENTS, choose a match for a court, and click Assign next game.

Notes:
- Auto-refresh interval is 3 seconds.
- Browser fullscreen must be started by clicking the button.
- The display can only assign matches that already have schedule rows and are Pending, Ready, or Scheduled.
- PHP syntax check passed for display_screen.php and print_score_sheets.php. Database/browser runtime testing was not performed here.
