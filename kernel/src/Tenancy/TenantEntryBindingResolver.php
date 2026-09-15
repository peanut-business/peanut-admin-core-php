<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Persistence\Model\TenantEntryBinding;

final readonly class TenantEntryBindingResolver
{
    public const ADMIN_CLIENT = 'admin-web';
    public const MEMBER_CLIENT = 'member-api';

    /** @param null|\Closure(string,string,string):TenantSystemContext $defaultSystem */
    public function __construct(
        private ?\Closure $defaultSystem = null,
        private bool $bindingsEnabled = true,
    ) {}

    public function loginTenantCode(object $request, string $clientKey, ?string $explicitTenantCode): ?string
    {
        $explicitTenantCode = $explicitTenantCode === null ? null : trim($explicitTenantCode);
        if ($explicitTenantCode === '') {
            $explicitTenantCode = null;
        }
        $binding = $this->binding(self::requestHost($request), $clientKey);
        if ($binding === null) {
            return $explicitTenantCode;
        }
        if ($explicitTenantCode !== null && !hash_equals($binding['tenant_code'], $explicitTenantCode)) {
            throw new \DomainException('TENANT_ENTRY_BINDING_CONFLICT');
        }
        return $binding['tenant_code'];
    }

    public function boundTenantId(object $request, string $clientKey): ?int
    {
        return $this->binding(self::requestHost($request), $clientKey)['tenant_id'] ?? null;
    }

    public function assertTenantAccess(object $request, string $clientKey, int $tenantId): void
    {
        $boundTenantId = $this->boundTenantId($request, $clientKey);
        if ($boundTenantId !== null && $boundTenantId !== $tenantId) {
            throw new \DomainException('TENANT_ENTRY_BINDING_CONFLICT');
        }
    }

    public function system(object $request, string $clientKey, string $actor, string $operation, string $operationId): TenantSystemContext
    {
        $actor = trim($actor);
        $operation = trim($operation);
        $operationId = trim($operationId);
        if ($actor === '' || $operation === '' || $operationId === '') {
            throw new \DomainException('TENANT_ENTRY_BINDING_UNAVAILABLE');
        }
        $binding = $this->binding(self::requestHost($request), $clientKey);
        if ($binding !== null) {
            return new TenantSystemContext($binding['tenant_id'], $actor, $operation, $operationId);
        }
        if ($this->defaultSystem === null) {
            throw new \DomainException('TENANT_ENTRY_BINDING_UNAVAILABLE');
        }
        return ($this->defaultSystem)($actor, $operation, $operationId);
    }

    public static function normalizeHost(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || str_contains($value, ',') || preg_match('/[\/@?#]/', $value) === 1) {
            throw new \DomainException('TENANT_ENTRY_HOST_INVALID');
        }
        $host = parse_url('//' . $value, PHP_URL_HOST);
        $host = is_string($host) ? rtrim($host, '.') : '';
        if ($host === '' || strlen($host) > 253 || !self::validHost($host)) {
            throw new \DomainException('TENANT_ENTRY_HOST_INVALID');
        }
        return $host;
    }

    /** @return array{tenant_id:int,tenant_code:string}|null */
    private function binding(string $host, string $clientKey): ?array
    {
        $host = self::normalizeHost($host);
        $clientKey = trim($clientKey);
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $clientKey) !== 1) {
            throw new \DomainException('TENANT_ENTRY_CLIENT_INVALID');
        }
        if (!$this->bindingsEnabled) {
            return null;
        }
        try {
            $rows = TenantEntryBinding::alias('binding')
                ->join('tenant tenant', 'tenant.id = binding.tenant_id')
                ->where('binding.host', $host)
                ->where('binding.client_key', $clientKey)
                ->field([
                    'binding.tenant_id',
                    'binding_status' => 'binding.status',
                    'tenant_code' => 'tenant.code',
                    'tenant_status' => 'tenant.status',
                ])
                ->order('binding.id')
                ->limit(2)
                ->select()
                ->toArray();
        } catch (\Throwable $exception) {
            throw new \DomainException('TENANT_ENTRY_BINDING_UNAVAILABLE', 0, $exception);
        }
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1) {
            throw new \DomainException('TENANT_ENTRY_BINDING_UNAVAILABLE');
        }
        $row = $rows[0];
        $tenantId = (int) ($row['tenant_id'] ?? 0);
        $tenantCode = trim((string) ($row['tenant_code'] ?? ''));
        if (($row['binding_status'] ?? null) !== 'active' || ($row['tenant_status'] ?? null) !== 'active' || $tenantId < 1 || $tenantCode === '') {
            throw new \DomainException('TENANT_ENTRY_BINDING_UNAVAILABLE');
        }
        return ['tenant_id' => $tenantId, 'tenant_code' => $tenantCode];
    }

    private static function requestHost(object $request): string
    {
        if (!method_exists($request, 'host')) {
            throw new \DomainException('TENANT_ENTRY_HOST_INVALID');
        }
        return self::normalizeHost((string) $request->host());
    }

    private static function validHost(string $host): bool
    {
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) === 1;
    }
}
