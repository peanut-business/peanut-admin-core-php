<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use JsonException;

final class ManifestLoader
{
    public function load(string $moduleRoot): ManifestDocument
    {
        $root = realpath($moduleRoot);
        if ($root === false) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', 'Registered module root does not exist.');
        }

        $path = $root . '/module.json';
        if (!is_file($path)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Missing manifest: {$path}");
        }

        try {
            $json = (string) file_get_contents($path);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid manifest JSON: {$path}");
        }
        if (!is_array($data) || array_is_list($data) || !is_object($object)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', 'Manifest root must be an object.');
        }

        $catalog = $this->loadCatalog($root, $data);
        $data['catalog'] = $catalog;
        try {
            $object->catalog = json_decode(
                json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                false,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', 'Manifest catalog is not valid JSON.');
        }

        return ManifestDocument::fromDecodedJson($root, $data, $object);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, list<array<string, mixed>>>
     */
    private function loadCatalog(string $root, array $manifest): array
    {
        $backend = is_array($manifest['backend'] ?? null) ? $manifest['backend'] : [];
        $mapping = [
            'menus' => 'menus',
            'permissions' => 'permissions',
            'protected_resources' => 'protected_resources',
            'target_types' => 'target_types',
            'data_conditions' => 'data_conditions',
            'system_actors' => 'system_actors',
        ];
        $catalog = [];
        foreach ($mapping as $manifestKey => $catalogKey) {
            $relative = $backend[$manifestKey] ?? null;
            if ($relative === null) {
                $catalog[$catalogKey] = [];
                continue;
            }
            if (!is_string($relative) || $relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', "Unsafe catalog path for {$manifestKey}.");
            }
            $path = $root . '/' . $relative;
            $real = realpath($path);
            if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', "Catalog file is outside module root: {$relative}");
            }
            try {
                $decoded = json_decode((string) file_get_contents($real), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', "Invalid catalog JSON: {$relative}");
            }
            if (!is_array($decoded) || !array_is_list($decoded)) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', "Catalog must be a JSON array: {$relative}");
            }
            $catalog[$catalogKey] = $decoded;
        }

        return $catalog;
    }
}
