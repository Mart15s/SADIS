# API transition matrix

Stage 1 keeps legacy endpoints temporarily while canonical Yava endpoints use the new domain services. Compatibility controllers must delegate to shared services; they must not fork authorization or business rules. Legacy routes may be marked deprecated only after repository search and runtime telemetry confirm no frontend consumer remains.

| Legacy surface | Canonical Yava surface | Compatibility rule | Removal gate |
| --- | --- | --- | --- |
| `/api/plots` | `/api/v1/fields` (Farm selected by `farm_id`) | A legacy Plot maps to one Field; preserve the old envelope and identifiers through mapping records. | No frontend calls, mapping coverage validated, deprecation window complete. |
| `/api/plots/{plot}` and `/api/plots/{plot}/workspace` | `/api/v1/fields/{field}` and `/api/v1/fields/{field}/workspace` | Geometry validation and save semantics must remain equivalent while both editors exist. | Mobile/desktop Field Editor uses canonical save and recovery flow. |
| `/api/plots/{plot}/plant-zones` | `/api/v1/fields/{field}/zones` | Preserve legacy Zone IDs via mappings; an implicit whole-Field zone is not exposed as fabricated legacy data. | All legacy editors migrated. |
| `/api/plants` and `/api/plots/{plot}/plants` | `/api/v1/crop-seasons` plus condition/history resources | Return only deterministically mapped Seasons; ambiguous Plant records remain legacy history. | Classifier report accepted and consumers migrated. |
| `/api/catalog-plants` | `/api/v1/crops` and `/api/v1/crops/{crop}/varieties` | Global catalogue and Farm-specific Crop behavior remain distinct during transition. | Global/Farm catalogue consumers migrated. |
| `/api/plots/{plot}/calendars`, `/api/calendars/{calendar}/tasks`, `/api/tasks/{task}/*` | `/api/v1/tasks` and `/api/v1/tasks/{task}/*` | Preserve legacy task shapes while canonical Tasks carry explicit Farm/Field/Season/Community scope. | Calendar and task UIs use canonical identifiers. |
| `/api/inventory` | `/api/v1/inventories` and `/api/v1/inventory-movements` | Canonical quantity changes create movement history; preserve the old response shape for verified legacy callers. | Inventory consumers and reports use movements. |
| `/api/plots/{plot}/access` and sharing routes | `/api/v1/farms/{farm}/members` plus membership permissions | Translate owner/editor/viewer only when mapping is unambiguous; server-side Farm permissions control access. | All active shares mapped and accepted. |
| `/api/community` and `/api/plots/{plot}/community` | Community membership, links, and analytics under `/api/v1/communities/*` | Keep legacy posts as historical compatibility data; never infer canonical membership or a Farm–Community link from a post. | Historical display has a supported archival path. |
| `/api/plots/{plot}/harvests`, rotations, and analytics | `/api/v1/crop-seasons/{season}/harvests`, rotation warnings, and `/api/v1/farms/{farm}/analytics` | Resolve mappings before canonical access; Community aggregation uses only explicit link scopes. | Canonical dashboards validated against representative legacy samples. |

## Response and deprecation contract

- Canonical `/api/v1` responses use a `data` resource envelope where applicable; validation errors use `message` plus `errors`.
- Compatibility responses keep only fields required by verified consumers.
- Deprecation is announced through documentation and, where supported, `Deprecation`, `Sunset`, and successor-link headers.
- Authorization is evaluated on the canonical target before data is serialized into an old shape.
- A missing or ambiguous legacy mapping returns a safe conflict/not-found response; it never guesses ownership.

The legacy and canonical controllers currently coexist. A row in this matrix describes the required compatibility contract, not permission to assume that similarly named records share an identifier. Migration mappings are the only supported identity bridge.

Canonical Farm–Community link management uses `POST /api/v1/farms/{farm}/communities/{community}`, `GET /api/v1/farm-community-links` with one authorized scope filter, `POST /api/v1/farm-community-links/{link}/{approve|reject}`, and `DELETE /api/v1/farms/{farm}/community-links/{link}`. Canonical task payloads carry task type, optional materials, resource, and weather-warning context; inventory movements may reference a Field and Crop Season; reservations may reference a Field within the requesting Farm.

## Verification

For each row, keep automated tests covering canonical success, legacy-shape success, direct unauthorized access, cross-community isolation, missing mapping, and retry/idempotency where the route mutates state.
