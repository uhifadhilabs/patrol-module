# Development

```bash
composer check       # cs:check → phpstan (max) → phpunit
```

Integration tests need the PostGIS test container
(`PATROL_TEST_DATABASE_URL`, see `phpunit.dist.xml`).
