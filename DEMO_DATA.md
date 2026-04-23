# Demo Data

This project includes a deterministic presentation dataset for the bachelor thesis live demo.

## How to rebuild the demo database

From `backend/`:

```bash
php artisan migrate:fresh --seed
```

If the schema is already up to date and you only want to recreate the demo dataset:

```bash
php artisan db:seed --class=DemoDataSeeder
```

## Demo accounts

All demo accounts use the same password:

```text
DemoGarden2026!
```

- `aiste@demo.sad.lt` - main garden owner
- `mantas@demo.sad.lt` - collaborator with editor access
- `rasa@demo.sad.lt` - collaborator with viewer access
- `admin@demo.sad.lt` - administrator account
- `lina@demo.sad.lt` - second garden owner for community/demo feed variety

## Demo garden world

### Aistė Petrauskaitė

Main account for the walkthrough. Her data is intentionally the richest.

- `Namų daržas`
  - Main outdoor family vegetable garden in Vilnius.
  - Contains zones for tomatoes, cucumbers, root vegetables, herbs, and strawberries.
  - Includes shared access for both an editor and a read-only viewer.
  - Has plot snapshots, condition history, harvest history, task history, and a live recommendation calendar.

- `Šiltnamis`
  - Smaller greenhouse plot used for tomatoes, peppers, cucumbers, and basil.
  - Useful for showing different seasonality and a second active calendar.

### Lina Kazlauskienė

- `Uogų kampas`
  - Smaller secondary garden used to make the community feed and cross-user views feel real.

## What the seeded data demonstrates

- Multiple roles: owner, admin, editor-style collaboration, and viewer-style sharing
- Plot layouts with valid geometry for plot/zone visualization
- Plants in varied lifecycle states: newly planted, germinating, growing, flowering, mature, regenerating, and post-harvest
- Historical and current condition tracking
- Harvest history and crop rotation context
- Inventory with both sufficient and insufficient resources
- Manual historical tasks plus generated live calendars
- Mixed task states: pending, completed, and canceled
- Weather-backed calendar generation without requiring live external APIs during seeding
- Community posts linked to real plots
- Enough history for analytics, dashboard summaries, and detail pages to feel populated

## Suggested live demo flow

1. Log in as `aiste@demo.sad.lt` and open the dashboard to show populated summary cards.
2. Open `Namų daržas` to show the plot plan, zones, plants, sharing, and history.
3. Open a plant detail page to show condition changes and harvest/rotation context.
4. Open the calendar to show overdue, completed, upcoming, and inventory-blocked tasks.
5. Open inventory to show both available resources and shortages.
6. Switch to `mantas@demo.sad.lt` or `rasa@demo.sad.lt` to demonstrate collaboration access differences.
7. Log in as `admin@demo.sad.lt` to show the administration screen with multiple realistic users.
8. Open the community area to show public posts from more than one garden owner.

## Notes

- The demo seed is deterministic: the important users, plots, and key records keep the same identities and narrative structure between rebuilds.
- The seeder uses application models and services so the generated dataset stays aligned with real business logic, task workflow behavior, inventory effects, and calendar generation.
