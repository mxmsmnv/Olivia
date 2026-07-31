# Contributing to Olivia

Olivia is released software. Contributions should preserve the supported Create flow, preview-before-Build rule, additive changes, and complete Undo manifests.

## Before opening a change

1. Search existing issues and describe the user-visible problem.
2. Keep changes focused and follow the existing ProcessWire patterns.
3. Never commit provider keys, customer content, private URLs, generated runtime data, or local configuration.
4. Add focused smoke coverage when behavior changes.

## Verification

Lint every changed PHP file with PHP 8.1 or newer and test the affected workflow
on a disposable ProcessWire development site. GitHub Actions repeats PHP syntax
checks on PHP 8.1 and 8.4. Maintainer release tooling is intentionally local.

## Pull requests

Explain the failure mode, the chosen fix, and the verification performed. Call out compatibility or rollback implications explicitly. Keep unrelated refactors out of the same pull request.

Report vulnerabilities privately using [SECURITY.md](SECURITY.md), not a public issue.
