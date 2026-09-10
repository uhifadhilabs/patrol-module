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

`tests/Integration/Migrations` holds the four locks, and they run in
`composer check`: the path is registered and resolves, a fresh database that has
been migrated has nothing left to `diff`, rows written through this module's own
services survive a further migrate and the history round-trips, and the lint
reads the SQL each version plans and rejects rules 1 and 3 being broken. The
lint's own fixtures live in `tests/Integration/Migrations/Fixtures/Migrations` —
two that break one rule each, two that keep them.
