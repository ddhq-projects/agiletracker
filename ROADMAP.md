# ROADMAP — AgileTracker

## MVP

- [ ] new repo [https://github.com/ddhq-projects/agiletracker/pull/1](https://github.com/ddhq-projects/agiletracker/pull/1)
- [ ] integrate favorites to schemas 
  - [ ] here [http://agiletracker.localhost/i/8](http://agiletracker.localhost/i/8) - set as fav or remove
  - [ ] here [http://agiletracker.localhost/i](http://agiletracker.localhost/i) 
  - [ ] here [http://agiletracker.localhost/inbox](http://agiletracker.localhost/inbox) 
  - [ ] here [http://agiletracker.localhost/sent](http://agiletracker.localhost/sent) 
- [ ] add groups
  - [x] with types: project, team, department etc
  - [x] groups access to issues - restrict
  - [x] research how to do in Jira and Planfix - make both options (Planfix-like shipped, Jira-like recorded in the archived RFC)
- [x] move the settings to a separate panel [http://agiletracker.localhost/settings](http://agiletracker.localhost/settings)

- [ ] Alfa 3
  - [x] sidebar - collapsed
  - [x] Roles & permissions (admin, manager, user) via Spatie (Sanctum stays the API auth; Passport was declined as an OAuth2 server is not RBAC)
  - [x] add [https://github.com/relaticle/activity-log](https://github.com/relaticle/activity-log)
    - [x] и добавить раздел в настройки [http://agiletracker.localhost/settings](http://agiletracker.localhost/settings) - для админов

- [ ] beta 1
  - add master setup - like wordpress - if no data - setup first admin user for first auto login
  - merge migrations (one per model + one for settings)
  - [x] Boards (drag & drop, per-board columns, items on many boards; replaces the single Kanban page)

- [x] Sub issues (parent_id in Item)

## Upcoming

### Core features (MVP)

- [x] Item CRUD (Filament Resource) — tasks/bugs/features with fields and statuses
- [x] Labels (tags) CRUD
- [x] Comments on Items
- [x] From + To relationship on Item
- [x] Dashboard with widgets (my tasks, recent activity)

### Phase 2

- plugin-system - docs/rfc/2026-05-30-plugin-system.md

### Future

- [ ] Notifications (due date reminders, assignment alerts)
- [ ] Search & filters (by status, priority, labels)



## Plugins

- [ ] Time tracking (estimated/spent in Item)

## Tooling evaluation


| Tool                 | Status      | Notes                                                                                                                                                                                                                                                                                                               |
| -------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `laravel/boost`      | ✅ Installed | Laravel + PHP guidelines in AGENTS.md. Filament section will appear after installing filament/filament and re-running `boost:install`                                                                                                                                                                               |
| `filament/blueprint` | 🔮 Future   | Paid package for Filament v4+. Generates detailed implementation plans (models, resources, forms, tables, policies, tests). **Re-evaluate after**: migrating to Filament v4 and introducing complex features (workflows, wizards, multi-tenancy). Current project scope (4 models) doesn't justify the license cost |


### When to revisit filament/blueprint

- After upgrading to Filament v4
- When at least 2 of these signals appear:
  - Complex multi-step forms (wizards)
  - Custom Relation Managers
  - Multi-tenancy
  - Reactive fields
  - Non-trivial bulk actions
  - Data import/export