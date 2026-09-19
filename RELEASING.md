# Release checklist

Before the first public tag and before every subsequent release:

1. Update the version in `blueprints.yaml` and move the relevant entries in
   `CHANGELOG.md` from `Unreleased` to the release version and date.
2. Run `composer validate --strict`, `composer lint`, `composer test` and the
   clean-Grav smoke check: `php tests/clean-grav.php /path/to/clean/grav`.
3. Inspect the package contents. Do not include site configuration, themes,
   route maps, runtime data, logs, credentials or private moderation records.
4. Commit the release, create an annotated tag such as `v1.0.0`, and push the
   commit and tag to the public repository.
5. Verify that Composer can install the tagged package into a clean Grav 2
   site, then publish the release notes.

The package name is `voidlabs/grav-plugin-virtual-collections`. Collection
definitions and route maps stay in the consuming site's configuration.
