<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

final class ModuleProviderBindings
{
    /**
     * @param iterable<ModuleProvider> $providers
     * @return array<class-string, class-string>
     */
    public static function collect(iterable $providers): array
    {
        $bindings = [];
        foreach ($providers as $provider) {
            $contribution = $provider->bindings();
            ksort($contribution, SORT_STRING);
            foreach ($contribution as $contract => $implementation) {
                if (!is_string($contract)
                    || !is_string($implementation)
                    || (!interface_exists($contract) && !class_exists($contract))
                    || !class_exists($implementation)
                    || !is_a($implementation, $contract, true)) {
                    throw new ModuleException(
                        'MODULE_BINDING_INVALID',
                        "Invalid container binding from {$provider->moduleKey()}.",
                    );
                }
                if (isset($bindings[$contract])) {
                    throw new ModuleException(
                        'MODULE_REGISTRY_CONFLICT',
                        "Duplicate container binding for {$contract}.",
                    );
                }
                $bindings[$contract] = $implementation;
            }
        }
        ksort($bindings, SORT_STRING);

        return $bindings;
    }
}
