# Contributing to Mad Framework

Thanks for your interest in making Mad Framework better. Issues and pull requests are welcome in English or Portuguese.

## How this repository works

Mad Framework is developed together with the MadBuilder platform, in a larger repository where the framework is tested against the apps the platform generates. This repository receives **one snapshot per release**, and that snapshot is what Packagist distributes.

That changes one thing for pull requests: an accepted change is applied to the main repository **with you as the author** and ships in the next release. The pull request here is then closed as merged upstream instead of being merged through GitHub. Your name stays in the history of the release that includes the change.

## Reporting bugs

Open an [issue](https://github.com/madbuilder-dev/mad-framework/issues) with:

- the framework version (the `VERSION` file), PHP, Laravel and database versions;
- the smallest Blade and PHP that reproduce the problem;
- what you expected and what happened, including the error message or a screenshot.

Questions about using MadBuilder itself are best asked in the [MadBuilder forum](https://manager.madbuilder.dev/ajuda/forum).

Security issues follow a different path: see [SECURITY.md](SECURITY.md).

## Pull requests

- **Keep the public contract stable.** Tag names, attributes and public PHP methods are used by apps in production. Adding is easy; renaming or removing needs a migration path and a note in the changelog.
- **One change per pull request**, with a clear description of the problem it solves.
- **Show that it works.** Describe how you tested it. A reproduction that fails before the change and passes after it is the best evidence.
- **Match the surrounding code.** Follow the style of the file you are editing; the project uses PHP 8.4 and Laravel 13.
- **Do not bump `VERSION`.** Maintainers choose the version and write the changelog entry when the release is prepared.

## Changelog

Every release has an entry in [CHANGELOG.md](CHANGELOG.md), written for the people who build screens with the framework: what changed for them and whether they need to do anything. Technical detail belongs in the pull request.
