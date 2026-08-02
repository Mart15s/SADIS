# Roles, permissions, and data sharing

Authorization is evaluated by Laravel on every protected request. Hiding a control in React is a usability choice, not a security boundary. Active membership is required; revoked or inactive memberships grant nothing.

## Account administrator

The legacy account-level `admin` role is a system administrator and bypasses Farm and Community membership checks. It is distinct from a Community Admin or Farm Admin. Normal application users have the legacy account role `owner`; their operational access comes from memberships and explicit grants.

## Farm membership

The canonical permission names are `view_farm`, `manage_fields`, `manage_crops`, `manage_tasks`, `manage_inventory`, `view_analytics`, and `manage_members`.

| Farm role | Default permissions |
| --- | --- |
| `owner`, `admin` | All canonical Farm permissions. |
| `manager` | View Farm; manage Fields, Crops, Tasks, and Inventory; view Farm Analytics. Does not manage members by default. |
| `worker` | View Farm and manage Tasks. |
| `viewer` | View Farm only. |

An explicit row in `farm_member_permissions` overrides the role default for that permission, including an explicit denial. The API prevents removal or demotion of the last active Farm owner.

## Community membership

| Community role | Default permissions |
| --- | --- |
| `admin` | View and administer the Community, members, links, resources, Community Tasks, and Community Inventory. |
| `coordinator` | View; manage Community Tasks and Community Inventory. |
| `resource_manager` | View and manage shared resources. |
| `member` | View Community content available to members. |

The API prevents removal or demotion of the last active Community Admin. A Community role does not by itself grant access to any linked Farm.

## Farm–Community links

A Farm can link to several Communities, and each link has its own lifecycle and grants. A new link is `pending`; an authorized Community Admin may approve or reject it. Revocation sets the link to `revoked` and preserves both the Farm and its records.

The management UI reads the auditable lifecycle through `GET /api/v1/farm-community-links` with exactly one `farm_id` or `community_id` filter. The caller must hold `manage_members` on that scope; the response includes pending, active, rejected, and revoked links without exposing member contact data.

Only an `active` link can bridge access. `farm_access_permissions` is an explicit list of canonical Farm permissions granted through that link to active Community members. Empty means no Farm access. `analytics_scopes` controls optional Community aggregates independently:

- `crop_summary` exposes only the active Crop Season count.
- `harvest_summary` exposes only an aggregate Harvest quantity.
- `task_summary` exposes counts grouped by Task status, never Task notes, assignees, materials, or other Task rows.

The Community Analytics response always limits the base Farm projection to identifier, name, area, coarse location, and link status. It does not load task notes, inventory balances, detailed Harvests, private Crop Conditions, user activity, or member contact information.

## Verification expectations

Test authorization with direct API requests, not only navigation. Include a second Farm and Community, users with every role, revoked memberships, a pending and revoked link, an explicit allow and deny, and an account-level administrator. Confirm that revoking a membership, link, permission, or analytics scope takes effect on the next request.
