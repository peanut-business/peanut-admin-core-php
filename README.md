# Peanut Admin Core PHP

`peanut-admin/core` 的 PHP 技术核心源码仓。A1 目标是执行上下文、Scope、授权协议、模块机制、技术存储／安全协议及 ThinkPHP 接入；完整账号组织、设置、文件账本、任务等业务由 Code 官方模块维护。迁移进度与验收见 Project 的 `docs/development/deep-convergence-a1.md`，不能将开发源码当作全项目完成或发布证明。

日常开发使用 `dev`；`main` 只承载已批准的发布版本。该仓在 2026-09-17 从原 Core 单体仓的 `packages/php` 拆出，初始开发提交为 `d1c25fdd3bc27cc8bd56fcaa2d4cbdc33c906bbe`。

Composer 包名保持 `peanut-admin/core`，版本从 Git 分支／标签取得，开发消费者精确锁定 `dev-dev` 的源码提交。A1 产品目标标识为 `4.0.0-dev`，没有因此创建正式标签或发布新包。现有 Composer 发布标签保留历史身份。

Core 提供通用技术能力与可直接使用的默认实现；应用业务规则由应用持有。`PasswordPolicy` 默认允许 6～1024 UTF-8 字节的新密码，构造参数可配置上下限；`PasswordHasher` 独立负责 Argon2id 散列、验证和重算判断，其默认安全参数保持不变，技术输入上限为 4096 字节。策略上限不能超过技术输入上限。应用在创建/改密前调用策略校验，既有凭据验证及认证内部摘要不受新策略影响。两者均可由应用继承公开类并通过宿主原生容器分别绑定，消费者使用注入实例。`Clock` 已有接口，`TokenIssuer` 的公开类也允许应用实现替换；替换者须保持令牌安全、格式与消费者合同。Core 不提供第二套应用容器或万能策略引擎。
