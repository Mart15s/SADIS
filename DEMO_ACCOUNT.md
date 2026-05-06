# Demo Account

Use the dedicated demo seeder to create a separate full-flow test account without touching other users:

```bash
cd backend
php artisan db:seed --class='Database\Seeders\FullFlowDemoAccountSeeder'
```

Credentials:

- Demo owner: `demo.garden@example.test` / `DemoGarden123!`
- Shared viewer: `demo.viewer@example.test` / `DemoViewer123!`
- Shared editor: `demo.editor@example.test` / `DemoEditor123!`

What the seeder creates:

- 4 populated plots with geometry, zones, planting history, and snapshots
- 8 zones covering greenhouse, berries, herbs, root vegetables, legumes, and an empty rotation zone
- 12 planted instances across multiple lifecycle states plus 1 unused catalog-only plant
- inventory with both sufficient and intentionally insufficient resources
- generated calendars using the real planning services with deterministic mocked Meteo.lt weather
- blocked, pending, completed, canceled, harvest, and replenishment workflow examples
- harvest records, condition history, rotation history, and a saved rotation draft
- shared plot access for viewer/editor collaboration testing

Notes:

- The seeder is deterministic and rerunnable. It rebuilds only the demo users and their related data.
- It does not call the existing destructive demo seeder.
- Weather generation is mocked inside this seeder so it remains usable when external API calls time out locally.
