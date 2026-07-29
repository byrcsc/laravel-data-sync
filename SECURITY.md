# Security Policy

## Reporting a vulnerability

Report vulnerabilities privately through
[GitHub security advisories](https://github.com/byrcsc/laravel-data-sync/security/advisories/new).
Do not open a public issue.

Include the package, Laravel, and PHP versions and the smallest safe
reproduction you can provide. Remove credentials, source files, failed-row
payloads, and personal information before sharing logs.

You can expect an acknowledgement within one week. Security fixes are released
for the latest package version and are not backported.

## Data-handling boundary

This package will move and optionally retain source data. Applications remain
responsible for:

- limiting access to staging and archive disks;
- selecting retention periods appropriate to their data;
- disabling failed-payload capture for sensitive feeds;
- encrypting storage and credentials at rest;
- granting queue workers only the source and destination permissions they
  require.

The package cannot make an insecure host filesystem, database, or remote
storage connection secure.
