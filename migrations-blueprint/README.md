# migrations-blueprint/

Canonical DDL for every Phase-1 table. These SQL files are **not**
executed directly — they are the authoritative reference that Claude
Code translates into Laravel migrations during `/bootstrap-laravel`.

## Conventions

- One table per file. Filename prefix = execution order.
- MySQL 8 dialect, InnoDB, `utf8mb4_unicode_ci`.
- All datetime columns are `DATETIME(3)` in the Laravel migration
  (millisecond resolution for audit).
- Every `_id` is `BIGINT UNSIGNED` with a foreign key. Use
  `->foreignId()->constrained()->cascadeOnDelete()` in the migration only
  where cascading is safe (closure table rows can cascade; audit-log
  references should NOT — keep history even if subject is deleted).
- All ENUM columns map to Laravel `->enum('col', ['a','b'])` migrations,
  or to a `string()` + database-level `CHECK` constraint if you prefer.
- `placement_parent_id` + `placement_side` has a UNIQUE index — DO NOT
  drop this. It is a compliance-critical integrity guard.

## Execution order

1. `0001_create_users_table.sql`
2. `0002_create_settings_table.sql`
3. `0003_create_distributors_table.sql`
4. `0004_create_kyc_documents_table.sql`
5. `0005_create_consents_table.sql`
6. `0006_create_orientation_views_table.sql`
7. `0007_create_genealogy_closure_table.sql`
8. `0008_create_sponsorship_table.sql`
9. `0009_create_line_change_requests_table.sql`
10. `0010_create_cooling_off_events_table.sql`
11. `0011_create_audit_log_table.sql`
12. `0012_create_agreements_table.sql`
13. `0013_seed_placement_strategy_setting.sql` (seeder, not migration)

## After translating

- `php artisan migrate` must succeed on a fresh DB.
- `php artisan migrate:rollback` must succeed too — write down migrations.
- Seed the default Placement Strategy using 0013 as a reference;
  prefer a Laravel seeder (`SettingsSeeder`) rather than raw SQL.
