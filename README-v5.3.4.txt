ZNP Development v5.3.4
Construction Workflow, Project Context & Project Team Mobile UX

INSTALLATION
1. Back up the website files and database.
2. Upload the contents of this ZIP to the website root, preserving folders.
3. While signed in as a Super Admin/Admin, open:
   /install_v5_3_4.php
4. Confirm the installer reports completion.
5. Test the Construction Portal on desktop and mobile.
6. Remove install_v5_3_4.php after successful installation.

CHANGES
- Preserves the active Construction Project across project-specific navigation.
- Standardizes the compact header as CURRENT PROJECT: [PROJECT NAME].
- Removes redundant project page titles/descriptions so the primary action appears immediately.
- Changes the persistent mobile navigation item from Vendors to Project Team.
- Moves Vendor Directory into More for authorized administrators.
- Rebuilds Project Team as responsive cards with no horizontal mobile scrolling.
- Project Team cards include Vendor, relational Trade(s), contact, phone, email, Call, Email and View Details.
- Remove From Project appears only inside View Details.
- Assign Vendor no longer includes a manually typed Trade field.
- Trade names are loaded from the Vendor's normalized trade relationships.
- Installer migrates legacy primary_trade data without hard-coded trade values.
- Installer consolidates duplicate Project/Vendor assignments and preserves linked messages/workforce references.
- Fixes modal actions used by Assign Vendor and View Details.

FILES INCLUDED
- dev/includes/functions.php
- dev/includes/header.php
- dev/includes/footer.php
- dev/assets/dev.css
- dev/assets/dev-app.js
- dev/project.php
- dev/calendar.php
- dev/buildings.php
- dev/schedule.php
- dev/tasks.php
- dev/documents.php
- dev/project_team.php
- dev/daily_logs.php
- dev/photos.php
- dev/expenses.php
- install_v5_3_4.php

NOTES
- This is an incremental drop-in patch, not a full website rebuild.
- The installer is rerunnable.
- Existing project access permissions remain unchanged.
