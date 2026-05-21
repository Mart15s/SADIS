# Demo Account

Use the current demo seeder to create the maintained defense/demo dataset:

```bash
cd backend
php artisan db:seed --class=CurrentVersionDemoSeeder
```

`FullFlowDemoAccountSeeder` and `DemoDataSeeder` remain compatibility aliases and call `CurrentVersionDemoSeeder`.

All demo accounts use password `password`:

| Account | Email | Purpose |
| --- | --- | --- |
| Demo owner | `demo.owner@example.test` | Owns the populated plots and is the main defense walkthrough account. |
| Shared editor | `demo.editor@example.test` | Has editor access to the primary plot. |
| Shared viewer | `demo.viewer@example.test` | Has viewer access to the primary plot. |
| Neighbor | `demo.neighbor@example.test` | Adds community variety. |
| Community member | `demo.community@example.test` | Adds public community posts. |

What the seeder creates:

- 2 populated plots with JSON geometry, zones, planting history, and planning snapshots
- 16 zones covering vegetables, herbs, berries, orchard strip, companion flowers, and rotation contexts
- 27 planted instances linked to catalog plants and reusable `plant_care`
- inventory with materials and tools, including sufficient and intentionally insufficient resources
- a calendar with weather fallback data, resource requirements, pending/completed/canceled tasks, and replenishment examples
- at least one pending actionable task that can be completed to reduce inventory through the normal workflow
- harvest records, condition history, rotation history, and a saved rotation draft
- shared plot access for viewer/editor collaboration testing
- public and plot-linked community posts

Notes:

- The seeder is deterministic and rerunnable. It rebuilds only the known demo users and their related data.
- Weather generation is mocked inside this seeder so it remains usable when external API calls time out locally.
