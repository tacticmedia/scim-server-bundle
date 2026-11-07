---
name: scim-integration
description: Integrates tacticmedia/scim-server-bundle into a Symfony application, so that Microsoft Entra ID, Okta and other identity providers provision its users and groups over SCIM 2.0. Use when the user asks to add SCIM, automatic user provisioning or deprovisioning, an Entra ID or Okta provisioning connection, or to map entities with #[ScimResource] and #[ScimAttribute].
---

# SCIM integration

The installed package contains the reference documentation of its version. Read both files before you change code:

- `vendor/tacticmedia/scim-server-bundle/docs/index.md`: configuration, routes, firewall, mapping, stores, events,
  errors and limits.
- `vendor/tacticmedia/scim-server-bundle/docs/identity-providers.md`: what each identity provider sends, and what to
  map.

## 1. Inspect the application

Find these facts in the code:

- The PHP and Symfony versions in `composer.lock`. The bundle needs PHP 8.5 and Symfony 8.1 or a later 8.x version.
- Which of `tacticmedia/scim-server-bundle`, `symfony/security-bundle`, `doctrine/orm` and `doctrine/doctrine-bundle`
  are installed.
- The configuration format in `config/packages/`, YAML or PHP. Use the same format.
- The `exclude` of the `App\` resource in `config/services.yaml` or `config/services.php`. The bundle finds mapped
  classes through autoconfiguration, so an excluded `src/Entity/` hides them.
- The user entity and the group or team entity, their id types and their associations.
- The firewalls and the `access_control` rules.
- How the other functional tests set up the database.

## 2. Ask the user

Ask only what the code does not answer:

1. Which identity providers provision the application: Microsoft Entra ID, Okta, or another one.
2. Which entity is the SCIM `User` and which is the SCIM `Group`, when it is not clear. Whether groups are necessary.
3. Whether the identity provider sets passwords. Entra never sends passwords. Okta sends a password on create also
   without password sync, unless **Set password when creating new users** is off. Map `password` only for password
   sync.
4. Which attributes the application must not take from the identity provider, for example roles or permissions.

## 3. Install

```bash
composer require tacticmedia/scim-server-bundle symfony/security-bundle
```

Without Symfony Flex, add `TacticMedia\ScimServerBundle\TacticMediaScimServerBundle::class => ['all' => true]` to
`config/bundles.php`.

## 4. Configure

Do the sections Configuration, Routes and Firewall of `docs/index.md`. Keep these rules:

- Read the token from an environment variable, for example `%env(SCIM_TOKEN)%`. Put an empty `SCIM_TOKEN=` in `.env`
  and `SCIM_TOKEN=test-token` in `.env.test`. Never commit a real token. `openssl rand -base64 48` makes one.
- Use the prefix `/scim/v2`. Okta rejects a base URL with `_`.
- Put the `scim` firewall before the other firewalls.
- Use the firewall `pattern` and the `access_control` path `^/scim/v2`, without a trailing `/`.
- Keep the `access_control` rule with `ROLE_SCIM_CLIENT`.
- Add `password_hashers` only when you map `password`.

## 5. Map

1. Make the attribute list: the rows of each identity provider in `docs/identity-providers.md`. Tell the user which
   rows to delete in the identity provider instead of mapping them.
2. Put `#[ScimResource('User')]` and `#[ScimResource('Group')]` on the entities. For each attribute of the list, put
   `#[ScimAttribute]` on a property, and add a Doctrine column for each new property.
3. Give each writable property a setter, or make it public. Make each optional property nullable, or give its mapping
   `default:`.
4. Give each reference collection, for example `members[type eq "User"]`, an adder and a remover.
5. Map `meta.created` and `meta.lastModified` when the entity has creation and change dates.
6. Give `userName` and the group `displayName` a unique index. The bundle publishes both as unique by default.
7. Never hide inactive users. Identity providers deactivate a user with `active` false and read the user back.

`docs/index.md` gives the rules for constructor parameters, ids, references, converters and passwords.

## 6. Database

Make a migration with the tool of the project, for example `bin/console make:migration`. Do not run it against a
shared or production database.

## 7. Verify

Run these commands and fix each error before you continue:

```bash
bin/console cache:clear
bin/console debug:scim
bin/console debug:router tacticmedia_scim_server
bin/console debug:firewall scim
```

`debug:scim` exits with status 1 when a check fails. Each message names the class, the member and the fix. Without
resource types, it tells how to fix the discovery.

Then start the application, set `BASE` to the base URL and `TOKEN` to the token, and send:

```bash
curl -i "$BASE/Users"
curl -i -H "Authorization: Bearer $TOKEN" "$BASE/ServiceProviderConfig"
curl -i -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/scim+json' -X POST "$BASE/Users" \
  -d '{"schemas":["urn:ietf:params:scim:schemas:core:2.0:User"],"userName":"test.user@example.com","active":true}'
```

Expect 401, 200 and 201. Then send a PATCH for each path of the attribute list, with the `id` of the POST response,
and expect 204:

```bash
curl -i -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/scim+json' -X PATCH "$BASE/Users/$ID" \
  -d '{"schemas":["urn:ietf:params:scim:api:messages:2.0:PatchOp"],"Operations":[{"op":"replace","path":"title","value":"Engineer"}]}'
```

400 `invalidPath` means that the class does not map the path.

## 8. Test

Add a functional test. Use the database setup of the other functional tests of the project. This test sends the
requests of Entra for a new user and a disabled user:

```php
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ScimProvisioningTest extends WebTestCase
{
    #[TestDox('Entra creates a user, disables it with "False", and still finds it')]
    public function testDisabledUserStaysVisible(): void
    {
        $client = self::createClient(server: [
            'HTTP_AUTHORIZATION' => 'Bearer test-token',
            'CONTENT_TYPE' => 'application/scim+json',
        ]);

        $client->request('POST', '/scim/v2/Users', content: json_encode([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'userName' => 'test.user@example.com',
            'active' => true,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $id = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['id'];

        $client->request('PATCH', '/scim/v2/Users/'.$id, content: json_encode([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => 'False']],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/scim/v2/Users?filter='.rawurlencode('userName eq "test.user@example.com"'));
        self::assertResponseIsSuccessful();
        $list = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(1, $list['totalResults']);
        self::assertFalse($list['Resources'][0]['active']);
    }
}
```

Add a PATCH for each path of the attribute list. Run `bin/console debug:scim` in CI.

## 9. Tell the user

Give the user the values for the identity provider: the base URL, for example `https://example.com/scim/v2`, and where
the token is set. Tell the user which attribute rows to delete in the identity provider.
`docs/identity-providers.md` gives the connection settings of each identity provider. Give the user the link to the
setup steps in the admin console: https://tacticmedia.github.io/scim/identity-providers/entra-id/ for Entra,
https://tacticmedia.github.io/scim/identity-providers/okta/ for Okta.
