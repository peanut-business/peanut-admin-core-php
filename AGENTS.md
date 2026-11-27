# peanut-admin-core-php

**最高研发基准条款：Core 提供底层依赖、Scaffold 提供通用底座、Module 应用开发并发行共用能力、业务应用基于脚手架安装模块后实现业务。**

后续任何本仓规则不得与该四层归属冲突；本仓仅拥有 PHP 技术内核与必要原语，身份、文件、文档、业务状态等完整共用业务归 Module 作者，行业专属业务归其实际应用。只有真实影响 Core 时才发 Core；本条不授予测试、发布或数据操作权限。

PHP technical kernel, Scope, module mechanisms and ThinkPHP integration; no complete business services or reverse imports from Code.

Before editing, locate the authorized peanut-admin-project using Git common-dir/repos mapping; do not assume worktrees are adjacent. Treat Project `AGENTS.md` as the L0 entry, load `python3 scripts/docs-governance read --core --content`, then select only the task route(s) required by the current write set/risk. Read only the current task/stage portions of Project state documents when relevant; do not load STATUS/PLAN wholesale. Approval/implementation state lives only in Project, not copied here. Unavailable authoritative rules: report and limit to safe read-only inspection.

Use Project `docs-governance read --areas <areas>` only to locate domain rule IDs and `read --ids <ids> --content` for exact rules actually needed; follow Project AGENTS when a selected public Code rule requires an explicit Code candidate. After context compression/summary, model or executor switch, Session recovery, `history_lost`, or loss of certainty about the current task/write set/routes, reload Project AGENTS → core → current task route before the next rule-dependent write or expensive validation. Run full governance `check` only at the appropriate governance boundary, and run affected behavior tests for the actual Core change. Do not load entire handbook/API/migration data by default or file self-certified reading receipts. Rules suspected wrong: report evidence/impact and await the user's decision before changing semantics; pause only affected work.

Use the existing dev tree for ordinary serial changes; create isolated worktrees only for demonstrated concurrent write conflicts or an explicitly required qualification boundary. Preserve concurrent work; normal validated integration/push to dev, verify remote refs; never force/rewrite history. main, formal publication/deployment, real customer/financial data, paid plans, visibility and license changes require applicable authorization. No private rules, credentials or customer data in public repos.

Absorb useful rules/open issues before deleting confirmed duplicates or unused one-time tools; no archive/redirect stubs. Preserve live tests, migrations, licenses and others' resources. Docs-only changes neither prove runtime acceptance nor require repinning packages or unrelated product tests.
