<?php

declare(strict_types=1);

namespace App\Service\Production;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Resguarda archivos operativos fuera de public/. La ruta interna no procede
 * nunca del nombre indicado por el cliente y se valida antes de descargarse.
 */
final class ProductionFileStorage
{
    private const ARTWORK_MIME_TYPES = [
        'application/pdf',
        'application/postscript',
        'application/illustrator',
        'application/eps',
        'application/zip',
        'application/x-zip-compressed',
        'image/svg+xml',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/tiff',
    ];

    private const REFERENCE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(private readonly string $projectDir)
    {
    }

    public function storePublicRequest(UploadedFile $file): StoredProductionFile
    {
        return $this->storeUpload($file, 'requests', self::ARTWORK_MIME_TYPES, 20 * 1024 * 1024);
    }

    public function storePreparedArtwork(UploadedFile $file): StoredProductionFile
    {
        return $this->storeUpload($file, 'artwork', self::ARTWORK_MIME_TYPES, 100 * 1024 * 1024);
    }

    public function storeDesignReference(UploadedFile $file): StoredProductionFile
    {
        return $this->storeUpload($file, 'references', self::REFERENCE_MIME_TYPES, 20 * 1024 * 1024);
    }

    /**
     * Lee un archivo ya privado. El nombre original se conserva desde el
     * snapshot comercial, pues el nombre físico es deliberadamente aleatorio.
     */
    public function inspectPrivate(string $internalPath, string $originalName): StoredProductionFile
    {
        $absolutePath = $this->absolutePath($internalPath);

        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new \RuntimeException('El archivo original resguardado ya no está disponible.');
        }

        return $this->metadata($internalPath, $originalName, $absolutePath);
    }

    /** Copia de forma controlada un adjunto histórico que aún esté bajo public/. */
    public function importLegacyPublicRequest(string $publicRelativePath, string $originalName): StoredProductionFile
    {
        $publicRelativePath = str_replace('\\', '/', trim($publicRelativePath));
        if (
            !str_starts_with($publicRelativePath, 'uploads/quotations/')
            || str_contains($publicRelativePath, '..')
        ) {
            throw new \InvalidArgumentException('La referencia histórica del archivo no es válida.');
        }

        $source = $this->projectDir.'/public/'.$publicRelativePath;
        if (!is_file($source) || !is_readable($source)) {
            throw new \RuntimeException('El adjunto histórico no está disponible para su resguardo.');
        }

        return $this->copyExisting($source, $originalName, 'legacy', self::ARTWORK_MIME_TYPES, 20 * 1024 * 1024);
    }

    public function absolutePath(string $internalPath): string
    {
        $internalPath = str_replace('\\', '/', trim($internalPath));
        if (
            $internalPath === ''
            || str_starts_with($internalPath, '/')
            || str_contains($internalPath, '..')
            || preg_match('#^[a-zA-Z]:/#', $internalPath) === 1
        ) {
            throw new \InvalidArgumentException('La ruta privada del archivo no es válida.');
        }

        $absolutePath = $this->storageRoot().'/'.$internalPath;
        $root = realpath($this->storageRoot());
        $directory = realpath(dirname($absolutePath));
        if ($root === false || $directory === false || !str_starts_with($directory, $root)) {
            throw new \InvalidArgumentException('La ruta privada del archivo está fuera del almacenamiento autorizado.');
        }

        return $absolutePath;
    }

    /** Resuelve el origen congelado de una partida sin aceptar rutas arbitrarias. */
    public function absoluteClientSource(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if (!str_starts_with($path, 'uploads/quotations/')) {
            return $this->absolutePath($path);
        }
        if (str_contains($path, '..')) {
            throw new \InvalidArgumentException('La referencia histórica del archivo no es válida.');
        }
        $root = realpath($this->projectDir.'/public/uploads/quotations');
        $candidate = realpath($this->projectDir.'/public/'.$path);
        if ($root === false || $candidate === false || !str_starts_with($candidate, $root) || !is_file($candidate)) {
            throw new \RuntimeException('El adjunto histórico no está disponible.');
        }

        return $candidate;
    }

    private function storeUpload(UploadedFile $file, string $category, array $allowedMimes, int $maximumBytes): StoredProductionFile
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('No fue posible recibir el archivo adjunto.');
        }

        $source = $file->getPathname();
        $this->assertFile($source, $allowedMimes, $maximumBytes);

        $extension = $this->safeExtension($file->getClientOriginalName());
        $relativeDirectory = $category.'/'.gmdate('Y/m');
        $destinationDirectory = $this->storageRoot().'/'.$relativeDirectory;
        $this->ensureDirectory($destinationDirectory);
        $storedName = bin2hex(random_bytes(20)).($extension === '' ? '' : '.'.$extension);

        $file->move($destinationDirectory, $storedName);
        $relativePath = $relativeDirectory.'/'.$storedName;

        return $this->metadata($relativePath, $this->safeOriginalName($file->getClientOriginalName()), $destinationDirectory.'/'.$storedName);
    }

    private function copyExisting(string $source, string $originalName, string $category, array $allowedMimes, int $maximumBytes): StoredProductionFile
    {
        $this->assertFile($source, $allowedMimes, $maximumBytes);

        $extension = $this->safeExtension($originalName);
        $relativeDirectory = $category.'/'.gmdate('Y/m');
        $destinationDirectory = $this->storageRoot().'/'.$relativeDirectory;
        $this->ensureDirectory($destinationDirectory);
        $storedName = bin2hex(random_bytes(20)).($extension === '' ? '' : '.'.$extension);
        $destination = $destinationDirectory.'/'.$storedName;

        if (!copy($source, $destination)) {
            throw new \RuntimeException('No fue posible resguardar el adjunto histórico.');
        }

        return $this->metadata($relativeDirectory.'/'.$storedName, $this->safeOriginalName($originalName), $destination);
    }

    /** @param list<string> $allowedMimes */
    private function assertFile(string $path, array $allowedMimes, int $maximumBytes): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('El archivo no se puede leer.');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > $maximumBytes) {
            throw new \InvalidArgumentException(sprintf('El archivo debe medir entre 1 byte y %d MB.', (int) floor($maximumBytes / 1024 / 1024)));
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = strtolower((string) $finfo->file($path));
        if (!in_array($mimeType, $allowedMimes, true)) {
            throw new \InvalidArgumentException('El tipo real del archivo no está permitido para esta acción.');
        }
    }

    private function metadata(string $internalPath, string $originalName, string $absolutePath): StoredProductionFile
    {
        $size = filesize($absolutePath);
        $hash = hash_file('sha256', $absolutePath);
        $mimeType = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath));
        if ($size === false || $size < 1 || $hash === false || $mimeType === '') {
            throw new \RuntimeException('No fue posible obtener los metadatos del archivo.');
        }

        return new StoredProductionFile($internalPath, $this->safeOriginalName($originalName), $mimeType, $size, $hash);
    }

    private function storageRoot(): string
    {
        $root = $this->projectDir.'/var/storage/production';
        $this->ensureDirectory($root);

        return $root;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('No fue posible preparar el almacenamiento privado de producción.');
        }
    }

    private function safeOriginalName(string $name): string
    {
        $name = trim(str_replace(["\r", "\n", "\0"], '', $name));

        return mb_substr($name !== '' ? $name : 'archivo', 0, 255);
    }

    private function safeExtension(string $name): string
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,10}$/D', $extension) === 1 ? $extension : '';
    }
}
