COURTCTRL - FIXED / MULTI-COURT SCHEDULE FEATURE

WHAT CHANGED
1. Added fixed_schedule.php. This page imports a fixed Excel timetable into the selected tournament.
2. The importer supports the same bracket being assigned to several courts during the same time slot.
3. Each Excel court assignment is saved as a separate match_schedules record with its own court, start time, end time, and schedule order.
4. The Schedule & Courts page now has an Import Fixed / Multi-Court Schedule link and displays the saved start time.

INSTALLATION
1. BACK UP your pickleball_tournament database in phpMyAdmin first.
2. Extract this ZIP into C:\xampp\htdocs\ (allow it to merge/replace the existing pickleball_tournament folder).
3. Start Apache and MySQL in XAMPP.
4. Open: http://localhost/pickleball_tournament/fixed_schedule.php
5. Select the tournament, select the schedule date, and upload Final Schedule.xlsx.
6. Import only after checking the selected tournament and date.

IMPORTANT REQUIREMENTS / LIMITATIONS
- The supplied workbook is structured as: column A = time, then pairs of columns for Court 1 through Court 8 (category/bracket label, game label).
- The selected tournament must have at least 8 active courts. The Excel file's 8 court columns are matched to the first 8 active courts ordered by court_name.
- Existing category and match records must already exist in COURTCTRL. This importer schedules existing matches; it does not create categories, teams, brackets, or matchups.
- Category labels supported by the workbook mapping: Lo NMD, HN Coed, Low NWD, BMD, BWD, BMxD, Low NMxD, and 40 Up. Bracket labels A-D are mapped to the corresponding bracket order/name.
- Game labels supported: Game 1, Game 2, etc.; QF 1-4; SF 1-2; GOLD; BRONZE. A match must already exist in the database with a matching category, bracket, round and match number. If any assignment cannot be matched, the import is stopped and no changes are committed.
- Each occupied slot is currently treated as 15 minutes. This matches the uploaded workbook's 15-minute interval layout.
- The Replace existing schedules checkbox deletes existing schedules for the selected tournament before importing. Leave it unchecked for a new schedule. Back up the database before replacing.
- This code was PHP syntax-checked. It has not been executed against your local MySQL database, so verify the category/bracket/match records before importing.
