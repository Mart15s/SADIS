# Legacy data migration

Stage 1 migration is additive and non-destructive. Legacy tables and columns remain available during the compatibility period. Schema migrations create the new structures; explicit Artisan commands classify and convert legacy records in chunks.

## Safety properties

- Dry-run is the default operating procedure before conversion.
- Every execution has a migration-run/audit record and deterministic mappings.
- Existing source rows are not deleted and legacy semantic values are not rewritten. Additive compatibility columns may be populated to link a source row to its canonical target.
- Commands process bounded chunks and can resume after interruption.
- Duplicate prevention uses stable source-type/source-id mappings and database uniqueness constraints.
- Count, orphan, ambiguous, invalid, and already-migrated results are reported.
- No Stage 1 data conversion command is called by Docker startup or `RUN_SCHEMA_MIGRATIONS`.

## Required sequence

1. Create and verify a database snapshot.
2. Deploy and run `php artisan migrate --force` to add the target schema.
3. Run the legacy migration report/classifier in dry-run mode.
4. Review counts, orphaned foreign keys, and ambiguous plant groups.
5. Execute parent mappings before dependent records: owners/farms, plots/fields, zones, catalogue mappings, then eligible crop seasons and history.
6. Rerun the report and compare source, mapped, skipped, and orphan counts.
7. Verify authorization using migrated owners and shared access records.
8. Keep legacy reads available until the frontend/API transition matrix is complete.

Commands:

```bash
# Classify and report only; no domain rows are written.
php artisan yava:stage1-migrate

# Execute in bounded chunks. Save the run UUID printed by the command.
php artisan yava:stage1-migrate --execute --chunk=250

# Resume/continue a recorded run. Use --limit for a controlled rehearsal.
php artisan yava:stage1-migrate --execute --run=RUN_UUID --chunk=250 --limit=1000

# Aggregate source/target/mapping/orphan counts, then add one run's details.
php artisan yava:stage1-report
php artisan yava:stage1-report RUN_UUID
```

The UUID printed by a dry run is ephemeral because dry runs are not stored and cannot be resumed. Only an executing run can be continued with `--run`. Capture JSON output and stderr from each rehearsal in the release evidence directory outside the repository; do not commit production records or identifiers.

Use each command's `--help` output from the deployed release as the authoritative option list. Never invent or assume production arguments from an older image.

## Plant classification

Every legacy Plant is classified as one of:

- `high_confidence_crop_season`
- `historical_crop_record`
- `ambiguous_legacy_plant`
- `invalid_or_orphaned`
- `already_migrated`

Only a high-confidence group may create a Crop Season automatically. A group must resolve one target field, one crop identity, a reliable time scope, and a coherent planting scope. Multiple individual markers that describe the same crop/time/field scope are grouped; they do not become artificial separate seasons. Historical and ambiguous rows remain traceable through legacy mappings and reports.

## Validation report

Archive the following alongside the release record:

- command version and commit SHA;
- run ID, start/end time, chunk size, and mode;
- source totals by legacy entity;
- created, matched/already-migrated, ambiguous, invalid, and orphan counts;
- mapping count and duplicate count;
- target totals and relationship-orphan checks;
- operator identity and backup identifier.

A retry with the same run UUID must not increase target counts for already-mapped source rows. A no-argument report returns aggregate counts; passing an executing run UUID also returns that persisted run and classification/status totals. Investigate any mismatch before enabling canonical reads.

## Failure and recovery

Stop the conversion command, preserve its run ID and logs, fix the cause, and resume using the same documented command semantics. Because the process is additive and idempotent, retry is preferred for partial conversion. If converted data is materially wrong, disable writes and restore the verified pre-run snapshot; do not delete target rows ad hoc in production.
