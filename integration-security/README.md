# Integration Security package

This development-stage package owns reusable Tenant machine credentials,
outbound Webhook safety, delivery evidence, and self-scoped Tenant session
device controls. It is not a public release and does not include the Host API,
real network transport, Module manifest, standard Admin Web wiring, or starter
integration; those shared surfaces belong to Stage C I05.

## Authorization and public boundary

Every user-facing method accepts a Kernel `AuthorizedOperationContext`, checks
the exact `peanut.integration-security` resource and operation, and derives the
Tenant, account, member, current session, and request from the validated Tenant
context. Platform-audience and machine-audience credentials cannot construct
that context. I05 binds these operations to dedicated permissions:

| Operation | Permission |
| --- | --- |
| `machine-read` | `peanut.integration-security.machine.read` |
| `machine-manage` | `peanut.integration-security.machine.manage` |
| `webhook-read` | `peanut.integration-security.webhook.read` |
| `webhook-manage` | `peanut.integration-security.webhook.manage` |
| `delivery-read` | `peanut.integration-security.delivery.read` |
| `session-read` | `peanut.integration-security.session.read` |
| `session-revoke` | `peanut.integration-security.session.revoke` |

Session operations are always self-scoped. No account, member, Tenant, handler,
worker, signing secret, token digest, or arbitrary destination address is
accepted from an HTTP client. A trusted application producer may enqueue a
typed Webhook event through `TrustedWebhookPublisher`; it cannot select a
handler or secret.

I05 exposes only these Tenant-audience operations under `/api/v1`:

- `GET|POST /integration-security/machine-identities`,
  `POST /integration-security/machine-identities/{identity_key}/rotate`, and
  `DELETE /integration-security/machine-identities/{identity_key}`;
- `GET|POST /integration-security/webhooks`,
  `POST /integration-security/webhooks/{endpoint_key}/rotate-secret`, and
  `DELETE /integration-security/webhooks/{endpoint_key}`;
- `GET /integration-security/deliveries` and
  `GET /integration-security/deliveries/{delivery_key}/attempts`;
- `GET /integration-security/sessions` and
  `POST /integration-security/sessions/{session_key}/revoke`.

Create and rotate bodies contain only names, scopes, expiry, URL, and event
subscriptions as applicable. Tenant/account/member/handler/secret fields and
undeclared fields are rejected. I05 composes mutating HTTP operations with the
existing same-PDO idempotency primitive; exact replay returns the original
safe response, while a changed payload conflicts. A replay response can never
re-disclose a machine token or signing secret, so credential creation/rotation
requires the original successful response to be captured by the caller.
The Admin route uses `peanut.integration-security.access`; machine, Webhook,
delivery, and session data then load independently under their own read
permissions, so denial or failure of one surface cannot block the others.

## Schema and lifecycle

The package owns five additive InnoDB tables. I05 creates them through one
Module-owned migration using `Database\\Schema`.

- `pa_integration_machine_identity`: Tenant key, server-generated identity key,
  name, sorted unique scope JSON, status, token prefix/digest/last four, family,
  expiry, last use, rotation/revocation timestamps, creator, revision, and
  timestamps. `(tenant_id, identity_key)` and `token_digest` are unique. Active
  identities may become `rotated` or `revoked`; terminal states never reactivate.
- `pa_integration_webhook_endpoint`: Tenant key, server-generated endpoint key,
  HTTPS URL, sorted unique event keys, encrypted signing secret plus key id,
  active/disabled status, creator, revision, and timestamps. Secrets are
  server-generated, disclosed only in the initial/rotation result, encrypted
  with AES-256-GCM at rest using the Tenant/endpoint key as authenticated
  associated data, and absent from ordinary records and audit.
- `pa_integration_webhook_delivery`: one Tenant/endpoint/event idempotency row,
  canonical payload, digest, state, bounded attempt count, availability/lease,
  safe result code, payload expiry, and timestamps. States are
  `pending -> delivering -> delivered`, `delivering -> retryable -> delivering`,
  or `delivering -> permanent_failed`. An expired lease records a redacted
  `WEBHOOK_LEASE_EXPIRED` attempt in the same transaction; attempts below eight
  become retryable and attempt eight becomes terminal.
  Maximum attempts are eight. `(tenant_id, endpoint_id, event_key)` is unique.
- `pa_integration_webhook_attempt`: immutable redacted attempt evidence with
  outcome, HTTP status, safe error code, duration, and timestamp. It contains no
  URL, IP, header, body, secret, token, exception, or provider response.
- `pa_integration_security_event`: immutable redacted audit evidence. Target
  keys are SHA-256 digests and metadata is bounded scalar JSON.

Tables use Tenant-prefixed indexes and composite foreign keys. Tenant and
member parents are `RESTRICT`; endpoints and machine identities are retained
and disabled/revoked rather than deleted. Delivery payload is erased after
seven days; terminal delivery and attempt evidence is retained for 30 days and
then purged by an explicit maintenance call. Audit retention remains Host
policy and is never cascaded by feature deletes. Rollback is forward-only: a
code rollback leaves inert additive tables and does not restore credentials.

## Credential and Webhook security

Machine tokens contain 256 random bits. Plaintext is returned exactly once on
create or rotation; only a SHA-256 digest, non-secret prefix, and last four are
persisted. Authentication checks format, active state, expiry, Tenant-bound
machine audience, and every requested scope. Rotation atomically creates the
successor and terminally rotates the predecessor; revoke terminally invalidates
the digest. A trusted feature scope catalog rejects unknown scopes, while a
Host-composed `MachineScopeGrantResolver` derives the scopes the current Tenant
member may grant. Callers never submit a grant list, and create and rotation
both re-evaluate current issuer grants. Authentication revalidates every
persisted scope against the current trusted catalog before recording use or
creating a principal, so removed or stale scopes fail closed. Tokens and
secrets are never serializable through ordinary records.

Webhook URLs require HTTPS on port 443, contain no userinfo or fragment, and
use an ASCII DNS name or IP literal. `localhost`, local/internal suffixes,
metadata hostnames, and every loopback, private, link-local, multicast,
unspecified, documentation, or otherwise reserved address fail closed. All DNS
answers must be public. A validated destination carries the approved IP set to
the transport; adapters must connect only to that set with the original Host
and TLS SNI, disable redirects, and perform no independent fallback lookup.
Every send revalidates DNS. A 3xx response is a permanent security failure.
Address classification uses explicit IPv4 and IPv6 CIDR deny ranges covering
loopback, private, link-local, metadata, carrier NAT, translation,
documentation, benchmark, multicast, reserved, and unspecified space. One
unacceptable DNS answer rejects the entire destination.

The signature input is `v1.<unix_timestamp>.<delivery_key>.<payload_sha256>` and
the header is `v1=<lowercase HMAC-SHA256>`. The delivery key is also the
idempotency header. Payloads are canonical JSON, at most 256 KiB, with a replay
timestamp window owned by I05. `408`, `425`, `429`, transport failure and `5xx`
are retryable with bounded backoff; other `4xx` and all redirect/security
failures are permanent. The fake transport used here performs no network I/O.

## Errors and deferred I05 integration

Stable package codes include `INTEGRATION_PERMISSION_DENIED`,
`INTEGRATION_INPUT_INVALID`, `MACHINE_IDENTITY_NOT_FOUND`,
`MACHINE_TOKEN_INVALID`, `MACHINE_TOKEN_EXPIRED`, `MACHINE_SCOPE_DENIED`,
`INTEGRATION_REVISION_CONFLICT`, `WEBHOOK_ENDPOINT_NOT_FOUND`,
`WEBHOOK_DESTINATION_DENIED`, `WEBHOOK_SECRET_INVALID`, and
`SESSION_DEVICE_NOT_FOUND`. Public adapters map these to generic Problem
Details without SQL, existence leaks, addresses, token material, or secrets.

I05 must add root Composer/pnpm workspace and lock entries, Module manifest and
migration, permission/resource bindings, Host controllers/routes, OpenAPI and
generated artifacts, Runtime coverage, audit projection, the real transport
adapter, standard Admin Web contribution, and canonical starter. It must prove
the same-PDO migration/transaction boundary, permission and wrong-audience
behavior, DNS rebinding/redirect rejection, signature/replay/idempotency,
session revoke token invalidation, Web unit/type/build, and additive upgrade.

## Exact write set and stop line

This feature candidate changes only `packages/php/integration-security/**` and
`packages/web/integration-security/**`. I05 owns every root manifest/lock,
backend/frontend Host, OpenAPI/generated, Runtime ledger, router, shell,
canonical starter, and public documentation index change. This task does not
send a real Webhook, add a dependency, install online, publish, tag, move `dev`
or modify a downstream consumption lock.
