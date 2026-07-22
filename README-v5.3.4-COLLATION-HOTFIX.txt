ZNP DEVELOPMENT v5.3.4 INSTALLER COLLATION HOTFIX
===================================================

PURPOSE
-------
This hotfix replaces only install_v5_3_4.php.

It resolves MySQL error 1267:
Illegal mix of collations (utf8mb4_unicode_ci) and
(utf8mb4_0900_ai_ci) for operation '='.

The installer now explicitly converts both trade-name operands to
utf8mb4_unicode_ci during the legacy Vendor Trade migration. It does
not change the database-wide collation or alter existing table defaults.

INSTALLATION
------------
1. Upload install_v5_3_4.php to the website root, replacing the prior file.
2. Sign in as Super Admin / Admin.
3. Visit /install_v5_3_4.php.
4. Confirm the success messages.
5. Delete or rename the installer after successful completion.

SAFETY
------
- Rerunnable/idempotent.
- Uses a transaction.
- Rolls back if a migration step fails.
- Does not change existing database or table collations.
