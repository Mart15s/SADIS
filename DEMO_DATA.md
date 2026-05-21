# Demo Data

This project includes a deterministic presentation dataset for the bachelor thesis live demo.

## How to rebuild the demo database

From `backend/`:

```powershell
$env:RUN_DEMO_SEEDER='true'
php artisan migrate:fresh --seed
```

If the schema is already up to date and you only want to recreate the demo dataset:

```powershell
php artisan db:seed --class=CurrentVersionDemoSeeder
```

`DatabaseSeeder` intentionally does not create demo users unless `RUN_DEMO_SEEDER=true`. `DemoDataSeeder` remains a compatibility alias for older commands and calls `CurrentVersionDemoSeeder`.

## Demo accounts

All current demo accounts use the same password:

```text
password
```

- `demo.owner@example.test` - main garden owner
- `demo.editor@example.test` - collaborator with editor access
- `demo.viewer@example.test` - collaborator with viewer access
- `demo.neighbor@example.test` - community variety account
- `demo.community@example.test` - public community posts account

## Demo garden world

### Main owner

The `demo.owner@example.test` account is the main account for the walkthrough. The data is intentionally the richest.

- `Oakridge Kitchen Garden`
  - Main outdoor family vegetable garden in Vilnius.
  - Contains zones for tomatoes, cucumbers, root vegetables, herbs, strawberries, flowers, legumes, brassicas, corn, and squash.
  - Includes shared access for both an editor and a read-only viewer.
  - Has plot snapshots, condition history, harvest history, task history, and a recommendation calendar.

- `South Fence Berry and Orchard Strip`
  - Smaller perennial strip for berries, mint, and a young apple tree.
  - Useful for showing permanent crops and rotation exclusions.

## What the seeded data demonstrates

- Account roles and plot-level collaboration: owner accounts plus viewer/editor access rights
- Plot layouts with valid geometry for plot/zone visualization
- Plants in varied lifecycle states: newly planted, germinating, growing, flowering, mature, regenerating, and post-harvest
- Historical and current condition tracking
- Harvest history and crop rotation context
- Inventory with both sufficient and insufficient resources
- Calendar tasks with pending, completed, canceled, harvest, and replenishment workflow examples
- Weather-backed calendar data without requiring live external APIs during seeding
- Community posts linked to real plots
- Enough history for analytics, dashboard summaries, and detail pages to feel populated

## Suggested live demo flow

1. Log in as `demo.owner@example.test` and open the dashboard to show populated summary cards.
2. Open `Oakridge Kitchen Garden` to show the plot plan, zones, plants, sharing, and history.
3. Open a plant detail page to show condition changes and harvest/rotation context.
4. Open the calendar to show overdue, completed, upcoming, and inventory-blocked tasks.
5. Complete an actionable pending resource task, such as tomato tying or pepper feeding, then open inventory to show the normal workflow reducing stock.
6. Switch to `demo.editor@example.test` or `demo.viewer@example.test` to demonstrate collaboration access differences.
7. Open the community area to show public posts from more than one garden owner.

## Notes

- The demo seed is deterministic: the important users, plots, and key records keep the same identities and narrative structure between rebuilds.
- The seeder uses application models and services so the generated dataset stays aligned with real business logic, task workflow behavior, inventory effects, and calendar generation.
