# COURTCTRL Code Guide

## Main pages
- `index.php` — dashboard and tournament overview
- `tournaments.php` — tournament, category, and court management
- `players.php` — registration, imports, and bulk registrant deletion
- `brackets.php` — bracket/group generation
- `schedule.php` — scheduling and court assignment
- `live_feed.php` — organizer live court board
- `display_screen.php` — public display screen
- `scoring.php` — scoring, round settings, and standings
- `schedule_export.php` — schedule Excel export

## Shared resources
- `config/database.php` — PDO database connection
- `assets/css/style.css` — shared visual theme, navigation, layout, and components
- `assets/js/app.js` — shared front-end behavior

## Safe editing workflow
1. Back up the project folder before editing.
2. Change one page or shared component at a time.
3. Run `php -l filename.php` to check PHP syntax.
4. Test the page in XAMPP with a tournament that has sample entries.
5. Do not delete bracket/match records directly unless you understand their foreign-key relationships.

The sidebar is now standardized across the main organizer pages. The global stylesheet contains the shared pickleball court palette; page-specific styles remain near the page markup for easier local adjustments.
