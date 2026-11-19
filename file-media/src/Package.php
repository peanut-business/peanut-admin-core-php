<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia;

final class Package
{
    public const NAME = 'peanut-admin/core';

    public static function version(): string
    {
        return \Composer\InstalledVersions::isInstalled(self::NAME)
            ? ((string) (\Composer\InstalledVersions::getPrettyVersion(self::NAME) ?? 'dev'))
            : 'dev';
    }

    private function __construct() {}
}
