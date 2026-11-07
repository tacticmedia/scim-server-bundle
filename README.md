# tacticmedia/scim-server-bundle

Symfony bundle for [tacticmedia/scim-server](https://github.com/tacticmedia/scim-server), a SCIM 2.0 server library.
It makes your Symfony application a SCIM service provider for Microsoft Entra ID, Okta and other identity providers.

- Map Doctrine entities or other classes with `#[ScimResource]` and `#[ScimAttribute]`.
- With Doctrine ORM, filters, sorting and paging run in the database through DQL.
- One route, a static token handler for the firewall, SCIM error bodies.
- Custom stores, raw repositories, repository decorators and events.
- `bin/console debug:scim` shows and checks the mappings.

## Installation

Requirements: PHP 8.5 or later, Symfony 8.1 or a later 8.x version.

```bash
composer require tacticmedia/scim-server-bundle symfony/security-bundle
```

With Symfony Flex, this command also registers the bundle. Without Flex, add the bundle to `config/bundles.php`:

```php
return [
    // ...
    TacticMedia\ScimServerBundle\TacticMediaScimServerBundle::class => ['all' => true],
];
```

Then set the access tokens, import the route file, add the firewall and map your classes. The
[documentation](docs/index.md) gives each step. [Identity providers](docs/identity-providers.md) tells what Microsoft
Entra ID, Okta and other identity providers send, and what to map. The
[documentation site](https://tacticmedia.github.io/scim/) also has the setup steps for Entra ID and Okta.

## Coding agents

With [Symfony AI Mate](https://symfony.com/doc/current/ai/components/mate.html), `mate discover` installs the skill
`mate-scim-integration` and the agent instructions of the bundle. Without Mate, tell the agent: "Integrate
tacticmedia/scim-server-bundle. Follow `vendor/tacticmedia/scim-server-bundle/skills/scim-integration/SKILL.md`."

## Contributing

This repository is a read-only split of the [tacticmedia/scim](https://github.com/tacticmedia/scim) monorepo,
directory `scim-server-bundle/`. Send issues and pull requests to the monorepo.

## License

MIT. See [LICENSE](LICENSE).
