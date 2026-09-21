# Peanut Admin PHP Core development entry

This repository owns the PHP technical kernel, Scope and module mechanisms, and ThinkPHP integration. Complete account/organization/file/task business features belong to the application repository's official modules. Do not import application business implementations into Core.

## Mandatory documented rules

The single project rule source is the authorized `peanut-business/peanut-admin-project` checkout. Before edits, resolve its actual path and read `AGENTS.md`, `project-rules/document-execution.md`, `project-rules/execution.md`, `project-rules/rule-index.json`, and the applicable source sections. In linked worktrees, verify the Git common directory and workspace mapping instead of assuming that `../` is the Project checkout.

Use Project's `scripts/docs-governance check` / `plan` and completed-receipt `verify` as documented. Do not copy private project documents or customer data into this public repository. If the authoritative checkout is unavailable, report the problem and do only safe read-only inspection; do not reconstruct policy from memory.

Suspected errors or conflicts in effective rules must be presented to the user with evidence and a proposed change BEFORE altering the rule or implementing a conflicting result. Pause the affected slice, not unrelated work. Never weaken requirements or tests simply to obtain a pass. A proposal marked `reviewed_not_rejected` is not approved for implementation. Deletion candidates require explicit confirmation.

Develop on isolated feature worktrees, integrate validated work into `dev`, and push without rewriting history. `main`, tags, formal packages, releases, production and customer-data operations require their own authorization. Keep existing license/provenance and migration identities intact. A docs-only bootstrap change does not require repinning application dependency locks or rerunning unrelated product tests.
