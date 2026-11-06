## tacticmedia/scim-server-bundle

- The skill `mate-scim-integration` integrates the bundle into the application.
- Reference: `vendor/tacticmedia/scim-server-bundle/docs/index.md`. What each identity provider sends:
  `vendor/tacticmedia/scim-server-bundle/docs/identity-providers.md`.
- After each change to a `#[ScimResource]` or `#[ScimAttribute]`, run `bin/console debug:scim`. Exit status 1 means
  that a check failed.
