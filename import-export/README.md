# Import / Export Core Contract

`peanut-admin/import-export` owns the reusable Tenant import/export ledger. It
does not own application data and never accepts a PHP class name, SQL text,
table name, filesystem path, or executable command from a request.

## Module And Permissions

- Module and resource key: `peanut.import-export`.
- Tenant audience only. A platform identity or a missing Tenant context fails
  closed.
- `peanut.import-export.read` lists and reads operations and their redacted row
  errors.
- `peanut.import-export.create` submits CSV imports and exports.
- `peanut.import-export.cancel` requests cancellation. Queued Task/Job work is
  cancelled immediately; running work observes the request between rows or
  provider batches.
- Tenant identity and actor membership come only from
  `AuthorizedOperationContext`. They are never accepted in request data.

The integration owner registers these permissions, the Module manifest, Host
routes, OpenAPI/generated artifacts, Runtime coverage, standard-admin route,
and canonical starter wiring in `PA-SV1-C03-I05-stage-c-integration`.

## Owned Tables

`pa_import_export_operation` is Module-owned and contains:

| Field | Contract |
| --- | --- |
| `operation_key` | unique ASCII `iox_` plus 32 lowercase hexadecimal chars |
| `tenant_id`, `created_by_member_id` | mandatory Tenant/member composite ownership |
| `provider_key` | registered provider key, never a class or table name |
| `direction`, `format` | `import` or `export`; format is fixed to `csv` |
| `status` | `queued`, `running`, `cancel_requested`, `succeeded`, `failed`, `cancelled`, or `expired` |
| `input_file_key` | mandatory only for import; Tenant-private File/Media key |
| `result_file_key`, `error_file_key` | nullable Tenant-private File/Media output keys |
| `task_job_key` | nullable until atomically associated with the Task/Job submission |
| `schema_revision`, `mapping_json` | provider schema snapshot and canonical source-to-target mapping |
| `processed_rows`, `accepted_rows`, `rejected_rows`, `total_rows` | non-negative progress counters with accepted + rejected <= processed |
| `attempt_number` | monotonic Task/Job attempt fence |
| `idempotency_key_hash`, `request_hash` | SHA-256 only; raw keys and request data are not persisted |
| `last_error_code` | stable allowlisted code only; no exception detail |
| `revision` | optimistic update and cancellation fence, starts at 1 |
| `retention_until` | UTC retention deadline from 1 to 90 days |
| timestamps | UTC millisecond timestamps; completion is present only for terminal states |

Unique keys cover `operation_key`, `(tenant_id, id)`, and
`(tenant_id, created_by_member_id, direction, provider_key,
idempotency_key_hash)`. Claim/progress, Tenant/status, retention, and Task/Job
lookups are indexed. Foreign keys bind the operation to its Tenant and member.

`pa_import_export_row_error` is Module-owned and contains the Tenant/operation,
1-based CSV row number, nullable registered target column key, stable error
code, and occurrence time. It never stores the rejected cell, raw row, provider
exception, SQL, path, token, or personal data. `(tenant_id, operation_id,
row_number, column_key, error_code)` is unique so a retry cannot duplicate
evidence. Errors are listed in row order and exported as a redacted CSV report.

Migrations are additive and idempotent through the Module ledger. Down
migration is not a normal recovery mechanism. A code rollback leaves the
tables inert. Retention changes terminal operations to `expired`; physical
deletion and File/Media lifecycle cleanup are a later explicit policy.

## State Machine And Concurrency

```text
queued -> running -> succeeded
   |         |  \-> failed
   |         |  \-> cancel_requested -> cancelled
   |         \----> cancelled
   \----------------> cancelled

succeeded | failed | cancelled -> expired
```

Submission creates the ledger row and the trusted Task/Job row on the same PDO
transaction. The host must wire both repositories to that connection; a split
connection is an invalid integration. An exact idempotency replay returns the
existing operation, while another request hash returns
`IMPORT_EXPORT_IDEMPOTENCY_CONFLICT`.

Each handler attempt records the Task/Job key and a strictly increasing attempt
number. Progress and completion updates are fenced by Tenant, operation key,
Task/Job key, attempt number, and expected state. A stale attempt cannot update
a newer attempt. Provider import calls receive
`<operation_key>:row:<row_number>` as the stable business idempotency key.

The task checks cancellation before each import row and export batch. Queued
cancellation is composed with Task/Job cancellation in the same transaction;
running cancellation is cooperative. A cancelled operation never publishes a
result. Terminal operations are immutable except for retention expiry.

## CSV And Provider Boundary

CSV is UTF-8, comma-delimited, RFC 4180 compatible, and has exactly one header
row. Spreadsheet formulas are treated as text. No spreadsheet dependency is
introduced. Limits are fixed at 100 columns, 100,000 data rows, 1 MiB per row,
and 16 MiB per result or error report. Empty, duplicate, unknown, missing
required, or invalid headers fail before provider writes.

Every provider is registered by a lowercase stable key and exposes an immutable
schema revision plus column definitions. Columns define canonical key, CSV
heading, import/export support, required import status, and maximum UTF-8 byte
length. Import mapping may only map CSV headings to declared import columns and
may not map two headings to one target.

The provider receives normalized string-or-null values, validates them, and
returns only stable `RowIssue` values. A valid row is applied through the
provider with the stable row idempotency key. The provider owns all business
tables, transactions, authorization beyond the registered resource operation,
and domain conflict rules. Export uses bounded provider batches and a
provider-owned opaque cursor; the core never reflects into a class or runs SQL.

File access uses a registered File/Media gateway. Input must resolve to a ready,
Tenant-private CSV object in the context Tenant. Result and error files are
created as Tenant-private File/Media objects and the ledger stores only their
keys and redacted counts.

## Errors, Audit, And UI

Stable errors are `IMPORT_EXPORT_INVALID`,
`IMPORT_EXPORT_PERMISSION_DENIED`, `IMPORT_EXPORT_NOT_FOUND`,
`IMPORT_EXPORT_PROVIDER_UNAVAILABLE`, `IMPORT_EXPORT_FILE_UNAVAILABLE`,
`IMPORT_EXPORT_SCHEMA_MISMATCH`, `IMPORT_EXPORT_IDEMPOTENCY_CONFLICT`,
`IMPORT_EXPORT_STATE_CONFLICT`, `IMPORT_EXPORT_LIMIT_EXCEEDED`, and
`IMPORT_EXPORT_INTERNAL_ERROR`. Public errors never expose provider classes,
SQL, paths, cell values, stack traces, or cross-Tenant existence.

Feature events are `tenant.import_export.submitted`, `.started`, bounded
`.progress`, `.cancel_requested`, `.cancelled`, `.succeeded`, and `.failed`.
Metadata is allowlisted to direction, provider key, counts, safe error code,
retention deadline, and revisions. Raw rows, mapping values, idempotency keys,
file storage keys, and provider exceptions are prohibited.

The feature-local bulk retention repository does not emit `.expired`: it has no
trusted per-Tenant actor context. I05 must either run expiry through a
Tenant-scoped system-audit adapter or explicitly document the host retention
audit owner; the core does not claim an audit event it cannot attribute safely.

The feature-local Web package owns strict response parsers, a disposable
Tenant-scoped runtime, import/export submission forms, status/progress,
cancellation, result/error downloads, permission states, and focused tests. It
exports a Module contribution contract, but I05 owns shared router and standard
admin registration.

## Qualification Stop Line

This lane proves only the feature-local package at a fixed commit. It does not
modify shared manifests or locks, Host routes/controllers, OpenAPI/generated
artifacts, Runtime coverage, shared Web routing, canonical starter, `dev`,
downstream consumption locks, tags, or releases. Aggregate, full browser/build, clean
install/recovery, performance, and cross-OS verification remain deferred to the
fixed-candidate qualification stage.
