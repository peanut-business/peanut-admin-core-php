# Peanut Admin Core PHP

CI 默认 `mode=qualification` 保留完整资格。显式 `mode=identity` 须填写精确 `expected_source_commit` 和 `release_version`，仅生成两轮标准 `release-identity prepare` 归档并逐字节比较输入、锁及 ZIP。库根无提交锁时只解析一次，封存实际锁，第二独立检出复制该锁并严格 install，不重新解析。结果保存 `php-core-identity-*` artifact；不运行测试或消费者资格、不标记 qualified、不发布，不能用于 tag 发行。CI 不取消已有运行。

`peanut-admin/core` 的 PHP 技术核心源码仓。A1 目标是执行上下文、Scope、授权协议、模块机制、技术存储／安全协议及 ThinkPHP 接入；完整账号组织、设置、文件账本、任务等业务由 Code 官方模块维护。迁移进度与验收见 Project 的 `docs/development/deep-convergence-a1.md`，不能将开发源码当作全项目完成或发布证明。

日常开发使用 `dev`；`main` 只承载已批准的发布版本。该仓在 2026-09-17 从原 Core 单体仓的 `packages/php` 拆出，初始开发提交为 `d1c25fdd3bc27cc8bd56fcaa2d4cbdc33c906bbe`。

模块清单可声明 `optional_dependencies: [{"module_key": "vendor.module", "version": "^1.0"}]`，条目形状与 `dependencies` 相同。只有显式加入同一编译集合的可选模块才参与版本核验、依赖顺序、循环检查和公开 exports/imports 边界；缺席不会触发安装、迁移或租户启用，也不会变成 `tenant.requires`。原必需依赖仍必须存在，不能用重复的可选声明降低该要求。

缺席可选模块的普通 PHP 参数／返回类型声明可以保留；没有其源码时不能审计该类型是否公开。命名空间沿用宿主验证的映射或 key 派生布局，不推断第三方包归属。宿主必须按已编译模块选择运行贡献，不加载依赖缺席接口／基类的提供者或执行缺席模块调用；声明可选依赖不授予内部服务、数据表或业务权限。模块选中后仍只允许其明确导出的类型，私有表访问继续拒绝。租户启停始终仅由 `tenant.requires` 决定硬依赖。

Composer 包名保持 `peanut-admin/core`，版本从 Git 分支／标签取得；开发消费者精确锁定 `dev-dev` 的源码提交，发行消费者使用公共渠道的精确版本。已发布版本及来源以 [GitHub Releases](https://github.com/peanut-business/peanut-admin-core-php/releases)、Packagist 对应标签提交及实际 dist 内容为准，开发提交不表示已发行，既有标签不改写。

生产输入政策为 `tools/build-inputs.json`。主 ZIP 直接由固定 source commit 的 Git blob archive 生成，保留现有 ZIP 默认压缩语义，不新增压缩等级；记录实际 Git 可执行文件 SHA/build options/原生供应包身份、动态 zlib 实际版本与内容 SHA（静态内置时由经封存 Git binary 完整覆盖）、准确 archive args、source commit 时间和 producer 脚本/配置摘要。禁用系统/全局 attributes，保留受 source 绑定的 `.gitattributes`，非空 `info/attributes` 拒绝；不把无关 checkout 配置、所有 ldd 库或整机包表收成主 ZIP 门禁。

原生顺序为 `scripts/release-identity inputs` → `scripts/release-identity resolve` → `scripts/release-identity prepare`。`inputs` 只实际采集/验证，不制包、不安装依赖、不标资格；默认 `.local/build-inputs.json`，可用 `CORE_BUILD_INPUTS` 指定本候选独立文件。初次成为本候选期望，已存在 seal 不允许漂移或被覆盖。resolve 实际调用固定官方 Composer PHAR/所选 PHP 和政策参数；prepare 在 archive 前后重采有效输入，绑定 seal 与实际解析锁。源码变化须形成新 seal/新资格，不能拿旧输入宣称新增字段已通过。

角色分别保存：producer 是 Git/zlib/归档输入；resolver 是 PHP 实际 patch/执行文件 SHA、Composer 2.10.2 官方 PHAR SHA、原生 platform 清单、manifest/lock/固定 install 参数；verifier 是 Python 实际执行/共享 runtime、ZIP/JSON 代码及 zlib 身份和 Git 对象读取工具。Python zlib 为 extension 时记录实际模块文件，builtin 时明确由实际 Python executable/shared runtime 内容摘要覆盖，并定向记录实际 zlib 库版本/内容或内置覆盖关系；缺失身份拒绝。平台清单只用于解释 Composer 求解，不代表每个扩展都影响主 ZIP。setup-php 选择 minor，不能以写一个 patch 字符串冒充固定 patch；实际 PHP 记录为真实值，不为 ZIP 复制整台 PHP 宿主。安装前缀不写入有效输入，实际内容和逻辑来源必须可核。

正式发行先显式运行 CI 并填写精确 `release_version`（不带 v），通过后将本仓 Actions variable `CORE_QUALIFICATION_RUN_ID` 固定到该成功 run。schema 2 资格用 archive ZIP 原生安装干净消费者，保存 `build-inputs.json` 字节摘要、源码 commit/tree、有效输入、实际根锁、消费者 manifest/lock、ZIP SHA-256 与逐文件身份到 `php-core-qualified`。生产/资格核当前有效 producer/resolver/verifier 没有漂移；发行只读原包时核真实 run/commit/tree、源码政策/供应约束、seal、qualified 和包内容，不要求未执行 producer 的发布宿主二进制全部相同。锁定安装及当前 verifier 的实际输入另存独立角色回执，用原锁 install，不重解析；同树合并可改变发布 commit，不改变原包 seal。旧 schema/缺字段拒绝，旧历史 CI 未记录信息保持未知。

公开 Packagist dist 与内部候选 ZIP 的容器字节允许不同；正式 Gate 必须核精确 version/source/dist reference，下载实际公开 ZIP，比较规范化路径、文件类型与逐文件摘要，再记录两个 ZIP 各自摘要。随后仅将消费者锁中 Core 的 source/dist 改为已经核验的公开下载包，保留全部依赖版本和其他锁内容，用 Composer install 实际消费该最终 ZIP。原资格锁保留，公开桥接锁单独保存。公开 ZIP 尚不存在、内容不同、下载/消费失败都保持未闭合，不把内部归档资格冒充公开 ZIP 资格；GitHub Release 只能在这一步成功后创建。库根不提交通用解析锁，资格实际锁随不可变 CI artifact 保存；归档缺失或过期必须重新资格。

Core 提供通用技术能力与可直接使用的默认实现；应用业务规则由应用持有。`PasswordPolicy` 默认允许 6～1024 UTF-8 字节的新密码，构造参数可配置上下限；`PasswordHasher` 独立负责 Argon2id 散列、验证和重算判断，其默认安全参数保持不变，技术输入上限为 4096 字节。策略上限不能超过技术输入上限。应用在创建/改密前调用策略校验，既有凭据验证及认证内部摘要不受新策略影响。两者均可由应用继承公开类并通过宿主原生容器分别绑定，消费者使用注入实例。`Clock` 已有接口，`TokenIssuer` 的公开类也允许应用实现替换；替换者须保持令牌安全、格式与消费者合同。Core 不提供第二套应用容器或万能策略引擎。

租户套餐、许可证和业务配置由应用持有。Core 的 `ModuleRuntimeRepository` 提供当前模块资格的读取合同，`ModuleGuard` 统一检查部署安装及租户有效窗口；`TenantModuleRecord` 是资格投影，不要求应用保存逐租户模块开关表。业务权益变更通过现有租户授权修订使旧授权失效，Core 不维护逐模块开通命令或第二套许可证体系。

模块删除自有表时，将历史表名放入 database.retired_tables；它只允许所属模块在正式 database/migrations 原生迁移文件中引用，不授予业务 SQL 访问权。退役表不进入当前 ownedTableOwners 或存在性检查，与当前表及其他模块声明冲突时拒绝编译。

清单以 `ManifestDocument::fromObject(root, decodedObject)` 接收唯一 JSON 对象，内部派生编译数组及确定性摘要，保留 `{}` 与 `[]` 的区别。`ManifestSchemaValidator::assertValid()` 必须落实随包完整 schema（含展开的 catalog）；编译器随后只补模块归属、依赖、权限及关系语义。编译器固定采用 PHP 原生类型反射与 Composer Semver，不接受替代反射／版本匹配 port。`StrictVersionConstraintMatcher` 供宿主共享“非法版本即不兼容”的 Semver 规则。

持久化固定使用 `tenant_id`，Standalone 与 SaaS 使用相同租户列结构。Model 复用 `TenantModel::scopeTenant(query, TenantScope)` 与 `TenantModel::tenantAttributes(TenantScope, attributes)`；幂等服务无模式参数，`IdempotencySchema::tenant()` 生成固定租户列 DDL。旧 tenantless 模式不再是开发 API，不自动转换历史数据库。

授权 AST 的 `JsonArrayContainsColumn` 接收 `TypedResourceTargetSet` 已规范的非空字符串列表，`ColumnNotIn` 接收 `SourceReadScope` 已验证的不超过 500 项非空整数列表；AST 不再重复规范或验证同一输入。首次目标、Scope 与列标识符校验仍由各自输入边界负责。

服务覆盖 registry 只产出已校验的 `bindings()`，交由原生容器绑定；slot 白名单、版本及实现合同保持校验。租户锁名直接使用 `TenantNamespace::lockName()`。缓存使用框架 Cache，文件存储使用 `StorageDriver`，图片元数据使用 `ImageMetadataInspector`；旧通用 TenantCache/key builders、StorageProvider、上传策略、图片计划器，以及未装配的同步 transport 和迁移辅助登记已退出当前 API。图片变体定义和有实际消费者的授权发行合同继续保留。
