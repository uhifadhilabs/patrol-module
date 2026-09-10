# Development

## Contents

- [The one command](#the-one-command)
- [The test database](#the-test-database)

## The one command

```bash
composer check       # cs:check → phpstan (max) → phpunit
```

## The test database

Integration tests run against a real PostGIS database — never SQLite — named by
`PATROL_TEST_DATABASE_URL` in `phpunit.dist.xml`. The local one is the PostGIS
test container on port 5434; CI runs the same image as a service on 5432.
