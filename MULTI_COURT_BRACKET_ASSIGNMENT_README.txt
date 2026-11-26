COURTCTRL - Multi-Court Bracket Assignment Update

Changes:
- A bracket can be distributed across multiple selected active courts.
- Schedule order is rebuilt into simultaneous waves.
- A player is never placed in two matches in the same wave, including across different brackets and categories in the same tournament.
- Each court receives at most one match in a wave.
- Live court cards no longer show the Match number and Order label; round information remains visible.

Install:
1. Back up your current project folder and database first.
2. Extract this ZIP.
3. Copy the pickleball_tournament folder into C:\xampp\htdocs\ and replace the existing project files.
4. Start Apache and MySQL in XAMPP.
5. Open http://localhost/pickleball_tournament/schedule.php
6. Reassign/distribute the brackets across the selected courts. The updated schedule order will prevent player overlaps.

Note:
The current scheduler uses schedule_order as a simultaneous wave. Exact clock start/end times are still not generated or displayed by this update.
PHP syntax was checked for schedule.php and live_feed.php. Database/runtime testing was not performed.
