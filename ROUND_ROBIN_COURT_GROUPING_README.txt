COURTCTRL - Round Robin Court Grouping

Change:
- Round Robin matches are now grouped into separate court sections/cards on Scoring & Standings.
- Each court section has its own heading and match count.
- Matches are grouped according to their current match_schedules.court_id assignment.
- Matches without a court assignment appear in a separate "Unassigned Court" section.
- Each court section displays its match cards in a two-column layout on wider screens and a single column on smaller screens.
- Existing score entry, start match, and court reassignment controls remain inside each match card.

Install:
1. Back up the current project folder and MySQL database.
2. Extract this ZIP.
3. Copy the included pickleball_tournament folder into C:\xampp\htdocs\ and replace the existing project files.
4. Refresh Scoring & Standings and select the tournament category.
5. Make sure matches have court assignments in Schedule & Courts; the scoring page groups cards based on those assignments.

Validation:
- PHP syntax check passed for scoring.php.
- Database/browser runtime testing was not performed here.
