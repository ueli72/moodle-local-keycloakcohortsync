# local_keycloakcohortsync

Moodle 5.2 local plugin to synchronise Keycloak group memberships into existing Moodle system cohorts.

## Mapping

The mapping is intentionally simple and stable:

```
Keycloak group.name == Moodle cohort.idnumber
```

The visible Moodle cohort name is irrelevant. Only cohorts explicitly listed in the plugin setting **Managed cohort IDs** are modified. Other/manual cohorts remain untouched.

## User matching

Default: Keycloak `email` -> Moodle `user.email`.

This is recommended for BWS Uster because existing Moodle usernames may differ from Keycloak usernames. The plugin refuses ambiguous Moodle email matches.

## Keycloak service account

Create a separate confidential Keycloak client, e.g. `moodle-cohort-sync`:

1. Client authentication: ON
2. Service accounts roles: ON
3. Standard flow: OFF (not required)
4. Assign the service account the minimum realm-management permissions required to list users and their groups (typically `view-users` and `query-users`; verify in your Keycloak version).
5. Copy the generated client secret into Moodle plugin settings.

Do not reuse or expose the interactive Moodle OIDC client secret unnecessarily.

## Moodle installation

Copy the directory to:

```
local/keycloakcohortsync
```

Then run Moodle upgrade / Notifications, or CLI:

```
php admin/cli/upgrade.php
```

Configure under:

Site administration -> Plugins -> Local plugins -> Keycloak Cohort Sync

Suggested BWS values:

```
Base URL: https://sso.bws-uster.ch
Realm: simsysbwsu
User matching: email
Dry-run: ON initially
```

Example managed cohort IDs:

```
BGH
BTR
BUS
CHA
COM
FEM
GHG
GSS
HGD
INT
Instruktoren
Lehrpersonen
Schueler
Schulleitung
SCI
Sekretariat
SPI_I
SPI_II
ZammadAdmin
ZIB
ZIVI
```

Only list cohorts that Keycloak is allowed to own.

## Behaviour

For each Keycloak user that can be matched to exactly one Moodle user:

- group present in Keycloak + listed as managed + cohort exists -> membership is added;
- managed cohort membership absent in Keycloak -> membership is removed;
- cohorts not listed as managed -> untouched;
- disabled Keycloak users are treated as having no managed groups;
- Keycloak users with no matching Moodle account are skipped.

The scheduled task runs every 15 minutes. Start with Dry-run enabled and inspect task output before enabling writes.

## Manual run

```
php local/keycloakcohortsync/cli/sync.php
```

After validating dry-run output, disable Dry-run in Moodle settings and run again.

## Notes

This plugin uses Keycloak Admin REST API rather than claims from the user's login token. This ensures group changes are synchronised even when users do not log in.
