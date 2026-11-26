COURTCTRL SCORE SHEETS - ORDER NUMBER & PORTRAIT UPDATE
========================================================

Changes:
- The displayed match number has been removed from the printable score sheet.
- The official ORDER number (match_schedules.schedule_order) is now shown as:
  1. GAME / ORDER NO.
  2. The number inside the bracket-colored circle.
- The internal matches.match_number is not selected or displayed.
- Blank elimination sheets without a schedule order are numbered sequentially in the generated blank-sheet set.
- Legal paper is now PORTRAIT (8.5 x 14 inches).
- Each score sheet is exactly 2 inches high.
- Six score sheets are arranged on each legal portrait page.
- The umpire name field remains blank.
- Live Feed helper text now reflects the portrait layout.

Installation:
1. Back up the current project folder.
2. Extract this ZIP into C:\xampp\htdocs\ and replace the files.
3. Open Live Feed and generate score sheets again.
4. In the print dialog select Legal paper, Portrait orientation, and 100% / Actual size.
5. Turn off browser headers/footers if they take up printable space.

Updated files:
- live_feed.php
- print_score_sheets.php
