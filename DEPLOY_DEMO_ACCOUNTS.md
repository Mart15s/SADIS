# Current Demo Deployment Accounts

These accounts are created by `CurrentVersionDemoSeeder` for demonstration deployments.

All accounts use the password:

```text
password
```

| Role in demo | Email | Purpose |
| --- | --- | --- |
| Main owner | `demo.owner@example.test` | Owns Oakridge Kitchen Garden and the berry/orchard strip. |
| Shared editor | `demo.editor@example.test` | Has editor access to the primary plot. |
| Shared viewer | `demo.viewer@example.test` | Has viewer access to the primary plot. |
| Neighbor | `demo.neighbor@example.test` | Creates community discussion posts. |
| Community member | `demo.community@example.test` | Creates public community advice and showcase posts. |

Render demo seeding:

```env
RUN_MIGRATIONS=true
RUN_DEMO_SEEDER=true
DEMO_SEEDER_CLASS=CurrentVersionDemoSeeder
```

Disable `RUN_DEMO_SEEDER` after the redeploy confirms seeding succeeded.
