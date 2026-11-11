# Business field protection: PHP Core public API

The technical API is PeanutAdmin\Kernel\Protection: FieldProtectionContext,
FieldProtector, SodiumFieldProtector, and FieldProtectionException. It is
**separate** from PeanutAdmin\Settings\Secret\SecretStorageContext, which is
only for Settings definitions. No new service container, permission model,
key management platform, database table or migration is introduced.

For each persisted sensitive field, the owning business module resolves the
**trusted** tenant ID, resource type, immutable record ID and field name from
its tenant-scoped record, then constructs FieldProtectionContext. The output
envelope is a JSON-compatible map with version=1,
algorithm=XCHACHA20-POLY1305-IETF, key_id, base64 nonce and base64 ciphertext.
Never return the envelope in public API responses or log plaintext. Its AEAD
associated data binds the tenant, resource, record, field and key ID; moving
ciphertext between these identities cannot decrypt.

The deployment provides key ID => **raw 32-byte key** and an active key ID:
new SodiumFieldProtector($keys, $activeKeyId). Convert any existing
environment-managed base64 secrets into raw bytes before constructing it.
No keys ship in the Core or a module. Missing or invalid keys, unknown envelope
versions, tampering and corrupted fields fail closed with redacted errors.

For reveal, **first** evaluate the owning module's registered reveal
permission using the platform's existing authorization evaluator, including
the exact tenant and record. Pass its trusted AuthorizedOperationContext to
reveal($envelope, $field, $authorized, 'sales.contact.reveal'). The final
operation string is the exact permission registered by Sales (example only).
The Core checks operation, tenant, resource and record target. It does **not**
replace the policy evaluator, tenant-scoped record lookup, audit, masking,
export-field restrictions or domain validation. Never treat list/read rights
as a reveal grant.

To rotate, retain old and new keys, set the new active key ID and call
rotate($envelope, $field). This decrypts and re-encrypts **in memory**;
persist the result atomically via the business module's existing transaction
and revision check. On failure, leave the old envelope and old key in place.
Retire old keys only after every bound record is migrated and read back.
Consumer-side withdrawal and access revocation remain business/permission
operations, not a second Core authorization system.

This contract accepts nonempty values up to 4 KiB per field. It does not
include search-on-ciphertext, matching, cross-record migration, automatic
data export, redaction layout, or business lifecycle state. Any additional
indexing belongs to the consuming module's explicit data contract.
