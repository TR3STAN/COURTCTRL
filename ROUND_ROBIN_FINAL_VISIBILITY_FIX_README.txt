COURTCTRL - Round Robin Final Visibility Fix

Root cause found in the previous package:
- The $activeStage variable was still not initialized before the POST handler in the packaged scoring.php.
- On a normal GET page load, the Round Robin stage pane therefore rendered without the active class and remained hidden.

Fix:
- Initialize activeStage to Round Robin for normal page loads.
- Preserve Elimination stage when active_stage=elim is requested.
- Keep court-grouped Round Robin cards based on their existing schedule court assignments.
- If matches exist but none have court assignments, show a clear instruction instead of an empty blank section.

Install:
1. Back up your project.
2. Extract the ZIP.
3. Replace C:\xampp\htdocs\pickleball_tournament\scoring.php with the included scoring.php (or replace the full project folder).
4. Refresh the page with Ctrl+F5.
5. Open the Round Robin tab.

Validation:
- PHP syntax check passed.
- Live database/browser execution was not available here.
