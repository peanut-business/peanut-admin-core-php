# Peanut Admin Ops Console

This development-only first-party package defines fail-closed, platform-audience
operations evidence, trusted backup/restore task, maintenance-window, and
structured redacted-log contracts.

It does not execute commands, read arbitrary paths, own a queue, expose raw
logs, or restore over an active target. Host adapters and shared integration are
owned by `PA-SV1-C03-I05`; see the canonical
[Ops Console contract](../../../docs/status/p1-c03-ops-console-contract.md).
