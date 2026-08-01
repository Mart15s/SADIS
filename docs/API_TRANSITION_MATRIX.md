# API transition matrix

Stage 1 keeps legacy endpoints temporarily while canonical Yava endpoints use the new domain services. Compatibility controllers must delegate to shared services; they must not fork authorization or business rules. Legacy routes may be marked deprecated only after repository search and runtime telemetry confirm no frontend consumer remains.

| Legacy surface | Canonical Yava surface | Compatibility rule | Removal gate |
| --- | --- | --- | --- |
| `/api/plots` | `/api/farms/{farm}/fields` | A legacy plot maps to one field; preserve the old envelope and identifiers through mapping records. | No frontend calls, mapping coverage validated, deprecation window complete. |
| `/api/plots/{plot}` workspace/geometry | `/api/fields/{field}` and `/api/fields/{field}/zones` | Delegate geometry validation and transactional save to the field service. | Mobile/desktop Field Editor uses canonical save and recovery flow. |
| `/api/plant-zones` | `/api/fields/{field}/zones` | Preserve legacy zone IDs via mappings; an implicit whole-field zone is not exposed as fabricated legacy data. | All legacy editors migrated. |
| `/api/plants` | `/api/crop-seasons` plus condition/history resources | Return only deterministically mapped seasons; ambiguous Plant records remain legacy history. | Classifier report accepted and consumers migrated. |
| `/api/catalog-plants` | `/api/crops` and `/api/crop-varieties` | Global catalogue is read-only for normal users; farm custom entries remain farm-scoped. | Global/farm catalogue consumers migrated. |
| Legacy calendar/task routes | `/api/tasks` scoped by farm, field, season, or community | Shared task service owns state transitions and authorization. | Calendar and task UIs use canonical identifiers. |
| Legacy inventory routes | `/api/farms/{farm}/inventory` and `/api/communities/{community}/inventory` | Mutations create immutable inventory movements; preserve old response shape when called through legacy routes. | Inventory consumers and reports use movements. |
| Legacy access-right routes | `/api/farms/{farm}/members` and permission endpoints | Translate owner/editor/viewer only when mapping is unambiguous; server-side farm permissions control access. | All active shares mapped and accepted. |
| Legacy community feed/posts | `/api/communities/{community}/activity` | Preserve as `legacy` activity only; never infer membership, community, or farm link. | Historical display has a supported archival path. |
| Legacy harvest/rotation/analytics routes | Crop-season harvests, rotation plans, farm analytics | Resolve mappings then delegate to canonical query/services; community aggregation applies explicit sharing scopes. | Canonical dashboards validated against legacy samples. |

## Response and deprecation contract

- Canonical responses use one documented resource envelope and consistent validation/error fields.
- Compatibility responses keep only fields required by verified consumers.
- Deprecation is announced through documentation and, where supported, `Deprecation`, `Sunset`, and successor-link headers.
- Authorization is evaluated on the canonical target before data is serialized into an old shape.
- A missing or ambiguous legacy mapping returns a safe conflict/not-found response; it never guesses ownership.

## Verification

For each row, keep automated tests covering canonical success, legacy-shape success, direct unauthorized access, cross-community isolation, missing mapping, and retry/idempotency where the route mutates state.
