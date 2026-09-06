<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

/** Narrow Host transport used by storage drivers that do not own an HTTP client. */
interface StorageHttpTransport
{
    /**
     * Completes synchronously. The transport may close multipart streams after
     * consuming them; the Driver releases any stream still open on return. A
     * sink path and its resulting file remain owned by the consuming Host.
     *
     * @param array{
     *     method: string,
     *     url: string,
     *     headers?: array<string, string>,
     *     connectTimeout?: int,
     *     timeout?: int,
     *     retrySafe?: bool,
     *     sink?: string,
     *     multipart?: list<array{
     *         name: string,
     *         contents: mixed,
     *         filename?: string,
     *         headers?: array<string, string>
     *     }>
     * } $request
     * @return array{status: int, body: string}
     */
    public function request(array $request): array;
}
