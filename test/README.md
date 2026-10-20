# Search Assistant AI module: running the test suite

## Quick start (inside the `omeka-s` container)

```bash
docker exec omeka-s sh -c "cd /var/www/html/modules/SearchAssistantAI && composer install"
docker exec omeka-s sh -c "cd /var/www/html/modules/SearchAssistantAI && composer test"
```

## Prerequisites

The suite is pure unit tests: no database, no network. `test/bootstrap_unit.php`
loads the host Omeka autoloader (`../../vendor/autoload.php`), so run it from a
module checkout inside an Omeka S install, on PHP 7.4 or later.

Search Assistant AI Pro's suite reuses `test/Support/` (`ModuleServices`, a container
built from `config/module.config.php` plus extra config, and
`ArraySettingsTrait`), so keep those helpers backward compatible.

## Why PHPUnit 9 and not 10

Omeka S core ships PHPUnit 9.6 and registers its autoloader first when
the test bootstrap `require`s the Omeka installation. If the module
depends on PHPUnit 10, the two autoloaders resolve different versions
of `PHPUnit\Framework\TestSuite`, and PHPUnit 10 code-paths call
`TestSuite::empty()` which doesn't exist in 9.x, so the suite blows up
before a single test runs. Keeping `phpunit/phpunit: ^9.6` in
`composer.json` avoids the collision.

## Layout

```
test/
├── bootstrap_unit.php               PHPUnit bootstrap (autoloaders, no DB)
├── Architecture/                    Free code never uses SearchAssistantAIPro
├── Config/                          Routes and admin navigation
├── Form/                            Form / fieldset validation tests
├── Job/                             Doctrine-backed job tests
├── Permissions/                     ACL and chat access policy
├── Service/                         Service-layer tests
├── Support/                         Test container and settings helpers
├── Fixtures/                        JSON test fixtures
├── js/                              Jest-style JS tests (not run by `composer test`)
└── scripts/                         Manual smoke scripts (NOT run by `composer test`)
```

## Current status

The suite was restored in v1.3.0 after being dropped during the PHP 7.4
compatibility work, and ported to PHP 7.4 syntax.

Controllers are deliberately uncovered at the unit level: they're better
exercised by HTTP / browser integration tests than by mocking Laminas MVC
plumbing. `SemanticSearchService`, `SyncQueueRepository` and `BatchIndexer` still
need coverage.
