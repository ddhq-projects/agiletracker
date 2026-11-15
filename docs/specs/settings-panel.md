---
title: "Settings panel"
status: done
completed: 2026-11-15
rfc: ../rfc/2026-11-15-settings-panel-item-custom-fields.md
---

# Spec: Settings panel

## What was done

Administrative configuration lives in its own Filament panel, mounted at
`/settings` and open to the `admin` role only. The panel contains the
General settings page and user management; the working panels keep everything
else.

- `app/Providers/Filament/SettingsPanelProvider.php` registers the panel with the
  id `settings` and the path `settings`. It reuses the main panel's theme
  (`resources/css/filament/admin/theme.css`), primary colour, session middleware
  and the collapsed-sidebar hook, has its own login at `/settings/login`
  and is not the default panel.
- `app/Filament/Settings/Pages/ManageSettings.php` is the General settings page.
  It overrides the route path to `/`, so General stays at `/settings`
  instead of `/settings/settings`. It still edits
  `App\Settings\GeneralSettings` through `filament/spatie-laravel-settings-plugin`.
- `app/Filament/Settings/Resources/UserResource.php` (with its List/Create/Edit
  pages) serves user management at `/settings/users`. `UserPolicy` restricts
  create, update and delete to `users.manage`.
- `App\Filament\Settings\Resources\GroupResource.php` serves typed groups at
  `/settings/groups` (see [access-control.md](access-control.md)).
- `App\Filament\Settings\Resources\ActivityResource.php` serves the read-only
  audit journal at `/settings/activity-log` (see
  [access-control.md](access-control.md)).
- The settings panel is reached through `settings.manage`: the user-menu action
  and `ManageSettings::canAccess()` ask for that permission, while
  `App\Models\User::canAccessPanel()` keeps the whole `settings` panel at
  `hasRole('admin')` and every other panel open. A non-admin therefore gets a
  403 on any settings route while keeping full access to the main panel.
- The main panel's sidebar no longer carries the General and Users items, the
  `Settings` group or the GitHub link. Settings lives in the user menu (topbar
  avatar) as an `Action` visible only to whoever holds `settings.manage`,
  pointing at the new panel.
- The GitHub link moved into the settings panel's sidebar, next to "Back to app",
  and still opens in a new tab.
- The settings panel leads back out: a "Back to app" button in the topbar
  (`resources/views/filament/settings-back.blade.php`, mounted on
  `PanelsRenderHook::TOPBAR_START`) and an equally labelled navigation item at
  the top of its sidebar both return to `/`.
- `app/Filament/Pages/Settings.php` (the disabled legacy `app_settings` page) is
  untouched and still not discovered.

## Key decisions

- **Pages and resources move, they are not filtered out.** Adopting
  `App\Filament\Settings\*` namespaces and pointing each panel's
  `discoverResources`/`discoverPages` at its own directory makes the separation
  structural: the main panel cannot pick up an admin-only resource, and a new
  admin page cannot leak into the working panel.
- **The settings panel has its own login.** Filament's `Authenticate` middleware
  redirects guests to the current panel's login URL; a panel without `login()`
  has no such URL and falls back to an unscoped redirect. Guests therefore land
  on `/settings/login`, which authenticates against the same guard, and
  non-admins arriving there are rejected by `canAccessPanel()`.
- **Authorization is kept in two layers.** `canAccessPanel()` guards the whole
  panel, `ManageSettings::canAccess()` still guards the page itself, so the page
  stays closed even if a future panel is opened up.
- **No new settings storage.** `GeneralSettings` and the `settings` table are
  reused as-is; this change is about placement and access, not the settings
  model.

## Deviations from RFC

- The RFC pinned the panel path to `manage-settings`; the shipped path is
  `/settings`, which is shorter and leaves room for the pages below it.
- The RFC asked for "the existing Laravel authentication" on the new panel; in
  practice the panel enables Filament's login for the redirect described above.
- The RFC's navigation item "Settings" was also meant to replace the `Settings`
  navigation group. The group list in the main panel is dropped only because
  nothing else uses it; if a future admin page returns there, the group comes
  back with it.
- The "Back to app" button is a small addition on top of the RFC's navigation
  plan: the panel would otherwise have no route back to the workspace.

## Known limitations / next steps

- **The user form assigns one base role.** `App\Actions\AssignUserRole` refuses
  to remove the last administrator; role assignment stays in the admin-only
  panel and is never exposed through the API. See
  [access-control.md](access-control.md).
- The rest of the RFC — `relaticle/custom-fields` 3.x, the `meta` backfill, the
  system `type` field and the REST v1 adapter — is not implemented and stays
  blocked on the licensing decision recorded in the RFC's open questions.
  Nothing in this panel change depends on it.
