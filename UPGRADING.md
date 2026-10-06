# 升级到 5.0

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

## 租户模块钩子

`TenantModuleEnableHook` 仍是租户启停命令的事务内前置回调，非安装/启动或定时生效事件。Manager 构造时拒绝未知/受保护模块或无效实现。尚未过期的 enabled 记录（包含未来生效记录）重复 enable、已 disabled 记录重复 disable，不重复钩子或状态写入。

调用者保留外层事务、授权、依赖校验和审计；钩子异常传播。回调只执行可回滚数据库操作，外部副作用使用应用持久业务机制。应用需把可信配置或 Provider 的钩子交给同一 Manager，不改 Core 源码扩展业务。
