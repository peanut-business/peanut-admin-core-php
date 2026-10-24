# Peanut Admin Core PHP

CI 默认 `mode=qualification` 保留完整资格。显式 `mode=identity` 须填写精确 `expected_source_commit` 和 `release_version`，仅生成两轮标准 `release-identity prepare` 归档并逐字节比较输入、锁及 ZIP。库根无提交锁时只解析一次，封存实际锁，第二独立检出复制该锁并严格 install，不重新解析。结果保存 `php-core-identity-*` artifact；不运行测试或消费者资格、不标记 qualified、不发布，不能用于 tag 发行。CI 不取消已有运行。

`peanut-admin/core` 的 PHP 技术核心源码仓。A1 目标是执行上下文、Scope、授权协议、模块机制、技术存储／安全协议及 ThinkPHP 接入；完整账号组织、设置、文件账本、任务等业务由 Code 官方模块维护。迁移进度与验收见 Project 的 `docs/development/deep-convergence-a1.md`，不能将开发源码当作全项目完成或发布证明。

日常开发使用 `dev`；`main` 只承载已批准的发布版本。该仓在 2026-09-17 从原 Core 单体仓的 `packages/php` 拆出，初始开发提交为 `d1c25fdd3bc27cc8bd56fcaa2d4cbdc33c906bbe`。

模块清单可声明 `optional_dependencies: [{"module_key": "vendor.module", "version": "^1.0"}]`，条目形状与 `dependencies` 相同。只有显式加入同一编译集合的可选模块才参与版本核验、依赖顺序、循环检查和公开 exports/imports 边界；缺席不会触发安装、迁移或租户启用，也不会变成 `tenant.requires`。原必需依赖仍必须存在，不能用重复的可选声明降低该要求。

缺席可选模块的普通 PHP 参数／返回类型声明可以保留；没有其源码时不能审计该类型是否公开。命名空间沿用宿主验证的映射或 key 派生布局，不推断第三方包归属。宿主必须按已编译模块选择运行贡献，不加载依赖缺席接口／基类的提供者或执行缺席模块调用；声明可选依赖不授予内部服务、数据表或业务权限。模块选中后仍只允许其明确导出的类型，私有表访问继续拒绝。租户启停始终仅由 `tenant.requires` 决定硬依赖。

Composer 包名保持 `peanut-admin/core`，版本从 Git 分支／标签取得；开发消费者精确锁定 `dev-dev` 的源码提交，发行消费者使用公共渠道的精确版本。已发布版本及来源以 [GitHub Releases](https://github.com/peanut-business/peanut-admin-core-php/releases)、Packagist 对应标签提交及实际 dist 内容为准，开发提交不表示已发行，既有标签不改写。

正式发行先显式运行 CI 并填写精确 `release_version`（不带 v），通过后将本仓 Actions variable `CORE_QUALIFICATION_RUN_ID` 固定到该成功 run。资格使用 `git archive` ZIP 安装干净 Composer 消费者，封存源码 commit/tree、PHP/Composer 工具、资格实际根依赖锁、消费者 manifest/lock、ZIP SHA-256 与逐文件内容身份到 `php-core-qualified` artifact。已有输出拒绝覆盖。tag 工作流只采用该固定 run 的归档，严格核 tree、工具与精确版本，用资格根锁安装，禁止重新解析依赖。无内容变化的合并可改变 commit，但不能改变 tree 或资格输入。

公开 Packagist dist 与内部候选 ZIP 的容器字节允许不同；正式 Gate 必须核精确 version/source/dist reference，下载实际公开 ZIP，比较规范化路径、文件类型与逐文件摘要，再记录两个 ZIP 各自摘要。随后仅将消费者锁中 Core 的 source/dist 改为已经核验的公开下载包，保留全部依赖版本和其他锁内容，用 Composer install 实际消费该最终 ZIP。原资格锁保留，公开桥接锁单独保存。公开 ZIP 尚不存在、内容不同、下载/消费失败都保持未闭合，不把内部归档资格冒充公开 ZIP 资格；GitHub Release 只能在这一步成功后创建。库根不提交通用解析锁，资格实际锁随不可变 CI artifact 保存；归档缺失或过期必须重新资格。

Core 提供通用技术能力与可直接使用的默认实现；应用业务规则由应用持有。`PasswordPolicy` 默认允许 6～1024 UTF-8 字节的新密码，构造参数可配置上下限；`PasswordHasher` 独立负责 Argon2id 散列、验证和重算判断，其默认安全参数保持不变，技术输入上限为 4096 字节。策略上限不能超过技术输入上限。应用在创建/改密前调用策略校验，既有凭据验证及认证内部摘要不受新策略影响。两者均可由应用继承公开类并通过宿主原生容器分别绑定，消费者使用注入实例。`Clock` 已有接口，`TokenIssuer` 的公开类也允许应用实现替换；替换者须保持令牌安全、格式与消费者合同。Core 不提供第二套应用容器或万能策略引擎。

`TenantModuleManager` 的 `TenantModuleEnableHook` 是租户启停命令的事务内前置回调：依赖与配置校验通过后、租户模块记录写入前调用，由调用方包裹数据库事务。回调异常向调用方传播并触发事务回滚；实现只能执行可回滚的数据库操作，外部副作用与持久任务应走应用业务的提交后机制。该回调不表示定时生效、到期或模块安装事件；对未过期的已启用记录重复启用、对已停用记录重复停用时不会再次调用回调。
