# Placera (PHP + mysqli)

## Setup
1. Create a MySQL database (default expected name: `placera`).
2. Import schema:
   - `sql/plc_schema.sql`
3. Configure DB credentials in environment variables (optional) or keep defaults:
   - `PLC_DB_HOST`
   - `PLC_DB_PORT`
   - `PLC_DB_NAME`
   - `PLC_DB_USER`
   - `PLC_DB_PASS`
4. Open `index.php` in browser.

## Auth flow
- New registration creates a `pending` user.
- First registered user is auto-approved as `admin`.
- Admin can approve/reject users in Admin -> Inställningar.

## Data model
- All tables are prefixed with `plc_`.
- Rooms, classes and placements are persisted in MySQL (not localStorage).
- Audit fields are included (`created_by`, `created_at`, `updated_by`, `updated_at`).

