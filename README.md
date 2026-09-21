# Peanut Admin Core PHP

`peanut-admin/core` 的 PHP 技术核心源码仓。A1 目标是执行上下文、Scope、授权协议、模块机制、技术存储／安全协议及 ThinkPHP 接入；完整账号组织、设置、文件账本、任务等业务由 Code 官方模块维护。迁移进度与验收见 Project 的 `docs/development/deep-convergence-a1.md`，不能将开发源码当作全项目完成或发布证明。

日常开发使用 `dev`；`main` 只承载已批准的发布版本。该仓在 2026-09-17 从原 Core 单体仓的 `packages/php` 拆出，初始开发提交为 `d1c25fdd3bc27cc8bd56fcaa2d4cbdc33c906bbe`。

Composer 包名保持 `peanut-admin/core`，版本从 Git 分支／标签取得，开发消费者精确锁定 `dev-dev` 的源码提交。A1 产品目标标识为 `4.0.0-dev`，没有因此创建正式标签或发布新包。现有 Composer 发布标签保留历史身份。
