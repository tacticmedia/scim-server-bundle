# tacticmedia/scim-server-bundle

Symfony bundle for [tacticmedia/scim-server](https://github.com/tacticmedia/scim-server), a SCIM 2.0 server library.

## Installation

Requirements: PHP 8.5 or later, Symfony 8.1 or a later 8.x version.

```bash
composer require tacticmedia/scim-server-bundle
```

With Symfony Flex, this command also registers the bundle. Without Flex, add the bundle to `config/bundles.php`:

```php
return [
    // ...
    TacticMedia\ScimServerBundle\TacticMediaScimServerBundle::class => ['all' => true],
];
```

[Documentation](docs/index.md)

## Contributing

This repository is a read-only split of the [tacticmedia/scim](https://github.com/tacticmedia/scim) monorepo,
directory `scim-server-bundle/`. Send issues and pull requests to the monorepo.

## License

MIT. See [LICENSE](LICENSE).
