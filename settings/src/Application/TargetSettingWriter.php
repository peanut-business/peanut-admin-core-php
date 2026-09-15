<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Application;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Host\AuthorizedExternalOperation;
use PeanutAdmin\Settings\Definition\SettingDefinition;

final readonly class TargetSettingWriter
{
    public function __construct(private SettingAdminService $admin) {}

    public function replace(
        AuthorizedExternalOperation $authorized,
        SettingDefinition $definition,
        mixed $value,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        ?string $ifMatch,
        ?string $ifNoneMatch,
        ?DateTimeImmutable $asOf = null,
    ): EffectiveSetting {
        return $this->admin->replaceTarget(
            $authorized,
            $definition,
            $value,
            $effectiveAt,
            $expiresAt,
            $ifMatch,
            $ifNoneMatch,
            $asOf,
        );
    }

    public function unset(
        AuthorizedExternalOperation $authorized,
        SettingDefinition $definition,
        DateTimeImmutable $effectiveAt,
        ?string $ifMatch,
        ?DateTimeImmutable $asOf = null,
    ): EffectiveSetting {
        return $this->admin->unsetTarget($authorized, $definition, $effectiveAt, $ifMatch, $asOf);
    }
}
