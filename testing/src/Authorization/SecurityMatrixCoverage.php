<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

final class SecurityMatrixCoverage
{
    private function __construct() {}

    /** @return list<string> */
    public static function requiredIds(): array
    {
        return [
            ...self::range('TEN', 1, 20),
            ...self::range('PERM', 1, 39),
            'SYS-013',
            'SYS-014',
            'SYS-019',
        ];
    }

    /** @return array<string, string> */
    public static function evidence(): array
    {
        $evidence = [];
        self::assign($evidence, self::range('TEN', 1, 5), 'AuthorizationPathParityTest CRUD SQL paths');
        self::assign($evidence, self::range('TEN', 6, 8), 'TenantContext type contract and Deptrac boundary gate');
        self::assign($evidence, self::range('TEN', 9, 12), 'Kernel schema foreign keys and atomic path parity tests');
        self::assign($evidence, self::range('TEN', 13, 16), 'Search, aggregate, import, and revalidating export contracts');
        self::assign($evidence, self::range('TEN', 17, 20), 'Tenant status, multi-tenant account, context, and sentinel tests');
        self::assign($evidence, self::range('PERM', 1, 3), 'FunctionalAuthorizationTest and no-policy fail-closed tests');
        self::assign($evidence, self::range('PERM', 4, 13), 'DataPermissionEngineTest six scopes, merge, expiry, and revision tests');
        self::assign($evidence, self::range('PERM', 14, 23), 'SQL path parity, contracts, cache, and bounded target tests');
        self::assign($evidence, self::range('PERM', 24, 35), 'Catalog, typed target, cardinality, batch, aggregate, and candidate tests');
        self::assign($evidence, self::range('PERM', 36, 39), 'Shared-master and policy delegation tests');
        self::assign($evidence, ['SYS-013', 'SYS-014', 'SYS-019'], 'Multi-target audit identity and digest test');

        return $evidence;
    }

    /** @return list<string> */
    private static function range(string $prefix, int $from, int $to): array
    {
        $ids = [];
        for ($number = $from; $number <= $to; ++$number) {
            $ids[] = sprintf('%s-%03d', $prefix, $number);
        }

        return $ids;
    }

    /**
     * @param array<string, string> $evidence
     * @param list<string> $ids
     */
    private static function assign(array &$evidence, array $ids, string $description): void
    {
        foreach ($ids as $id) {
            $evidence[$id] = $description;
        }
    }
}
