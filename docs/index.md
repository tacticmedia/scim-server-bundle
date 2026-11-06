# TacticMediaScimServerBundle

The bundle makes a Symfony application a SCIM 2.0 service provider (RFC 7643, RFC 7644). It connects
[tacticmedia/scim-server](https://github.com/tacticmedia/scim-server) to your classes, to Doctrine ORM and to the
Symfony firewall. Identity providers such as Microsoft Entra ID and Okta can then create, change and delete your users
and groups.

You put attributes on your entities. The bundle publishes the schemas, maps documents to objects, translates filters to
DQL, and answers every SCIM endpoint.

## Installation

Requirements: PHP 8.5 or later, Symfony 8.1 or a later 8.x version. For Doctrine storage: `doctrine/orm` 3.7 or later
and `doctrine/doctrine-bundle` 3.3 or later. The bundle registers the Doctrine ORM store only when `DoctrineBundle` is
registered. For the firewall: `symfony/security-bundle`.

```bash
composer require tacticmedia/scim-server-bundle symfony/security-bundle
```

Symfony Flex registers the bundle. Without Flex, add it to `config/bundles.php`:

```php
return [
    // ...
    TacticMedia\ScimServerBundle\TacticMediaScimServerBundle::class => ['all' => true],
];
```

Do these four steps:

1. Set the access tokens ([Configuration](#configuration)).
2. Import the route file ([Routes](#routes)).
3. Add the firewall ([Firewall](#firewall)).
4. Map your classes ([Mapping](#mapping)). [Identity providers](identity-providers.md) tells what to map for each
   identity provider.

## Configuration

```yaml
# config/packages/tacticmedia_scim_server.yaml
tacticmedia_scim_server:
    access_tokens:
        entra: '%env(SCIM_TOKEN_ENTRA)%'
        okta: '%env(SCIM_TOKEN_OKTA)%'
    max_results: 200
    bulk:
        enabled: true
        max_operations: 1000
        max_payload_size_bytes: 1048576
```

| Key | Default | Meaning |
| --- | --- | --- |
| `resources` | `[]` | `#[ScimResource]` classes that autoconfiguration does not find ([Discovery](#discovery)). |
| `access_tokens` | `[]` | Static tokens by client name. The client name is the user identifier of the client. |
| `max_results` | `200` | The largest page, and the page size when a request has no `count`. |
| `bulk.enabled` | `true` | `false` makes `/Bulk` answer 501. |
| `bulk.max_operations` | `1000` | The largest number of operations in one bulk request. |
| `bulk.max_payload_size_bytes` | `1048576` | The largest bulk request body. |
| `rate_limit.enabled` | `false` | `true`, or any other `rate_limit` key, limits the requests of each client ([Rate limiting](#rate-limiting)). |
| `rate_limit.limit` | `500` | The size of the token bucket of a client: the largest burst. |
| `rate_limit.rate.interval` | `1 second` | The refill period. |
| `rate_limit.rate.amount` | `50` | The tokens that each refill period adds. |
| `rate_limit.cache_pool` | `cache.rate_limiter` | The cache pool of the buckets. |
| `rate_limit.limiter` | none | The service id of a `RateLimiterFactoryInterface`. It replaces `limit`, `rate` and `cache_pool`. |
| `me.enabled` | `false` | `true`, or any other `me` key, turns `/Me` on ([/Me](#me)). Off, `/Me` answers 501. |
| `me.resource_type` | `User` | The resource type of `/Me`. A name that no resource type has stops the container build. |
| `me.attribute` | `userName` | The attribute that equals the user identifier of the security token. It must be `id` or support `eq` in `resource_type`, else the container build stops. |
| `me.resolver` | none | The service id of a `MeResolver`. It replaces `resource_type` and `attribute`. |
| `pagination.cursor.enabled` | `true` | RFC 9865 cursors ([Cursor pagination](#cursor-pagination)). `false`: the server ignores `cursor`. |
| `pagination.cursor.key` | `%kernel.secret%` | The key that encrypts the cursors. |
| `pagination.cursor.timeout_seconds` | none | The lifetime of a cursor. None: cursors do not expire. |

A token must match `[a-zA-Z0-9\-_\+~\/\.]+=*`. The output of `openssl rand -base64 48` matches. A token with other
characters never authenticates.

`/ServiceProviderConfig` publishes these values and the authentication scheme `oauthbearertoken`. It publishes
`changePassword.supported: true` when a resource type publishes the RFC 7643 `User` attribute `password`.

## Routes

```yaml
# config/routes/tacticmedia_scim_server.yaml
tacticmedia_scim_server:
    resource: '@TacticMediaScimServerBundle/config/routes.php'
    prefix: /scim/v2
```

The file has one route, `tacticmedia_scim_server`, with the path `/{path}` and every method. The prefix is the SCIM base
URL. The server dispatches every SCIM endpoint below it, and answers a method that the endpoint does not allow, for
example `OPTIONS`, with 405 and the `Allow` of the endpoint. A request without a token gets 401 from the firewall
first.

- Okta rejects a base URL with `_`. Do not put `_` in the prefix.
- The prefix can have parameters, for example `/scim/{tenant}/v2`. Match the firewall to it ([Firewall](#firewall)).
- Give the identity provider the full URL, for example `https://example.com/scim/v2`.

## Firewall

```yaml
# config/packages/security.yaml
security:
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: auto
    firewalls:
        scim:
            pattern: ^/scim/v2
            stateless: true
            access_token:
                token_handler: tacticmedia_scim_server.access_token_handler
                token_extractors: tacticmedia_scim_server.access_token_extractor
                failure_handler: tacticmedia_scim_server.authentication_failure_handler
        main:
            # the firewall of your application
    access_control:
        - { path: ^/scim/v2, roles: ROLE_SCIM_CLIENT }
```

- Put the `scim` firewall before your other firewalls.
- Use `^/scim/v2` without a trailing `/`. The pattern `^/scim/v2/` does not protect `GET /scim/v2`, which lists every
  resource.
- Make the firewall `pattern` and the `access_control` path match the route prefix. For the prefix
  `/scim/{tenant}/v2`, use `^/scim/[^/]+/v2`. A request outside the pattern gets to the server without a token.
- Keep the `access_control` rule. Without it, a request without a token gets to the server.
- `tacticmedia_scim_server.access_token_extractor` reads `Authorization: Bearer <token>` (Entra), with the scheme in any
  case, and `Authorization: <token>` (Okta "Header" authentication).
- `tacticmedia_scim_server.access_token_handler` compares the token with `access_tokens`. The user is an `InMemoryUser`
  with the client name and the role `ROLE_SCIM_CLIENT`.
- The failure handler answers a wrong token with a SCIM 401 body and `WWW-Authenticate: Bearer error="invalid_token"`.
  Okta checks this body.
- A request without a token gets a SCIM 401 body and `WWW-Authenticate: Bearer`.
- `password_hashers` is necessary only when you map `password` ([Password](#password)).

For OAuth or JWT tokens, do not set `access_tokens`. Use another token handler, for example the `oidc` handler of
SecurityBundle. Give its users the role `ROLE_SCIM_CLIENT`.

## Rate limiting

```yaml
# config/packages/tacticmedia_scim_server.yaml
tacticmedia_scim_server:
    rate_limit:
        limit: 500
        rate: { interval: '1 second', amount: 50 }
```

The limit is off until you configure `rate_limit`. Each client has a token bucket of `limit` requests, which `rate`
refills. The key is the user identifier of the security token, which is the client name of `access_tokens`. A request
without a token, for example outside the firewall, uses the client IP as key.

- Over the limit, the bundle answers 429 with a SCIM error body and `Retry-After`, an integer number of seconds of at
  least 1. Okta waits that time. Without the header, or with a date, Okta waits 5 minutes.
- The limit applies after the firewall. A request without a valid token gets 401 and takes no token from a bucket.
- A `/Bulk` request counts as one request.
- Microsoft Entra ID requires gallery applications to accept at least 25 requests per second per tenant. The defaults
  allow twice that.
- The buckets are in `cache.rate_limiter`, a pool of `cache.app`. Each server then counts its own requests, and
  `cache:clear` resets the buckets. When several servers share the limit, set `cache_pool` to a shared pool, for
  example Redis.
- The bundle locks a bucket with `lock.factory` when it exists (`framework.lock`). Without a lock, parallel requests
  can go a little over the limit.

For another policy, storage or lock, configure a limiter in `framework.rate_limiter` and give its service to
`limiter`:

```yaml
framework:
    rate_limiter:
        scim:
            policy: sliding_window
            limit: 3000
            interval: '1 minute'
tacticmedia_scim_server:
    rate_limit:
        limiter: limiter.scim
```

## /Me

```yaml
# config/packages/tacticmedia_scim_server.yaml
tacticmedia_scim_server:
    me:
        attribute: userName
```

`/Me` (RFC 7644 section 3.11) is an alias of the resource of the authenticated subject, for end-user self-service.
Identity providers do not call it. Until you configure `me`, `/Me` answers 501.

The bundle finds the `resource_type` resource whose `attribute` equals the user identifier of the security token.
GET, HEAD, PUT, PATCH and DELETE on `/Me` then act on that resource as on `/Users/<id>`, and each response has the URL
of the resource in `Location`. No match gives 404, more than one match 409. POST `/Me` gives 501.

- `attribute` must be filterable, for example `userName`, `externalId` or `emails.value`. `id` takes the identifier
  as the id.
- For another lookup, implement `TacticMedia\ScimServer\Server\MeResolver` and set `resolver` to its service id.
- A `resource_type` that does not exist, or an `attribute` that the resource type cannot filter with `eq`, stops the
  container build. A writeOnly, Computed or integer attribute cannot select a resource. With `resolver`, the build
  does not check them.

The `access_tokens` clients have no resource. End users need their own firewall. A write through `/Me` has the rights
of an identity provider: an end user can change each readWrite attribute of their resource, for example `active`,
`userName` and `roles`. Thus allow only GET and HEAD:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        scim_me:
            pattern: ^/scim/v2/Me/?$
            stateless: true
            access_token:
                token_handler: App\Security\EndUserTokenHandler
                failure_handler: tacticmedia_scim_server.authentication_failure_handler
        scim:
            pattern: ^/scim/v2
            # as in Firewall
    access_control:
        - { path: ^/scim/v2/Me/?$, roles: ROLE_USER, methods: [GET, HEAD] }
        - { path: ^/scim/v2, roles: ROLE_SCIM_CLIENT }
```

- Put `scim_me` before `scim`. A PUT, PATCH or DELETE on `/Me` then needs `ROLE_SCIM_CLIENT`, and an identity provider
  gets 401 on `/Me`.
- The firewall and `access_control` compare the path case-sensitively, the server does not. A request to
  `/scim/v2/me` thus gets to the `scim` firewall and fails. Tell end-user clients to use `/Me`.

## Mapping

Put `#[ScimResource]` on a class and `#[ScimAttribute]` on its properties. This entity is an excerpt of the `User` of
the bundle tests:

```php
use App\Enum\UserType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServerBundle\Converter\HashPassword;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

use function Symfony\Component\Clock\now;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ScimResource('User')]
class User implements PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    #[ScimAttribute('externalId')]
    private ?string $externalId = null;

    #[ORM\Column(length: 180, unique: true)]
    #[ScimAttribute('userName')]
    private string $userName;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('name.givenName')]
    private ?string $firstName = null;

    #[ORM\Column(nullable: true, enumType: UserType::class)]
    #[ScimAttribute('userType')]
    private ?UserType $type = null;

    #[ORM\Column]
    #[ScimAttribute('active', default: true)]
    private bool $enabled = true;

    #[ORM\Column(nullable: true)]
    #[ScimAttribute('password', converter: HashPassword::class)]
    private ?string $password = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[ScimAttribute('emails[type eq "work"].value', primary: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[ScimAttribute('phoneNumbers[type eq "work"].value', primary: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[ScimAttribute('phoneNumbers[type eq "mobile"].value')]
    private ?string $mobile = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('department')]
    private ?string $department = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[ScimAttribute('manager')]
    private ?self $manager = null;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class, mappedBy: 'users')]
    #[ScimAttribute('groups')]
    private Collection $groups;

    #[ORM\Column]
    #[ScimAttribute('meta.created')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[ScimAttribute('meta.lastModified')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    #[ScimAttribute('meta.version')]
    private int $version = 1;

    public function __construct(string $userName)
    {
        $this->id = Uuid::v7();
        $this->userName = $userName;
        $this->groups = new ArrayCollection();
        $this->createdAt = now();
        $this->updatedAt = $this->createdAt;
    }

    // A getter for each mapped property, and a setter for each writable one.
}
```

### Discovery

The bundle finds `#[ScimResource]` classes through autoconfiguration. Thus each class must be in a directory that
`services.yaml` loads with `autoconfigure: true`. The bundle does not make the entities services.

If `services.yaml` excludes `../src/Entity/`, remove that exclusion, or list the classes in `resources`:

```yaml
# config/packages/tacticmedia_scim_server.yaml
tacticmedia_scim_server:
    resources:
        - App\Entity\User
        - App\Entity\Group
```

The bundle uses the listed classes and the classes that autoconfiguration finds. A listed class that does not exist or
has no `#[ScimResource]` stops the container build.

The container build checks every mapping. An error stops the build with a message that names the class and gives a
fix. An error of one member also names the member and the SCIM path.

### `#[ScimResource]`

| Argument | Default | Meaning |
| --- | --- | --- |
| `name` | | The resource type name, for example `User`. |
| `endpoint` | `'/'.$name.'s'` | The endpoint, `/` and one name. `/Schemas`, `/ResourceTypes`, `/ServiceProviderConfig`, `/Bulk` and `/Me` are reserved. |
| `schema` | RFC 7643 schema for `User` and `Group` | The URN of the core schema. Another name needs it. |
| `extensions` | `[]` | `list<ScimExtension>`: schema extensions that the application defines. |
| `factory` | `null` | An `ObjectFactory` class that creates objects ([Objects](#constructor-arguments-and-objectfactory)). |
| `store` | `null` | An `ObjectStore` class. `null` is the Doctrine ORM store. |
| `idPrefix` | `null` | A prefix for an `int` id: `'u'` gives the SCIM id `u42`. A prefix that ends in a digit fails the build. |
| `description` | `''` | Published in `/ResourceTypes`, and as the description of a core schema that the application defines. |
| `validationGroups` | `null` | `list<string>`: the Validator groups of each write ([Validation](#validation)). `null` turns the validation off. `[]` fails the build. |
| `deltas` | `true` | `false`: a PATCH of `members` loads the members and calls the adder and the remover ([Large groups](#large-groups)). |

### `#[ScimAttribute]`

| Argument | Meaning |
| --- | --- |
| `path` | The SCIM path: `userName`, `name.givenName`, `emails[type eq "work"].value`, or a URN-qualified path. |
| `converter` | A `ValueConverter` class ([Converters](#converters)). |
| `primary` | On a slot: the element of this type has `"primary": true`. |
| `default` | On a field or a slot that is not readOnly and not required: the SCIM value for a create or replace without the attribute. |
| `elementClass` | On a collection property of a whole multi-valued attribute: the class whose properties map the sub-attributes ([Entity collections](#entity-collections)). |
| `type`, `required`, `caseExact`, `mutability`, `returned`, `uniqueness`, `canonicalValues`, `description` | Characteristics, only for attributes of schemas that the application defines. |

`uniqueness` is also allowed on RFC 7643 attributes, except `id`, `externalId` and `meta`. Before it stores a POST, PUT or
PATCH, the server looks for the value with an `eq` filter in the same resource type. A duplicate gives 409 `uniqueness`, for
`server` and for `global`:

```php
#[ORM\Column(length: 255, unique: true)]
#[ScimAttribute('displayName', uniqueness: Uniqueness::Server)]
private string $name;
```

A unique attribute must be a top-level, single-valued attribute that is not complex. Map it with a property that is not
writeOnly. A unique method, writeOnly attribute, sub-attribute, slot, reference or multi-valued attribute fails the
build. This also applies to `userName`, which RFC 7643 makes unique.

An unqualified path resolves in the core schema, then in the extensions. Use the URN-qualified path when two schemas
define the name.

### Mapping kinds

The bundle infers the kind from the path, the schema and the member.

| Kind | Path | Member | Filter | Sort |
| --- | --- | --- | --- | --- |
| Field | A simple attribute or a sub-attribute of a single-valued complex attribute: `userName`, `name.givenName`, `meta.created`. | A scalar, a `DateTimeInterface` or a backed enum. | Yes. `co`, `sw`, `ew` need a string or string-backed enum member. | Yes |
| Slot | One sub-attribute of the element of one type of a multi-valued attribute: `emails[type eq "work"].value`. | A scalar. | Yes | Yes |
| Reference | A single-valued attribute that refers to a resource: `manager`. | Typed with the target class. | `value eq`, `value ne`, `pr` | No |
| Reference collection | A multi-valued attribute that refers to resources: `members[type eq "User"]`, `groups`. | `array`, `iterable` or a Doctrine `Collection` of target objects. | `value eq`, `value ne`, `type`, `pr` | No |
| Element collection | A whole multi-valued attribute with `elementClass:`: `addresses`. | `array`, `iterable` or a Doctrine `Collection` of element objects. | Each mapped sub-attribute | Each mapped sub-attribute |
| Computed | Any path without a value filter. | A public method without required parameters. | With `#[ScimDqlExpression]` | With `#[ScimDqlExpression]` |

Field:

```php
#[ScimAttribute('name.givenName')]
private ?string $firstName = null;
```

Slot. All slots of one attribute and one type are one element. Mark one type `primary: true`:

```php
#[ScimAttribute('phoneNumbers[type eq "work"].value', primary: true)]
private ?string $phone = null;

#[ScimAttribute('phoneNumbers[type eq "mobile"].value')]
private ?string $mobile = null;
```

The document has `[{"value": "...", "type": "work", "primary": true}, {"value": "...", "type": "mobile"}]`. On write,
an element goes to the slot of its `type`. An element without `type` goes to the primary slot. Elements of other types
are dropped. To keep every element, map an [element collection](#entity-collections).

Computed. A method mapping is readOnly:

```php
#[ScimAttribute('label')]
public function label(): string
{
    return $this->serialNumber;
}
```

### Entity collections

An element collection maps a whole multi-valued attribute, such as `emails`, `phoneNumbers` or `addresses`, to a
collection of objects. It keeps every element and every `type`. Put `elementClass:` on the collection property, and
`#[ScimAttribute]` with a sub-attribute name on the properties of the element class:

```php
// In App\Entity\User. The constructor sets $this->addresses = new ArrayCollection().

/** @var Collection<int, UserAddress> */
#[ORM\OneToMany(targetEntity: UserAddress::class, mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
#[ORM\OrderBy(['id' => 'ASC'])]
#[ScimAttribute('addresses', elementClass: UserAddress::class)]
private Collection $addresses;

/** @return Collection<int, UserAddress> */
public function getAddresses(): Collection { return $this->addresses; }
public function addAddress(UserAddress $address): void { if (!$this->addresses->contains($address)) { $this->addresses->add($address); } }
public function removeAddress(UserAddress $address): void { $this->addresses->removeElement($address); }
```

```php
#[ORM\Entity]
#[ORM\Table(name: 'app_user_address')]
class UserAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('locality')]
    public ?string $locality = null;

    #[ORM\Column(name: 'is_primary')]
    #[ScimAttribute('primary', default: false)]
    public bool $primary = false;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'addresses')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public readonly User $user,
        #[ORM\Column(length: 20, nullable: true)]
        #[ScimAttribute('type')]
        public ?string $type = null,
    ) {
    }
}
```

- The element class has no `#[ScimResource]` and maps properties only. Each path is a sub-attribute name of the
  attribute.
- `/Schemas` publishes the mapped sub-attributes. A member that is not nullable and has no `default:` publishes its
  sub-attribute as `required: true`, so the library rejects an element without it with 400 `invalidValue`.
- Read: one element per object, in collection order, with the sub-attributes that have a value. An object without one
  is absent.
- Write: an element of the document updates the first object with an equal `value` and `type`, case-insensitively. A
  new element gets a new object from the constructor: a parameter typed with the owner class gets the owner, a parameter
  with the name of a mapped property gets its value. The adder adds it. Each object without an element goes through the
  remover, and `orphanRemoval: true` deletes its row. Only the first element with `primary: true` stays primary.
- Filters: `addresses.locality eq "Paris"`, `addresses[type eq "work" and locality sw "P"]`, `addresses pr`. One
  element must satisfy the whole value path. The Doctrine ORM store translates each condition to an `EXISTS`
  subquery.
- `sortBy=addresses.locality` sorts by the primary element, else by the first element. Cursor pagination does not
  apply; the server pages by offset.
- Doctrine: the property is a `OneToMany` or `ManyToMany` association to the element class, ordered by the element
  identifier with `#[ORM\OrderBy(['id' => 'ASC'])]`. Without that order the database chooses one, and the ETag and
  `sortBy` can change without a write. Each mapped property is a column. Do not name a column `primary`, which SQL
  reserves. The store checks this on first use, and so does `debug:scim`.
- A PATCH of the attribute loads the collection. The delta path of [Large groups](#large-groups) does not apply.
- Slots and an element collection of one attribute fail the build.

### Computed attributes

A public method maps a readOnly attribute. A filter on it gives 400 `invalidFilter`, a `sortBy` 400 `invalidValue`.
On the Doctrine ORM store, `#[ScimDqlExpression]` makes it filterable and sortable. The expression gives the value of
the method in DQL; `{alias}` stands for the alias of the entity:

```php
use TacticMedia\ScimServerBundle\Doctrine\Orm\Mapping\ScimDqlExpression;

#[ScimAttribute('name.formatted')]
#[ScimDqlExpression("TRIM(CONCAT(COALESCE({alias}.firstName, ''), ' ', COALESCE({alias}.lastName, '')))")]
public function getFormattedName(): ?string
{
    return trim(($this->firstName ?? '').' '.($this->lastName ?? '')) ?: null;
}
```

- The bundle cannot check that the method and the expression give one value. Test both on the same data.
- Only a single-valued attribute that is not complex takes an expression. Another attribute fails the build.
- The store parses the expression on first use and throws `MappingException` when Doctrine cannot use it. Run
  `debug:scim` in CI.
- The store compares the expression with `IS NOT NULL`, which DQL allows after a field, a function such as `CONCAT()`,
  `COALESCE()` or `NULLIF()`. Wrap a `CASE` expression: `COALESCE(CASE ... END, NULL)`.
- `sortBy` on an expression has no cursor; the server pages by offset.
- A custom store gets the expression in `AttributeMapping::$dqlExpression`.

### Read and write rules

- The bundle never writes a readOnly attribute. It writes an immutable attribute on create, and later only while the
  attribute has no value.
- A writable member needs a setter or a public property. A reference collection needs an adder and a remover, for
  example `addUser()` and `removeUser()`.
- A create or replace without an attribute writes its `default`, else `null`, else an empty collection. Thus an
  optional member must be nullable or have `default:`.
- A writeOnly attribute (`password`) keeps its stored value when the document does not have it.
- The document never has `null`, `""` or empty values.

### Ids

| Member type | SCIM id | Notes |
| --- | --- | --- |
| `Symfony\Component\Uid\Uuid` (recommended) | RFC 4122 string | A malformed id gives 404. |
| `Symfony\Component\Uid\Ulid` | base 32 string | |
| `int` | decimal string, with `idPrefix` | RFC 7643 needs ids that are unique over all resource types. Give each int-id type its own `idPrefix`. |
| `string` | as stored | |

Without `#[ScimAttribute('id')]`, the property `$id` maps `id`. The bundle reads the id after the flush, so a generated
int id works. The build fails when two int-id targets of one reference collection have the same `idPrefix` or none.

### Metadata

| Path | Member | Notes |
| --- | --- | --- |
| `meta.created` | `DateTimeInterface` | readOnly. The constructor sets it, or the bundle sets it on create when the member is writable. |
| `meta.lastModified` | `DateTimeInterface` | readOnly. The bundle sets it on create, to the value of a mapped `meta.created`, and on a replace that changes the document, when the member is writable. |
| `meta.version` | `int` on the `#[ORM\Version]` field, or a string such as `W/"7"` | Optional. Without it, the server computes a weak ETag from the document. See [ETags and If-Match](#etags-and-if-match). |

The bundle uses the `clock` service (`symfony/clock`) when it exists. Store dates in UTC: set `date.timezone = UTC`.

### ETags and If-Match

Map `meta.version` to an integer `#[ORM\Version]` field, as in the `User` above, and give it a getter. Then
`If-Match` on PUT, PATCH and DELETE is atomic:

- An `int` member at `meta.version` gets the converter `VersionConverter`: `5` is the ETag `W/"5"`. A filter such as
  `meta.version eq "W/\"5\""` compares the number.
- Give the column the type explicitly: `#[ORM\Column(type: Types::INTEGER)]`, `SMALLINT` or `BIGINT`. Doctrine fails
  on an `#[ORM\Version]` column without a type.
- For an `If-Match` with an entity tag, the server passes the stored version to the repository. The store runs one
  DQL `UPDATE ... SET version = version + 1 WHERE id = :id AND version = :version` before the flush. The statement
  compares the version and locks the row in one step. When another request changed or deleted the row after the
  check, the request gets 412 and writes nothing.
- Each PUT and PATCH increments the version, also one that changes only `members` and one that changes nothing. A
  PATCH on the delta path of [Large groups](#large-groups) increments it only for a change. Without `If-Match`, the
  store runs the statement without the version condition, so concurrent writes wait for each other instead of
  failing with 412.
- Doctrine leaves the version out of the INSERT, so the column needs the default `1`. Doctrine adds it to the
  mapping of an integer version field.
- An `#[ORM\Version]` class without a `meta.version` mapping gets a computed ETag, and the store still increments the
  version on each write.
- A string member at `meta.version` is an ETag that the application maintains. The server then compares `If-Match`
  before the write, without a lock.
- A write outside SCIM that changes only a to-many association does not increment the version. Change a column too,
  for example `updatedAt`.

## References and members

A Reference or a reference collection writes target objects. The bundle finds the targets by SCIM id in the store of
the target class.

```php
/** @var Collection<int, User> */
#[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'groups')]
#[ScimAttribute('members[type eq "User"]')]
private Collection $users;

/** @var Collection<int, Group> */
#[ORM\ManyToMany(targetEntity: self::class)]
#[ScimAttribute('members[type eq "Group"]')]
private Collection $subgroups;

public function addUser(User $user): void
{
    if (!$this->users->contains($user)) {
        $this->users->add($user);
    }
}

public function removeUser(User $user): void
{
    $this->users->removeElement($user);
}
```

- `members` refers to `User` and `Group`, so each collection names one type in brackets.
- A member with a `type` goes to the collection of that type. A member without `type` goes to the collection that
  has it, else to the first collection whose store finds the id.
- An unknown member id gives 400 `invalidValue`. Nothing is written.
- The bundle compares ids. It loads only the added targets, and calls the remover and the adder for the difference.
- `display` and `displayName` come from the `displayName` of the target, else from its `userName`.
- The server adds `$ref`.
- `groups` on `User` is readOnly in RFC 7643. Map it to the inverse side of the association.
- `manager` is a Reference. Type the member with the class of the target: `private ?self $manager`.

## Large groups

A PATCH of `members` does not load the members of the group when all of these conditions are true:

- The class maps `meta.version` to an integer `#[ORM\Version]` field ([ETags and If-Match](#etags-and-if-match)).
- Each collection of `members` is the owning side of a Doctrine `ManyToMany` association without a second-level cache.
  Each side of the join table has one column, which refers to the identifier.
- The `members` attribute is readWrite, and each collection has an adder and a remover.
- No decorator hides the delta capability. A decorator of the repository must implement
  `TacticMedia\ScimServer\Repository\DeltaRepository` and forward `deltaAttributes()` and `patch()`.
- `#[ScimResource]` does not set `deltas: false` (below).

The `Group` above meets them. Then one PATCH costs the same number of SQL statements for any group size, for
example 14 statements for one member add, transaction statements included.

These operations use the delta path: `add` of `members`, `remove` of `members` with or without a `value` list,
`remove` of `members[value eq "<id>"]`, and `replace` of `members`. They cover the member forms of Entra ID, Okta and
the Keycloak plugin. When a request has another operation on `members`, for example a filter on `type` or a path-less
`add`, the whole request loads the members. A PUT and a GET that does not exclude `members` also load them.

- The store locks the row of the group, reads member ids with DQL, writes the join table with DBAL, and increments
  the version. SQLite has no row lock and locks the database file on the first write.
- Doctrine SQL filters apply to the reads. An add of a hidden target gives 400 `invalidValue`, and a `replace` keeps
  the hidden members.
- Ids compare in the form of the id converter, so `members[value eq "<UPPER-CASE-UUID>"]` removes the member.
- An unknown member id gives 400 `invalidValue`. Nothing is written.
- A PATCH that adds or removes no member and changes no other attribute keeps `meta.lastModified` and the version.
  Steps that cancel out are no change: a `remove` and an `add` of one member, or an `add` and a `remove` of a new
  member. Such a PATCH keeps the version, dispatches no event and gives the same ETag. A removal waits for the last
  step, so a `remove` and an `add` of one member write no row.
- The server dispatches `TacticMedia\ScimServer\Event\ResourcePatched`, not `ResourceUpdated`. Its `$before` and
  `$after` have no `members`. `$changes` is a list of `ValuesChange` with `$added` and `$removed` members, each
  `{"value", "type"}`. A listener that must see every change subscribes to both events.
- The PATCH does not call the adder or the remover, and Doctrine dispatches no event for the collection. An inverse
  collection that is already loaded, for example `User::$groups`, keeps its old elements until the next request.

When the adder, the remover or a Doctrine listener of the collection must run, turn the delta path off for the class:

```php
#[ScimResource('Group', deltas: false)]
class Group
```

Then each PATCH of `members` loads the members and writes them through the adder and the remover, and the server
dispatches `ResourceUpdated`. `debug:scim` shows the attributes that use the delta path.

## Extensions

### Enterprise extension

The RFC 7643 enterprise extension is available to a `User` class. Map its attributes by name or by URN-qualified path:

```php
#[ScimAttribute('department')]
private ?string $department = null;

#[ScimAttribute('urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:employeeNumber')]
private ?string $employeeNumber = null;
```

### Application extensions

Declare the extension on the class. Map its attributes with URN-qualified paths. Set the characteristics on each
attribute. This class is an excerpt of the `Device` of the bundle tests:

```php
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Returned;
use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimExtension;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource(
    'Device',
    schema: 'urn:acme:scim:schemas:core:1.0:Device',
    extensions: [new ScimExtension('urn:acme:scim:schemas:extension:audit:1.0:Device', 'DeviceAudit', 'Audit data', required: true)],
    factory: DeviceFactory::class,
    idPrefix: 'd',
    description: 'A device',
)]
final class Device
{
    #[ScimAttribute('id')]
    public ?int $id = null;

    #[ScimAttribute('status')]
    public ?DeviceStatus $status = null;

    #[ScimAttribute('location.building', description: 'Building code')]
    public ?string $building = null;

    #[ScimAttribute('urn:acme:scim:schemas:extension:audit:1.0:Device:checkedBy', type: AttributeType::Reference, caseExact: true, returned: Returned::Request, uniqueness: Uniqueness::Global, canonicalValues: ['robot'])]
    public ?string $checkedBy = null;

    // ...
}
```

- A resource type that RFC 7643 does not define needs `schema:`. Its unqualified paths define the attributes of that
  schema.
- The type of an attribute comes from the PHP type: `string`, `bool`, `int`, `float`, `DateTimeInterface`, backed
  enum. Set `type:` for another type.
- With `converter:` and without `type:`, the type is `string`.
- A string-backed enum publishes its case values as `canonicalValues`.
- An extension without a mapped attribute is an error.

`/Schemas` publishes only the mapped attributes of each schema.

## Password

Map `password` with the `HashPassword` converter. The class must implement `PasswordAuthenticatedUserInterface`:

```php
#[ORM\Column(nullable: true)]
#[ScimAttribute('password', converter: HashPassword::class)]
private ?string $password = null;
```

- Configure a password hasher for the class in `security.password_hashers`.
- The bundle stores the hash. GET never returns the password.
- A PUT or PATCH without `password` keeps the stored hash.
- A `password` without a converter is a build error.
- `HashPassword` on an attribute that is not writeOnly is a build error, because its `toScim()` throws.

## Converters

A converter changes a member value to a SCIM value, and back. Without `converter:`, the bundle converts scalars,
`DateTimeInterface` (UTC, `2026-11-06T10:00:00Z`) and backed enums. This converter is in the bundle tests:

```php
use TacticMedia\ScimServerBundle\Converter\ConversionContext;
use TacticMedia\ScimServerBundle\Converter\ConversionFailed;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;

final readonly class TagConverter implements ValueConverter
{
    public function toScim(mixed $value, ConversionContext $context): ?string
    {
        return $value instanceof Tag ? $value->code : null;
    }

    public function fromScim(mixed $value, ConversionContext $context): ?Tag
    {
        return match (true) {
            null === $value => null,
            \is_string($value) => new Tag($value),
            default => throw new ConversionFailed('A tag is a string.'),
        };
    }
}
```

```php
#[ScimAttribute('urn:acme:scim:schemas:extension:audit:1.0:Device:tag', converter: TagConverter::class)]
public ?Tag $tag = null;
```

- A converter is a service. Autoconfiguration finds it by class name. Under another service id, tag it
  `tacticmedia_scim_server.value_converter` with `key: <class>`.
- `ConversionFailed` on write gives 400 `invalidValue`. In a filter, the comparison matches nothing.
- `fromScim()` also gets constructor arguments and filter literals. `$context->object` is then `null`.

## Constructor arguments and ObjectFactory

The bundle creates an object with its constructor. It gives each parameter the value of the readWrite or immutable
property mapping with the same name. `User::__construct(string $userName)` gets the `userName` of the document.

- A parameter never gets a writeOnly attribute. The bundle writes it after the constructor, because its converter can
  need the object.
- A parameter without a readWrite or immutable mapping must be nullable or have a default value.
- A parameter that gets `null` for an absent attribute must be nullable or have a default value, else the mapping needs
  `default:`.
- The build fails when a parameter breaks one of these rules.

For other constructors, set `factory:`:

```php
use TacticMedia\ScimServerBundle\Object\ObjectFactory;

final readonly class DeviceFactory implements ObjectFactory
{
    public function create(array $document): Device
    {
        return new Device(\is_string($document['serialNumber'] ?? null) ? $document['serialNumber'] : '');
    }
}
```

The factory is a service with `autoconfigure: true`. The bundle writes the document onto the new object after
`create()`.

## Custom ObjectStore

The Doctrine ORM store is the default. For Doctrine MongoDB ODM, an HTTP API or another storage, implement
`ObjectStore` and set `store:`:

```php
#[ScimResource('User', store: CrmUserStore::class)]
final class CrmUser
{
    // ...
}
```

```php
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Object\ObjectPage;
use TacticMedia\ScimServerBundle\Object\ObjectStore;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;

final class CrmUserStore implements ObjectStore
{
    public function find(ResourceMapping $mapping, string $id): ?object
    {
        return $this->findMany($mapping, [$id])[$id] ?? null;
    }

    public function findMany(ResourceMapping $mapping, array $ids): array
    {
        // Load the objects. Key them by SCIM id. Leave out unknown and malformed ids.
        return [];
    }

    public function query(ResourceMapping $mapping, ObjectQuery $query): ObjectPage
    {
        // Translate $query->filter, $query->sortBy, $query->sortOrder, $query->startIndex and $query->count.
        return new ObjectPage([], 0);
    }

    public function transactional(ResourceMapping $mapping, \Closure $write): mixed
    {
        return $write();
    }

    public function insert(ResourceMapping $mapping, object $object): void
    {
        // Create the object in the storage.
    }

    public function update(ResourceMapping $mapping, object $object, mixed $version = null): void
    {
        // Store the changed object. With a $version, only while the stored object has it, else 412.
    }

    public function delete(ResourceMapping $mapping, object $object, mixed $version = null): void
    {
        // Delete the object. $version as for update().
    }
}
```

| Method | Contract |
| --- | --- |
| `find()`, `findMany()` | `$id` is the SCIM id. Convert it with the id converter. A malformed or unknown id, and the id of a [soft-deleted](#soft-delete) object, is `null` or absent. |
| `query()` | Leave out soft-deleted objects. `totalResults` counts all matches. `startIndex` starts at 1. `count` 0 gives no objects. `sortBy` is a list of members. Order by the value of the first member that has a value, absent values last ascending and first descending, then by id. With an empty `sortBy`, order by id. `load` lists the relations that the response shows. The store can preload them. |
| `transactional()` | Make the writes durable when `$write` returns. Discard them when it throws. Give a duplicate `ScimException::uniqueness()`. |
| `insert()`, `update()`, `delete()` | Called only inside `transactional()`. |
| `update()`, `delete()` | A non-null `$version` is the member value of `meta.version` that `If-Match` requires. Throw `ScimException::preconditionFailed()` when the stored object has another version. A store that ignores `$version` leaves a race between the `If-Match` check and the write. |

`ObjectQuery::$filter` is `null` (every object) or a tree of `Query\Plan` nodes. The bundle never calls `query()` for a
filter that matches nothing.

| Node | Holds when |
| --- | --- |
| `AllOf`, `AnyOf`, `Negate` | All nodes hold, one node holds, the node does not hold. |
| `IsPresent($operand)` | The member is not `null` and, for a string, not `""`. |
| `Compare($operand, $operator, $value)` | The member is not `null` and compares to `$value`. Fold the case when the attribute is a string with `caseExact` false. `co`, `sw` and `ew` are substring tests. |
| `Exists($relation, $condition)` | The related object, or one element of the collection, exists, is not soft-deleted and satisfies `$condition`. |

Throw `ScimException::invalidFilter()` for a node that the storage cannot evaluate. `OrmQueryTranslator` is the
reference translation.

The store is a service with `autoconfigure: true`. Under another service id, tag it
`tacticmedia_scim_server.object_store` with `key: <class>`.

## Raw ResourceRepository

A raw repository works with SCIM documents, not objects. Implement the library `ResourceRepository` and declare the
supported paths with `#[AsResourceRepository]`. This repository is in the bundle tests:

```php
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\ListResult;
use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServerBundle\Attribute\AsResourceRepository;

#[AsResourceRepository(
    'Printer',
    attributes: ['serialNumber', 'urn:acme:scim:schemas:extension:site:1.0:Printer:room'],
    schema: 'urn:acme:scim:schemas:core:1.0:Printer',
    schemas: [
        new Schema('urn:acme:scim:schemas:core:1.0:Printer', 'Printer', 'A printer', [new AttributeDefinition('serialNumber', caseExact: true), new AttributeDefinition('model')]),
        new Schema('urn:acme:scim:schemas:extension:site:1.0:Printer', 'PrinterSite', 'Where a printer is', [new AttributeDefinition('room')]),
    ],
    description: 'Printers',
)]
final readonly class PrinterRepository implements ResourceRepository
{
    private const array PRINTER = ['id' => 'p1', 'serialNumber' => 'SN-1', 'urn:acme:scim:schemas:extension:site:1.0:Printer' => ['room' => '4.01']];

    public function get(string $id, array $attributes = [], array $excludedAttributes = []): ?array
    {
        return self::PRINTER['id'] === $id ? self::PRINTER : null;
    }

    public function list(ListQuery $query): ListResult
    {
        return new ListResult([self::PRINTER], 1);
    }

    public function create(array $document): array
    {
        throw ScimException::notImplemented('Printers are read-only.');
    }

    public function replace(string $id, array $document, ?string $version = null): array
    {
        throw ScimException::notImplemented('Printers are read-only.');
    }

    public function delete(string $id, ?string $version = null): void
    {
        throw ScimException::notImplemented('Printers are read-only.');
    }
}
```

- `attributes` lists the supported paths in mapping syntax. `/Schemas` publishes only these.
- `User` and `Group` use the RFC 7643 schemas without `schema:`. A `User` also gets the enterprise extension.
- The repository evaluates `ListQuery::$filter` itself.
- Register the class as a service with `autoconfigure: true`.

## Decoration

Each resource type has the service `tacticmedia_scim_server.repository.<lowercase name>`, for example
`tacticmedia_scim_server.repository.user`. Each has the tag `tacticmedia_scim_server.repository`, raw repositories
too.

Decorate one resource type:

```php
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\ListResult;
use TacticMedia\ScimServer\Repository\ResourceRepository;

#[AsDecorator('tacticmedia_scim_server.repository.user')]
final readonly class UserRepositoryDecorator implements ResourceRepository
{
    public function __construct(
        #[AutowireDecorated]
        public ResourceRepository $inner,
    ) {
    }

    public function get(string $id, array $attributes = [], array $excludedAttributes = []): ?array
    {
        return $this->inner->get($id, $attributes, $excludedAttributes);
    }

    public function list(ListQuery $query): ListResult
    {
        return $this->inner->list($query);
    }

    public function create(array $document): array
    {
        return $this->inner->create($document);
    }

    public function replace(string $id, array $document, ?string $version = null): array
    {
        return $this->inner->replace($id, $document, $version);
    }

    public function delete(string $id, ?string $version = null): void
    {
        $this->inner->delete($id, $version);
    }
}
```

Forward `$version`. A decorator that drops it makes `If-Match` lose its atomicity.

Decorate every resource type with `#[AsTagDecorator('tacticmedia_scim_server.repository')]` on the same kind of class.

## Validation

The bundle runs the Symfony Validator for a class with `validationGroups`:

```php
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ScimResource('User', validationGroups: ['scim'])]
#[UniqueEntity(fields: ['email'], groups: ['scim'])]
class User
{
    #[ORM\Column(length: 255, unique: true, nullable: true)]
    #[ScimAttribute('emails[type eq "work"].value')]
    #[Assert\Email(groups: ['scim'])]
    private ?string $email = null;
}
```

- Install `symfony/validator` and keep `framework.validation` enabled. Without the `validator` service, a class with
  `validationGroups` fails the container build.
- Use a group of your own. Constraints for forms in `Default` can reject the data of an identity provider.
  `['Default']` validates the `Default` group.
- The repository validates after it writes the document onto the object and before the store writes the object, in
  the same transaction: POST, PUT and PATCH. On the [Large groups](#large-groups) path it validates after the member
  rows are written, so a constraint on the collection, for example `#[Assert\Count(max: 500)]`, loads the members. A
  PATCH that changes nothing is not validated.
- A violation gives 400 `invalidValue`, and nothing is written. The `detail` names the mapped path of the member at
  the start of the property path of each violation: `Attribute "emails[type eq "work"].value": This value is not a
  valid email address.` A violation of the class, or of a member that is not mapped, has only the message.
- A `UniqueEntity` violation gives 409 `uniqueness`, with the `UniqueEntity` violations only. Keep the unique index:
  the constraint does not stop two concurrent requests.

## Events

The server dispatches these events through the `event_dispatcher` service after a successful write:

| Event | Properties |
| --- | --- |
| `TacticMedia\ScimServer\Event\ResourceCreated` | `$type`, `$resource` |
| `TacticMedia\ScimServer\Event\ResourceUpdated` | `$type`, `$before`, `$after`, `$patch` (`null` for PUT) |
| `TacticMedia\ScimServer\Event\ResourceDeleted` | `$type`, `$resource` (the document before the delete) |

```php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use TacticMedia\ScimServer\Event\ResourceCreated;
use TacticMedia\ScimServer\Event\ResourceDeleted;
use TacticMedia\ScimServer\Event\ResourceUpdated;

#[AsEventListener]
final class ProvisioningRecorder
{
    /** @var list<ResourceCreated|ResourceUpdated|ResourceDeleted> */
    public array $events = [];

    public function __invoke(ResourceCreated|ResourceUpdated|ResourceDeleted $event): void
    {
        $this->events[] = $event;
    }
}
```

- A PATCH that changes nothing dispatches no event.
- The listener runs after the commit. For work in the same transaction, use a Doctrine listener.
- A `ScimException` from a listener gives an error response, but the write stays.

## Soft delete

Mark one property with `#[ScimSoftDelete]`. DELETE then sets it, and the bundle no longer shows the object:

```php
use TacticMedia\ScimServerBundle\Mapping\ScimSoftDelete;

#[ORM\Column(nullable: true)]
#[ScimSoftDelete]
public private(set) ?\DateTimeImmutable $deletedAt = null;
```

- The property is a `bool` (`true`: deleted) or a nullable `DateTimeInterface` (set: deleted). DELETE writes `true`,
  or the clock time in UTC. The bundle reads and writes the property by reflection, so it needs no getter or setter.
  `public private(set)` lets the application read it. For a `private` property without a setter, PHPStan reports a
  property type that is too wide.
- A property of another type, a `readonly` property, a property that `#[ScimAttribute]` also maps, or a second
  `#[ScimSoftDelete]` fails the build. A property that is not a Doctrine field fails the first use.
- After the DELETE, GET, PUT, PATCH and DELETE of the object give 404. Lists, filters, `totalResults` and cursor pages
  leave it out.
- A soft-deleted `manager` is absent. `members` and `groups` leave out soft-deleted objects. Filters on `manager`,
  `members` and `groups` do not match them.
- Writes keep the links to soft-deleted objects: a PUT or PATCH of `members` does not remove them, also on the
  [Large groups](#large-groups) path, and a write without `manager` keeps a soft-deleted manager. A write that names
  a soft-deleted object gives 400 `invalidValue`, as for an unknown id.
- Do not soft-delete for `active: false`. Entra and Okta deactivate users with it and expect to find inactive users.

### Re-provisioning

The server checks unique attributes against the objects that it shows, so a POST with the `userName` of a
soft-deleted user reaches the repository. Entra sends one when it creates a user again after a hard delete.

By default the repository revives the soft-deleted object whose unique attributes match the POST: `userName`, and
each attribute with `uniqueness:`, with the case rules of `eq`. It clears the property, writes the document onto the
object as onto a new one, and answers 201. The object keeps its id, `meta.created` and the links that other objects
have to it, for example its groups.

`#[ScimSoftDelete(revive: false)]` stores a new object. A unique index on `userName` then rejects it with 409
`uniqueness`. To store the new object next to the soft-deleted one, replace the index with a partial unique index
(PostgreSQL only; Doctrine DBAL ignores `where` on other platforms). `columns` and `where` name database columns:

```php
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'app_user_live_user_name', columns: ['user_name'], options: ['where' => 'deleted_at IS NULL'])]
#[ScimResource('User')]
class User
{
    #[ORM\Column(length: 180)]
    #[ScimAttribute('userName')]
    private string $userName;

    #[ORM\Column(nullable: true)]
    #[ScimSoftDelete(revive: false)]
    public private(set) ?\DateTimeImmutable $deletedAt = null;
}
```

### Custom stores

The Doctrine ORM store applies these rules. A [custom store](#custom-objectstore) for a class with `#[ScimSoftDelete]`
skips soft-deleted objects in `find()`, `findMany()` and `query()`. To revive, it implements
`TacticMedia\ScimServerBundle\Object\SoftDeleteStore::findDeleted()`; without it, a POST always stores a new object.

A store that cannot hide soft-deleted objects keeps them out of the mapping: leave out `#[ScimSoftDelete]` and
[decorate](#decoration) the repository. Its `delete()` marks the record in your storage:

```php
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Repository\ResourceRepository;

#[AsDecorator('tacticmedia_scim_server.repository.user')]
final readonly class SoftDeleteUsers implements ResourceRepository
{
    public function __construct(
        #[AutowireDecorated]
        private ResourceRepository $inner,
        private CrmClient $crm,
    ) {
    }

    public function delete(string $id, ?string $version = null): void
    {
        $this->inner->get($id) ?? throw ScimException::notFound(\sprintf('Resource %s not found.', $id));
        $this->crm->archiveUser($id);
    }

    // get(), list(), create() and replace() call $this->inner.
}
```

## Multi-tenancy

One endpoint can serve several customers with a Doctrine SQL filter that limits the rows to the tenant of the
client. The store reads with DQL, and the version statement of [ETags and If-Match](#etags-and-if-match) is DQL. The
filter thus applies to reads, references, uniqueness checks and the version statement.

Give each customer its own client. With `access_tokens`, the client name is the user identifier. Enable the filter
when the client authenticates:

```php
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener]
final readonly class EnableTenantFilter
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        if ('scim' === $event->getFirewallName()) {
            $this->entityManager->getFilters()->enable('tenant')->setParameter('tenant', $event->getUser()->getUserIdentifier());
        }
    }
}
```

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        filters:
            tenant:
                class: App\Doctrine\TenantFilter
                enabled: false
```

- A failed write closes the entity manager, and the store resets it. The store enables the filters of the old
  manager again, with their parameters, so the next operation of a Bulk request stays in the tenant.
- Set the tenant of a new row in an `ObjectFactory` or in a Doctrine `prePersist` listener.
- Include the tenant column in each unique index, for example `(tenant_id, user_name)`. Otherwise a name of another
  tenant gives 409 and discloses that the name exists.
- Use UUID ids. `id` must be the single Doctrine identifier, so a composite key `(tenant_id, id)` does not work.
- A route prefix such as `/scim/{tenant}/v2` does not bind the token to the tenant. Compare the route parameter with
  the client, or do not use a tenant parameter.
- In a worker, for example FrankenPHP, the manager and its filters stay for the next request. Disable the filter when
  the request ends.
- `/Schemas`, `/ResourceTypes` and `/ServiceProviderConfig` are the same for all tenants.

## Filters, sorting and paging

- The store translates filters to DQL. Every Field and Slot is filterable, except writeOnly ones, and so are the
  sub-attributes of an element collection and a Computed attribute with `#[ScimDqlExpression]`.
- A comparison on a string attribute with `caseExact` false uses `LOWER()` on both sides.
- `ne` and `not` match objects without a value, as the library `FilterEvaluator` does. RFC 7644 does not specify
  `ne` for an absent value.
- A filter on an attribute that the class does not map gives 400 `invalidFilter`.
- `sortBy` takes a Field or a Slot that is not writeOnly. For a multi-valued attribute, the sort key is the value of
  the primary slot group, else of the first slot group with a value. It also takes a sub-attribute of an
  [element collection](#entity-collections) and a [Computed attribute](#computed-attributes) with a DQL expression.
  Absent values sort last ascending and first descending. The id breaks ties.
- `id` takes `gt`, `ge`, `lt`, `le` and `sortBy` only with a `string` member. With another member, they give 400.
- A page has at most `max_results` resources.

## Cursor pagination

The server pages by RFC 9865 cursors when a request has the `cursor` parameter. The first request has an empty
`cursor`. Each next request has the `nextCursor` of the previous response. The last page has no `nextCursor`:

```text
GET /scim/v2/Users?cursor&count=100
GET /scim/v2/Users?cursor=<nextCursor>&count=100
```

```yaml
# config/packages/tacticmedia_scim_server.yaml
tacticmedia_scim_server:
    pagination:
        cursor:
            enabled: true
            key: '%kernel.secret%'
            timeout_seconds: 3600
```

| Key | Default | Meaning |
| --- | --- | --- |
| `pagination.cursor.enabled` | `true` | `false` turns cursors off. The server then ignores `cursor` and pages by `startIndex` only. |
| `pagination.cursor.key` | `%kernel.secret%` | The key that encrypts and authenticates the cursors. Give all servers of the application the same key. A new key makes every open cursor 400 `invalidCursor`. |
| `pagination.cursor.timeout_seconds` | none | The seconds that a cursor stays valid. Then it is 400 `expiredCursor`. `/ServiceProviderConfig` publishes it as `pagination.cursorTimeout`. The `clock` service gives the time. |

- A request without `cursor` pages by `startIndex`. `defaultPaginationMethod` is `index`.
- A cursor response has `nextCursor` while more resources follow. It has no `startIndex` and no `previousCursor`.
- A cursor holds the position and the query: the endpoint, `filter`, `sortBy` and `sortOrder`. A cursor with another
  query is 400 `invalidCursor`. `count`, `attributes` and `excludedAttributes` can change between pages.
- The cursor is encrypted, so URLs and access logs do not show the sort values.
- Each page runs the query again. The firewall, Doctrine SQL filters and data changes apply to each page.

The Doctrine ORM store pages by keyset. The cursor holds the sort values and the id of the last resource of the page,
which the store reads from the database. A resource that is created or deleted between two pages does not move the
next page. The store pages by keyset when each `sortBy` member has one of these Doctrine types: `string`,
`ascii_string`, `text`, `guid`, `enum`, `integer`, `smallint`, `bigint`, `float`, `boolean`, `date`, `time`,
`datetime`, `datetime_utc` and their immutable types, `date_point`, `day_point`, `time_point`. With another type, for
example `decimal`, `smallfloat`, `datetimetz`, `binary`, `uuid` or a custom type, the cursor holds an offset. A create or delete before the offset then
moves the next page, as with `startIndex`. Queries across all resource types (`/`, `/.search`) also use an offset.

A custom store pages by keyset when it implements `CursorPaginatedObjectStore`:

```php
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Object\CursorObjectPage;
use TacticMedia\ScimServerBundle\Object\CursorPaginatedObjectStore;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;

final class CrmUserStore implements CursorPaginatedObjectStore
{
    // The methods of ObjectStore.

    public function queryAfter(ResourceMapping $mapping, ObjectQuery $query, ?array $after): ?CursorObjectPage
    {
        // The first $query->count objects after the position $after, in the order of query().
        return new CursorObjectPage([], 0, null);
    }
}
```

| Item | Contract |
| --- | --- |
| `$query` | The filter, order and `load` of `query()`. `startIndex` is always 1. |
| `$after` | `null` for the first page, else the `next` of an earlier page of the same query. |
| `CursorObjectPage::$next` | The position after the last object, `null` on the last page. A list of strings, numbers, Booleans and nulls, for example the sort values and the id, or the token of an upstream API. |
| `null` result | The server pages this query by offset. Decide it from the query, not from the data: a `null` for a position is 400 `invalidCursor`. |
| A position that the store cannot use | Throw `ScimException::invalidCursor()`. |

A decorator of a repository ([Decoration](#decoration)) keeps keyset pages only when it implements the library
`CursorPaginatedRepository` and delegates `listAfter()`. Else the server pages by offset:

```php
use TacticMedia\ScimServer\Query\CursorPage;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Repository\CursorPaginatedRepository;

public function listAfter(ListQuery $query, ?array $after): ?CursorPage
{
    return $this->inner instanceof CursorPaginatedRepository ? $this->inner->listAfter($query, $after) : null;
}
```

A raw repository pages by keyset when it implements `CursorPaginatedRepository`.

## Database notes

The store compares a string attribute with `caseExact` true as stored. The collation of the column thus decides
these comparisons, the unique indexes and the sort order. The bundle sets no collation.

MySQL and MariaDB: the default collations ignore case and accents, and the MariaDB default ignores trailing spaces.
Use the binary collation `utf8mb4_0900_bin`. It compares and sorts by code point, as the library `FilterEvaluator`
does:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        default_table_options:
            charset: utf8mb4
            collation: utf8mb4_0900_bin
```

The option applies to the tables that Doctrine creates. A schema diff does not change the collation of an existing
column. Convert an existing table with
`ALTER TABLE <table> CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin`.

PostgreSQL: the locale of the database decides the sort order. Create the database with the builtin locale, which
sorts by code point:
`CREATE DATABASE <name> LOCALE_PROVIDER builtin BUILTIN_LOCALE 'C.UTF-8' TEMPLATE template0`.

SQLite needs no setting. The bundle tests run with these settings on PostgreSQL 18, MySQL 9.7 and 26.10, and
MariaDB 12.3 and 13.0.

## Errors

| Status | `scimType` | Cause |
| --- | --- | --- |
| 400 | `invalidFilter` | An unmapped, Computed or writeOnly attribute in a filter, or an operator that the attribute does not support. |
| 400 | `invalidValue` | A converter failure, an unknown reference id, an unsupported `sortBy`, a Validator violation. |
| 400 | `invalidPath` | A PATCH path that the class does not map. |
| 401 | | No token, or a wrong token. |
| 404 | | An unknown or malformed id. |
| 405 | | A method that the endpoint does not allow. The response has the `Allow` of the endpoint. |
| 409 | `uniqueness` | A unique constraint violation, a `UniqueEntity` violation, or a duplicate value of an attribute with `uniqueness` `server` or `global`. |
| 409 | | A foreign key violation, or a Doctrine version check that failed in the flush. |
| 412 | | `If-Match` does not match, or the resource changed or was deleted between the check and the write. |
| 429 | | Too many requests of one client ([Rate limiting](#rate-limiting)). The response has `Retry-After`. |
| 500 | | Any other exception. The body has no details. The kernel logs the exception. |

`tacticmedia_scim_server.exception_listener` gives every exception on the SCIM route a SCIM error body. It keeps the
status and the headers of an `HttpException`, for example `Retry-After` of a 429. Below 500, the `detail` is the
message of the exception. From 500, the `detail` is the status text, for example `Service Unavailable`. Another
exception gives 500 with the `detail` `The server failed to process the request.`

The server turns a `ScimException` into its response. The kernel logs the other exceptions, also the 401 for a request
without a token. Symfony logs an `HttpException` with a 4xx status at level `error`. To log it at another level, set
`log_level` for its class in `framework.exceptions`.

## debug:scim

```bash
bin/console debug:scim
bin/console debug:scim User
```

The command shows each resource type: endpoint, class, store, schemas, and each mapped path with its member, kind,
filter support and sort support, the validation groups, and the attributes whose PATCH uses the delta path of
[Large groups](#large-groups). For a raw repository it shows the declared attributes.

It also runs the checks that need services:

- The store, the factory and each converter of a mapping are services.
- The Doctrine metadata fits the mapping: each property is a Doctrine field or association, `id` is the single
  identifier, a Reference is a to-one association and a reference collection a to-many association to the target
  class, `meta.version` maps the integer `#[ORM\Version]` field.
- An element collection is a to-many association to the element class, ordered by the element identifier, and each
  mapped property of the element class is a Doctrine field. The table has a row per mapped property of the element
  class.
- Doctrine parses each `#[ScimDqlExpression]`. The table shows its kind as `Computed with DQL`.

The command exits with status 1 when a check fails. Run it in CI. Without resource types, it shows how to fix the
discovery.

## Limits

- `/Schemas` publishes only mapped attributes. A request body drops unmapped attributes. A PATCH of an unmapped path
  gives 400 `invalidPath`. Map each attribute that your identity provider sends
  ([Identity providers](identity-providers.md)).
- An element collection maps only an attribute of an RFC 7643 schema: a schema that the application defines has no
  multi-valued complex attribute.
- A Computed attribute is readOnly. It is filterable and sortable only with `#[ScimDqlExpression]` on the Doctrine ORM
  store.
- `LOWER()` folds the case of ASCII letters reliably. The folding of other letters depends on the database.
- SQLite `LIKE` ignores the case of ASCII letters. On SQLite, `co`, `sw` and `ew` thus ignore `caseExact`.
- The bundle stores dates in UTC and compares filter values in UTC. Set `date.timezone = UTC`.
- A PUT, and a PATCH of `members` that does not meet the conditions of [Large groups](#large-groups), load all
  current members of the group.
- A DELETE of a user, soft or not, does not change `meta.version` of its groups. Their ETags stay, although their
  `members` change.
- `If-Match` is atomic only with an integer `#[ORM\Version]` field at `meta.version`, or a custom store or raw
  repository that honors `$version` ([ETags and If-Match](#etags-and-if-match)).
- A `password: null` in a document keeps the stored hash. A replace that changes only the password does not change
  `meta.lastModified`.
- The bundle has no Doctrine checks at container build time. Run `debug:scim`. The store also runs them on first use.
- Error bodies from the firewall and the exception listener are always `application/scim+json`.
