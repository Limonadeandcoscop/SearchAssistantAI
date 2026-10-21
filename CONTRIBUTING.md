# Contributing

Bug reports and pull requests are welcome.

## Reporting a bug

Open an issue with the module version, the Omeka S and PHP versions,
the LLM and embeddings setup you use, and the steps to reproduce.
Security problems go through the private channel described in
`SECURITY.md`, not through issues.

## Development

Run the module from a checkout inside an Omeka S install
(`modules/SearchAssistantAI`): the test suite needs no database but
loads the Omeka autoloader.

```
composer install
composer test     # PHPUnit
composer stan     # PHPStan level 6
composer ci       # both
```

Guidelines:

- Keep the code compatible with PHP 7.4 (`composer.json` pins the
  platform to 7.4).
- Run `composer ci` before opening a pull request; PHPStan must report
  zero errors.
- Add or update tests for every behaviour change.
- Add new options through the extension points listed in the README
  (registries, service aliases, events) rather than special cases.
- Write code, comments and commit messages in English.
- Update `CHANGELOG.md` under a new "Unreleased" section.

## License

By contributing, you agree that your contributions are licensed under
the GNU General Public License v3.0 or later, like the rest of the
module.
