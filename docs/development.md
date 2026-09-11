# Development

## Contents

- [The one command](#the-one-command)
- [The test database](#the-test-database)
- [Migrations](#migrations)

## The one command

```bash
composer check       # cs:check → phpstan (max) → phpunit
```

## The test database

Integration tests run against a real PostGIS database — never SQLite — named by
`PATROL_TEST_DATABASE_URL` in `phpunit.dist.xml`. The local one is the PostGIS
test container on port 5434; CI runs the same image as a service on 5432.

## Migrations

This module owns eleven `patrol_*` tables and ships the SQL that creates and
changes them, in `migrations/` under `Uhifadhi\Patrol\Migrations`. The path is
registered from `UhifadhiPatrolBundle::prependExtension()`, so an installation
configures nothing and writes no version for these tables.

**Every entity change lands with its migration, in the same commit.** Generate
it against a database the whole history has already been applied to, and always
name the namespace — with several namespaces registered the command's default
target is not this one:

```bash
php bin/console doctrine:migrations:diff --namespace='Uhifadhi\Patrol\Migrations'
```

Then read the generated SQL and make it obey the three rules:

1. **Expand, backfill, contract — in one version.** A required column on a table
   an earlier version created is added nullable, filled by this version, then
   tightened. One transaction, so a failure leaves the table untouched and
   nobody edits a vendor file.

   ```php
   $this->addSql('ALTER TABLE patrol_patrol ADD sector VARCHAR(64) DEFAULT NULL');
   $this->addSql("UPDATE patrol_patrol SET sector = 'unassigned' WHERE sector IS NULL");
   $this->addSql('ALTER TABLE patrol_patrol ALTER sector SET NOT NULL');
   ```

2. **A column no backfill can fill for everyone ships nullable**, and the form
   enforces "required". Only the installation knows the value; the database
   stays permissive and the rule lives where the rule is. A later release may
   tighten it, and must refuse with a clear message while null rows remain.

3. **A destructive statement rides a LATER release** than the code that stopped
   using the column or table, and the file says which:

   ```php
   /**
    * @destructive 1.4 — patrol_patrol.legacy_grid stopped being read in 1.3
    */
   ```

A released version is never edited; a mistake in one is corrected by a new
version.

### The versions this module ships

| Class | What it does |
|---|---|
| `Uhifadhi\Patrol\Migrations\Version20260910044923` | The module's eleven tables. |
| `Uhifadhi\Patrol\Migrations\Version20260911090000` | `patrol_settings` — what one area runs patrols on. |
| `Uhifadhi\Patrol\Migrations\Version20260911120000` | `patrol_type` and `patrol_station`, with every existing patrol carried onto them. |

**What an existing installation runs:** `doctrine:migrations:migrate`, and
nothing else. `Version20260911120000` creates the two vocabulary tables, fills
them from the DISTINCT type and station strings the installation's own patrols
carry (one set per area), adds `patrol_patrol.patrol_type_id` and
`station_id` nullable, matches every row, and only then makes the type key NOT
NULL — all in one transaction. It cannot leave a patrol behind: the vocabulary
is read out of the very strings it then matches, and a blank type lands under a
type keyed `unspecified` rather than being skipped. `station_id` stays nullable,
because a patrol that named no station always was one.

`patrol_patrol.type` and `patrol_patrol.station` are deliberately **left in
place** for this release and written from the relations on every save, so a
rollback finds its values where it left them. The version that drops them rides
a later release and will carry the `@destructive` marker.

Back it up first, read it with `--dry-run`, and if a run has to be undone the
hatches are `doctrine:migrations:version --add` and `migrate --write-sql`.

`tests/Integration/Migrations` holds the four locks, and they run in
`composer check`: the path is registered and resolves, a fresh database that has
been migrated has nothing left to `diff`, rows written through this module's own
services survive a further migrate and the history round-trips, and the lint
reads the SQL each version plans and rejects rules 1 and 3 being broken. The
lint's own fixtures live in `tests/Integration/Migrations/Fixtures/Migrations` —
two that break one rule each, two that keep them.
