COURTCTRL — Round Robin winner authority correction

Replace these two files in the TOP-LEVEL project folder only:
- scoring.php
- display_standings.php

The Round Robin standings now use a non-empty winner_entry_id as the completion authority. match_status no longer blocks a recorded winner from contributing to standings or completed-match totals.

No database schema, SQL table structure, scoring form fields, score-saving actions, bronze-match logic, podium calculations, or nested project copies were changed.
