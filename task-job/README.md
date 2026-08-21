# Task/Job package

This package owns the reusable Tenant task ledger and the local worker contract.
It is a development-stage feature package and is not a standalone queue broker,
scheduler, service manager, or public release.

## Security boundary

- `TrustedJobPublisher` is the only submission entry point. There is no generic
  HTTP operation that accepts a handler key or execution payload.
- A registered `TaskSubmissionProvider` binds one public task type to one
  producer resource and operation. It validates public input and builds the
  private handler payload. Publisher input with a different authorized
  resource or operation fails closed.
- The signed Kernel envelope preserves the producer's Tenant, actor, resource,
  operation, target and trace evidence. `LocalWorker` revalidates it immediately
  before invoking a registered `TaskHandler`. Tenant, account, member, resource,
  operation and normalized typed-target sets must all match the signed envelope.
- `TaskHandler` receives a worker-built `JobExecution`; it must use the stable
  `jobKey` as its side-effect idempotency key. Payloads cannot override the job
  key, Tenant or attempt number.
- Workers claim within one explicit Tenant. A worker cannot claim or renew a
  different Tenant's job.
- Public job shapes omit handler keys, payloads, envelopes, idempotency hashes,
  worker identifiers and lease tokens. Audit metadata contains only stable task
  type, producer keys, attempt numbers, bounded backoff and safe error codes.

## Data and state machine

The Module owns `pa_task_job`, `pa_task_job_attempt`, and
`pa_task_job_event`. `Schema::createSql()` is the migration source for the I04
Host Module migration.

```text
queued -> running -> succeeded
                  -> queued (typed retry, attempts remain)
                  -> dead
queued -> cancelled
running --expired lease--> queued or dead at max_attempts
dead --explicit manage + revision--> queued for one bounded attempt
```

Claim uses `FOR UPDATE SKIP LOCKED`; every attempt has a random lease token and
only its SHA-256 digest is stored. Complete, fail and renew compare the digest,
attempt number, Tenant and unexpired lease. Expired attempts become
`abandoned`, and their old token cannot commit. Automatic retries use 5, 10,
20, 40, 80, 160 and then at most 300 seconds. Jobs have 1-10 attempts; an
explicit retry of a dead job grants one additional attempt but never exceeds
10.

The canonical provider payload is protected by `payload_hash`. Claim and the
immediate pre-handler check both reject a payload mismatch. Expired recovery
locks the job and its current running attempt, then requires their lease-token
digests to match; a missing or corrupt attempt fails closed without recovering
the job.

Submission idempotency is scoped by Tenant, producer member, and task type.
Only the key digest is stored. An exact request returns the existing job; reuse with another
provider-built request returns `TASK_IDEMPOTENCY_CONFLICT`.

State changes and their redacted `tenant.task.*` event are committed in the
same PDO transaction. The Host may project these events into the shared Tenant
audit query, but it must not replace or weaken this package ledger.

`TrustedJobPublisher` participates when its repository PDO is already inside a
Host business/outbox transaction; the caller owns commit and rollback. Without
an existing transaction it opens and closes one atomic enqueue transaction.
Claim, lease and completion always use short worker-owned transactions, so a
handler never runs while a claim transaction holds database locks.

## I04 integration handoff

The Stage B integration owner must add, without changing these feature-local
contracts:

- root Composer and pnpm workspace entries and lock regeneration using existing
  dependencies only;
- `peanut.task-job` Module manifest with the three owned tables;
- Module migration using `Schema::tableNames()` and `Schema::createSql()`;
- permissions `peanut.task-job.read` and `peanut.task-job.manage`;
- protected resource `peanut.task-job` operations `read` and `manage`;
- Tenant APIs `GET /api/v1/tasks`, `GET /api/v1/tasks/{job_key}`,
  `POST /api/v1/tasks/{job_key}/cancel`, and
  `POST /api/v1/tasks/{job_key}/retry`; both writes require a strong revision
  precondition and accept no handler or payload field;
- stable Problem Details mapping, OpenAPI/generated routes and types, Runtime
  coverage, `/app/tasks`, standard Admin registration, canonical starter and
  locks;
- a local CLI worker that receives an explicit Tenant and trusted worker ID;
  no external broker, cron daemon, online dependency or cross-Tenant scan;
- shared Tenant audit projection for `tenant.task.submitted`, `claimed`,
  `retry_scheduled`, `lease_recovered`, `succeeded`, `dead`, `cancelled`, and
  `retried` with the package allowlist.

Notification/SMS may start from this package by registering providers and
handlers in its own feature lane. It must not edit Task/Job files or expose a
generic submission endpoint. I04 invokes `TrustedJobPublisher` with the same
PDO from the business/outbox transaction; a rollback must remove the outbox,
job and task audit event together.

## Focused evidence

`tests/feature-harness.php` exercises schema creation, idempotency conflict and
replay, Tenant isolation, operation permission checks, atomic claims, lease
fencing and recovery, bounded retry, unknown-handler failure, optimistic
management and redacted audit events against MySQL 8.
