# Agent instructions

This is the public `virtual-collections` plugin for Grav 2.

Keep the plugin slug, Composer package name and runtime contract stable. The
consuming Grav site owns collection definitions, route maps, taxonomies,
themes, accounts, runtime data and secrets; none of those belong in this
repository.

Before changing behavior, run `composer lint` and `composer test`. Keep
`composer.json`, `.github/workflows/ci.yml`, `tests/clean-grav.php` and
`RELEASING.md` synchronized with the supported Grav and PHP versions. Before
publishing, also run the clean-Grav smoke check described in `RELEASING.md`.

Keep public documentation focused on installing and configuring the plugin.
Do not add site history, extraction notes, generated caches, logs, credentials,
private moderation records or other runtime data.
