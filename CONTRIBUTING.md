# Contributing

Thanks for helping. Clear, focused pull requests are easier to review and
maintain.

## Getting set up

```bash
git clone https://github.com/byrcsc/laravel-data-sync
cd laravel-data-sync
composer install
```

No external database or storage service is needed for the default suite.
Testbench runs it against an in-memory SQLite database.

## The three checks

Run all three before opening a pull request:

```bash
composer test
composer analyse
composer format
```

PHPStan runs at level max with no baseline. Do not add a baseline, suppression,
or cast merely to silence it. Pint is the style authority.

Changes to sync behavior need a test for the relevant invariant: source files
must not be lost, failed runs must remain retryable, and dry runs must have no
side effects. Reader changes should use small fixtures that exercise malformed
input as well as the happy path.

## Commits and branches

Branch from `main` using `feat/…`, `fix/…`, `docs/…`, or `chore/…`. Use
[Conventional Commits](https://www.conventionalcommits.org/) for commit and
pull-request titles.

Do not edit `CHANGELOG.md` in a pull request. Released sections are written
from GitHub release notes.

## Security

Do not open a public issue for a vulnerability or include source credentials,
failed-row payloads, or customer data in a report. Follow
[SECURITY.md](SECURITY.md).
