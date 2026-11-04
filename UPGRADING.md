# 升级到 5.0

## 5.0.1：请求内租户入口查询

`TenantEntryBindingResolver` 在真实 ThinkPHP `Request` 的 middleware 数据中缓存同一 host/client 的绑定命中和未命中；不同请求与不同 lookup 实例隔离，lookup 异常不缓存。普通提供 `host()` 的对象仍直接查询。租户冲突、输入校验、禁用绑定和默认上下文合同保持不变。长驻宿主必须为每次 HTTP 请求使用独立 Request，不能跨请求复用该对象。

该修正没有数据库迁移或 Composer 依赖变化；`composer.json` 不嵌入版本，发行版本仍从精确 Git 标签取得。5.0.0 的公开记录与标签保持原样。

5.0 将新密码输入策略与密码散列职责分离。该公开 API 变化不能作为 4.x 的兼容更新；实际公开版本以 GitHub/Packagist 对应标签为准。

## 密码策略与散列

4.x 在 `PasswordHasher` 上设置上下限，并通过 `assertValid()`、`minimumLength()`、`maximumLength()` 访问策略。5.0 把这些构造参数与方法移到 `PasswordPolicy`：

```php
$policy = new \PeanutAdmin\Kernel\Identity\PasswordPolicy(12, 128);
$hasher = new \PeanutAdmin\Kernel\Identity\PasswordHasher();
$policy->assertValid($newPassword);
$hash = $hasher->hash($newPassword);
```

默认新密码策略为 6～1024 UTF-8 字节，配置上限不能超过散列技术输入上限 4096 字节。应用创建/修改/重置凭据时先校验策略；登录和既有摘要重算只使用散列服务，不用新的最小长度要求拒绝旧凭据。

Argon2id 参数与既有摘要验证合同保持。技术输入超过 4096 字节时 hash 拒绝、verify 返回 false。应用分别注入策略与散列服务，可通过宿主原生容器替换；不再向 PasswordHasher 传策略参数，也不自行创建默认对象绕过绑定。

## 租户模块资格

Core 保留 ModuleRuntimeRepository / TenantModuleRecord 的资格读取和 ModuleGuard 检查，应用从当前许可证及套餐产生投影。逐模块开通 Manager、写仓储、配置校验与启停 Hook 合同已经退出；应用直接通过自己的套餐/许可证用例变更权益，并使用现有租户授权修订使旧结果失效。

套餐、期限及成员业务授权属于应用，不由 Core 自动授予。模块业务配置使用应用已有 Settings 能力；外部副作用继续使用所属业务的持久机制，不把许可证到期当成自动补偿或定时事件。
