<?php

declare(strict_types=1);

namespace App\Service\Production;

/** Metadatos inmutables de un archivo resguardado fuera del directorio público. */
final readonly class StoredProductionFile
{
    public function __construct(
        public string $internalPath,
        public string $originalName,
        public string $mimeType,
        public int $byteSize,
        public string $sha256,
    ) {
    }
}
