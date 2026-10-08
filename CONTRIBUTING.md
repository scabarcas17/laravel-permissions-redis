# Contributing to Laravel Permissions Redis

Thanks for considering a contribution.

## Development Setup

### Requirements

- PHP 8.3+
- Redis 6.0+
- Composer

### Getting Started

```bash
# Clone the repository
git clone https://github.com/scabarcas17/laravel-permissions-redis.git
cd laravel-permissions-redis

# Install dependencies
composer install
```

### Running Tests

```bash
# Run the full test suite
composer test

# Run a specific test file
vendor/bin/pest tests/Unit/PermissionResolverTest.php

# Run tests with a filter
vendor/bin/pest --filter="it can assign a role"
```

The `tests/Redis/` contract suite talks to a real Redis server and is skipped
automatically when nothing listens on `127.0.0.1:6379`. It uses `predis` by
default; run it with `phpredis` as well, since the two clients differ in
argument and reply shapes:

```bash
# Redis contract suite with predis (default) and with phpredis
vendor/bin/pest --testsuite Redis
PERMISSIONS_REDIS_TEST_CLIENT=phpredis vendor/bin/pest --testsuite Redis
```

Environment overrides: `PERMISSIONS_REDIS_TEST_HOST`, `PERMISSIONS_REDIS_TEST_PORT`,
`PERMISSIONS_REDIS_TEST_DB` (defaults to `15`), `PERMISSIONS_REDIS_TEST_CLIENT`
(`predis` or `phpredis`) and `PERMISSIONS_REDIS_TEST_SKIP=1`.

### Code Style

This project uses [Laravel Pint](https://laravel.com/docs/pint) for code formatting:

```bash
# Check formatting
vendor/bin/pint --test

# Fix formatting
composer format
```

### Static Analysis

This project uses [PHPStan](https://phpstan.org/) at maximum level with [Larastan](https://github.com/larastan/larastan):

```bash
composer analyse
```

## How to Contribute

### Reporting Bugs

Before creating a bug report, please check [existing issues](https://github.com/scabarcas17/laravel-permissions-redis/issues) to avoid duplicates.

When filing a bug report, include:

- PHP version
- Laravel version
- Redis version and extension (`phpredis` or `predis`)
- Package version
- Steps to reproduce
- Expected vs actual behavior

### Suggesting Features

Feature requests are welcome. Open an issue with the `enhancement` label and describe:

- The problem you're trying to solve
- Your proposed solution
- Any alternatives you've considered

### Pull Requests

1. Fork the repository
2. Create a feature branch from `main`:
   ```bash
   git checkout -b feature/my-feature
   ```
3. Make your changes following the conventions below
4. Add or update tests for your changes
5. Ensure all checks pass:
   ```bash
   composer test && composer format && composer analyse
   ```
6. Commit using [Conventional Commits](https://www.conventionalcommits.org/):
   ```
   feat: add UUID support for model IDs
   fix: resolve cache invalidation on role sync
   docs: update middleware usage examples
   test: add coverage for wildcard permissions
   ```
7. Push and open a pull request against `main`

### Branch Naming

| Prefix | Purpose |
|--------|---------|
| `feature/` | New features |
| `fix/` | Bug fixes |
| `docs/` | Documentation only |
| `test/` | Test additions or fixes |
| `refactor/` | Code refactoring (no behavior change) |

## Code Conventions

- **Strict types**: Every PHP file must declare `declare(strict_types=1)`
- **Type hints**: All method parameters and return types must be typed
- **PSR-12**: Code style enforced via Laravel Pint
- **PHPStan max**: All code must pass PHPStan at maximum level
- **Tests required**: Every feature or bug fix must include tests

## Questions?

If you have questions about contributing, open a [Discussion](https://github.com/scabarcas17/laravel-permissions-redis/discussions) on GitHub.
