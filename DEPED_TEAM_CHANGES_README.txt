DEPED TOURNAMENT - TWO TEAM CHANGES

This package includes apply_deped_team_changes.php.

Changes to apply:
1. High Novice Coed Doubles - Bracket B:
   Joey H. Bonilla / Yogen B. Daypuyart (the Bonilla team in Bracket B)
   becomes Erik John Sales / Francis Jay Agtas.
2. Low Novice Mixed Doubles - Bracket B:
   Yves Job Ulanday / Norie Angela Ulanday
   becomes Christine Souribio / Justin Miko Benedicto.

Important:
- The uploaded project archive does not contain a MySQL database backup, so the live XAMPP database cannot be changed from this ZIP alone.
- Run the included PHP page on the same computer where COURTCTRL and its MySQL database are running.
- The page first displays a preview and only changes the database after you click Confirm and Apply Both Changes.
- It updates the team members and the displayed team names, but preserves the tournament entries, match IDs, matchups, scores, and schedules.
- If the exact tournament/category/bracket/team cannot be identified uniquely, it stops without applying the changes.
- Back up the database in phpMyAdmin before running.
- After success, delete apply_deped_team_changes.php from htdocs for security.

Steps:
1. Copy the included pickleball_tournament folder to C:\xampp\htdocs\ and replace existing project files if prompted.
2. Start Apache and MySQL.
3. Open http://localhost/pickleball_tournament/apply_deped_team_changes.php
4. Check the preview carefully.
5. Click Confirm and Apply Both Changes.
6. Confirm the success message, then delete apply_deped_team_changes.php.
