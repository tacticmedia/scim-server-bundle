# Identity providers

What Microsoft Entra ID, Okta and other identity providers send, and what to map for each. The
[documentation](index.md) is the reference for the mapping attributes.

## Unmapped attributes

The schemas publish only the mapped attributes ([Limits](index.md#limits)). For an attribute that the class does not
map:

| Request | Result |
| --- | --- |
| A POST or PUT body, or the `value` of a PATCH operation without `path` | The server drops the attribute. |
| A PATCH `path` to the attribute or sub-attribute, for example `title` or `name.formatted` | 400 `invalidPath`. The other operations of the request are not applied either. |
| A PATCH `path` with a `type` that has no slot, for example `emails[type eq "other"].value` when only the `work` slot is mapped | The bundle drops the element. |

Thus map each attribute of the attribute mapping of the identity provider, or remove the attribute from that mapping.

## Microsoft Entra ID

### Connection

Create a non-gallery enterprise application. In **Provisioning**, set:

| Field | Value |
| --- | --- |
| Tenant URL | The base URL, for example `https://example.com/scim/v2`. Optional: append `?aadOptscim062020` for the RFC 7644 PATCH format. The server accepts both formats. On-demand provisioning ignores the flag. |
| Secret Token | A token of `access_tokens` ([Configuration](index.md#configuration)). Entra sends `Authorization: Bearer <token>`. |

Microsoft supports the Secret Token for non-gallery applications. For OAuth 2.0 client credentials or Workload Identity
Federation, use another token handler ([Firewall](index.md#firewall)).

**Test Connection** queries a user by a random GUID. The server answers 200 with an empty `ListResponse`.

### User attributes

The Microsoft SCIM tutorial gives these user mappings as an example. The **Attribute mapping** page of the application
shows its real list. Map each row of that list, or delete the row in Entra.

| Entra attribute | SCIM path | Mapping |
| --- | --- | --- |
| `user-PrincipalName` | `userName` | `#[ScimAttribute('userName')]` |
| `mailNickname` | `externalId` | `#[ScimAttribute('externalId')]` |
| `IsSoftDeleted` | `active` | `#[ScimAttribute('active', default: true)]` |
| `displayName` | `displayName` | `#[ScimAttribute('displayName')]` |
| `givenName` | `name.givenName` | `#[ScimAttribute('name.givenName')]` |
| `surname` | `name.familyName` | `#[ScimAttribute('name.familyName')]` |
| `jobTitle` | `title` | `#[ScimAttribute('title')]` |
| `mail` | `emails[type eq "work"].value` | `#[ScimAttribute('emails[type eq "work"].value', primary: true)]` |
| `proxy-Addresses` | `emails[type eq "other"].Value` | `#[ScimAttribute('emails[type eq "other"].value')]` |
| `telephone-Number` | `phoneNumbers[type eq "work"].value` | `#[ScimAttribute('phoneNumbers[type eq "work"].value', primary: true)]` |
| `mobile` | `phoneNumbers[type eq "mobile"].value` | `#[ScimAttribute('phoneNumbers[type eq "mobile"].value')]` |
| `Facsimile-TelephoneNumber` | `phoneNumbers[type eq "fax"].value` | `#[ScimAttribute('phoneNumbers[type eq "fax"].value')]` |
| `streetAddress` | `addresses[type eq "work"].streetAddress` | `#[ScimAttribute('addresses[type eq "work"].streetAddress', primary: true)]` |
| `postalCode` | `addresses[type eq "work"].postalCode` | `#[ScimAttribute('addresses[type eq "work"].postalCode')]` |
| `physical-Delivery-OfficeName` | `addresses[type eq "other"].Formatted` | `#[ScimAttribute('addresses[type eq "other"].formatted')]` |
| `department` | enterprise `department` | `#[ScimAttribute('department')]` |
| `employeeId` | enterprise `employeeNumber` | `#[ScimAttribute('employeeNumber')]` |
| `manager` | enterprise `manager` | `#[ScimAttribute('manager')]` on `private ?self $manager` |

- Write the sub-attribute in schema spelling: `value`, `formatted`.
- Microsoft's known issues say that Entra processes only the `work` type of `addresses`, `emails` and `phoneNumbers`.
  The tutorial also maps `mobile`, `fax` and `other`. The provisioning logs show what arrives.
- Entra finds users with `userName eq "..."` and `externalId eq "..."` filters. Both are Fields, so both filter.
- Entra does not provision passwords. Do not map `password` for Entra.
- Entra sends no null values. A value that is cleared in Entra stays in the application.

### Group attributes

| Entra attribute | SCIM path | Mapping |
| --- | --- | --- |
| `displayName` | `displayName` | `#[ScimAttribute('displayName', uniqueness: Uniqueness::Server)]` |
| `members` | `members` | `#[ScimAttribute('members[type eq "User"]')]` on a collection of users |
| `objectId` | `externalId` | `#[ScimAttribute('externalId')]` |

- Entra matches groups with `displayName eq "..."`. Microsoft requires a unique `displayName`.
- A member id that no mapped collection finds gives 400 `invalidValue`. When a group has groups as members, also map
  `members[type eq "Group"]` ([References and members](index.md#references-and-members)).

### Deprovisioning

| Event in Entra | Request |
| --- | --- |
| User unassigned, out of scope, disabled or soft-deleted | PATCH `active` false. Entra sends `"False"` without the flag. |
| User restored | PATCH `active` true. |
| User hard-deleted, 30 days after the soft delete or by a purge | DELETE, when the **Delete** target action is on. |
| Group deleted or unassigned | DELETE. |

Microsoft requires that GET and filters return a user whether or not the user is active. Do not hide inactive users
([Soft delete](index.md#soft-delete)).

### Failures

Entra retries a failed object 6, 12 and 24 hours after the failure, then each day for 28 days. Entra puts the job in
quarantine when more than 40 percent of the events fail, from 5,000 failures, or for a 404 where it expects 200. A
quarantined job runs less often, down to once a day. After four weeks in quarantine, Entra disables the job. An unmapped PATCH path fails
each cycle, so map the attributes before you start provisioning.

## Okta

### Connection

| Setting | Value |
| --- | --- |
| Base URL | The base URL, for example `https://example.com/scim/v2`. Okta rejects a base URL with `_`. |
| Authentication | HTTP Header with a token of `access_tokens`. The token extractor reads the token with and without `Bearer`. |
| Unique identifier | `userName`. Okta finds an existing user with `userName eq "..."`. |
| To App | Create Users, Update User Attributes, Deactivate Users. Sync Password only when the class maps `password`. |

### User attributes

Okta requires `userName`, `name.givenName`, `name.familyName`, `emails` and `active`. A create request also has
`displayName`, `locale`, `externalId`, `password` and `groups`.

| SCIM path | Mapping | Notes |
| --- | --- | --- |
| `userName` | `#[ScimAttribute('userName')]` | Required. |
| `name.givenName` | `#[ScimAttribute('name.givenName')]` | Required. |
| `name.familyName` | `#[ScimAttribute('name.familyName')]` | Required. |
| `emails[type eq "work"].value` | `#[ScimAttribute('emails[type eq "work"].value', primary: true)]` | Required. |
| `active` | `#[ScimAttribute('active', default: true)]` | Required. |
| `externalId` | `#[ScimAttribute('externalId')]` | The Okta user id, for example `00ujl29u0le5T6Aj10h7`. |
| `displayName` | `#[ScimAttribute('displayName')]` | |
| `locale` | `#[ScimAttribute('locale')]` | Optional. Dropped when unmapped. |
| `password` | `#[ScimAttribute('password', converter: HashPassword::class)]` | Okta sends a password on create also without password sync. Map it only for password sync ([Password](index.md#password)). Dropped when unmapped. |
| `groups` | | readOnly. The server ignores it. |

Attributes that you add to the Okta app profile arrive with the same rule as [Unmapped attributes](#unmapped-attributes).

### Requests

- A profile update is a GET, then a PUT of the GET document with the changed values. A PUT drops unmapped attributes,
  so they never fail a request.
- New OIN integrations activate, deactivate and sync passwords with PATCH. Other integrations use PUT for each update.
- Okta never deletes a user. It sends `active` false. Okta sends nothing for a suspended user. Do not hide inactive
  users.
- Group Push creates a group with POST. New OIN integrations change a group with PATCH, other integrations with PUT.
  Okta deletes a group only when the administrator unlinks it and selects the delete option.

## Other identity providers

| Identity provider | What to check |
| --- | --- |
| JumpCloud | Activation creates, reads, updates and deletes a test user. JumpCloud finds a user by email, then by `userName`. The default mapping also sends `addresses`, `phoneNumbers` of type `work`, and the enterprise `organization`, `costCenter`, `department` and `employeeNumber`. |
| OneLogin | The administrator writes the JSON template of the user. Map each attribute of the template. |
| PingOne | The administrator writes the matching filter, for example `userName Eq "%s"`. A filter on an unmapped attribute gives 400 `invalidFilter`. |

## Sources

Read on 2026-11-05 and 2026-11-06.

- Microsoft: [SCIM tutorial](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/use-scim-to-provision-users-and-groups),
  [SCIM compliance flags](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/application-provisioning-config-problem-scim-compatibility),
  [known issues](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/known-issues),
  [quarantine](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/application-provisioning-quarantine-status),
  [how provisioning works](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/how-provisioning-works).
- Okta: [SCIM 2.0 guide](https://developer.okta.com/docs/api/openapi/okta-scim/guides/scim-20/),
  [prepare the SCIM API](https://developer.okta.com/docs/guides/scim-provisioning-integration-prepare/main/),
  [connect](https://developer.okta.com/docs/guides/scim-provisioning-integration-connect/main/).
- [JumpCloud](https://jumpcloud.com/support/provision-and-manage-users-and-groups-in-apps-using-custom-scim-identity-management-integration),
  [OneLogin](https://developers.onelogin.com/scim/define-user-schema),
  [PingOne](https://docs.pingidentity.com/pingone/integrations/p1_create_scim_connection.html).
