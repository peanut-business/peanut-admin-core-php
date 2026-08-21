# Notification and SMS package

This development-stage package owns a reusable Tenant notification inbox,
template contract, recipient and attachment snapshots, channel outbox, and SMS
provider boundary. It does not send a real SMS, expose a generic task payload,
or publish a standalone package release.

## Domain boundary

- A business operation creates a rendered message and one outbox row per
  declared channel in one database transaction. It never invokes an external
  provider.
- Templates use only explicit `{{variable_name}}` placeholders. Missing,
  unknown, nested, recursive, oversized, and non-scalar values fail closed.
- The recipient snapshot stores the active Tenant member/account identifiers,
  display name, and, for SMS, only a masked number plus a keyed digest. The raw
  phone number exists only between a trusted resolver and the provider call.
- Attachments copy immutable display metadata and a `file_*` reference after a
  File/Media adapter proves that the object is ready and belongs to the current
  Tenant. This package never copies bytes, storage keys, URLs, or storage
  credentials.
- Inbox list, read, and bulk read/archive always derive Tenant and member from
  `AuthorizedOperationContext`; request data cannot choose either boundary.

## Task/Job integration

`OutboxTaskSubmissionProvider` registers the trusted task types
`notification.inbox.dispatch` and `notification.sms.dispatch`. Their private
payload contains only `outbox_key`. `InboxTaskHandler` and `SmsTaskHandler`
reload the Tenant-owned outbox and use `JobExecution::jobKey` as the stable
delivery idempotency key.

`NotificationOutboxDispatcher` enqueues and binds the returned job in one outer
PDO transaction. The accepted B01 repository must join an existing transaction
instead of committing independently. The outbox key is also the Task/Job
idempotency key. A failed publication rolls back both writes, while the original
business outbox remains durable for a later dispatch attempt.

SMS delivery uses bounded Task/Job retries, a per-Tenant 60/minute bucket and a
per-recipient-digest 5/hour bucket. Typed provider failures are classified as
retryable or permanent. Stored receipts are restricted to provider key,
provider message key, receipt code, and safe error code. Phone numbers,
provider response bodies, credentials, and exception messages are not stored in
the event ledger.

`LocalDevSmsProvider` performs no network request and no real delivery. It
returns a deterministic redacted receipt and deduplicates by `jobKey` within the
local process.

`DisabledSmsProvider` is the package default. It performs no network request
and fails closed with `SMS_PROVIDER_UNAVAILABLE`. A Host must select a real or
development implementation through the standard service override registry;
provider-name switches and implicit development fallbacks are not supported.

## Owned data

The package owns these tables through `Database\Schema`:

- `pa_notification_template`
- `pa_notification_message`
- `pa_notification_attachment`
- `pa_notification_outbox`
- `pa_sms_rate_bucket`
- `pa_notification_event`

Rows use restrictive foreign keys and never cascade-delete notification,
delivery, or audit evidence. Template updates and inbox changes use revisions.
Cross-Module File/Media references intentionally do not use a foreign key; the
trusted adapter validates ownership and readiness before the snapshot is
written.

## I04 integration handoff

The Stage B integration owner must add the shared wiring without changing these
feature-local contracts:

- root Composer and pnpm workspace/lock entries using existing dependencies;
- Module manifest, six owned tables, additive migration and uninstall order;
- permissions `peanut.notification-sms.read` and
  `peanut.notification-sms.manage`, and protected resource operations `read`
  and `manage`;
- Tenant APIs for inbox list, mark-read, bulk read/archive, template management,
  notification creation, and pending-outbox dispatch;
- a File/Media attachment resolver that accepts only current-Tenant `ready`
  objects and an active-member recipient/SMS resolver whose keyed digest is
  stable for the Tenant;
- registration of both submission providers and both handlers in the trusted
  Task/Job registries; no generic handler/payload endpoint;
- the accepted B01 transaction-joining enqueue implementation; integration
  must prove task enqueue and outbox job binding commit or roll back together;
- Problem Details, OpenAPI/generated artifacts, Runtime coverage, shared audit
  projection, `/app/notifications`, standard Admin registration, canonical
  starter, manifests, and locks;
- explicit provider configuration. Production-like deployments must replace
  `LocalDevSmsProvider`; missing/unknown providers fail closed. No provider SDK
  or online dependency is introduced by this feature.

The integration must preserve the package's Tenant/member scoping and must not
expose recipient phone values, Task payloads, provider response bodies, or
File/Media storage details through API, audit, logs, generated clients, or UI.
