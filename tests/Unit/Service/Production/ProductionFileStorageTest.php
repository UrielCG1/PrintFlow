<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Production;

use App\Service\Production\ProductionFileStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProductionFileStorageTest extends TestCase
{
    private string $projectDirectory;

    protected function setUp(): void
    {
        $this->projectDirectory = sys_get_temp_dir().'/printflow-production-storage-'.bin2hex(random_bytes(6));
        mkdir($this->projectDirectory, 0770, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDirectory);
    }

    public function testItStoresAReferenceOutsideThePublicDirectoryWithVerifiedMetadata(): void
    {
        $temporaryUpload = $this->projectDirectory.'/source.jpg';
        file_put_contents($temporaryUpload, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/Aaf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/Aaf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/Iaf/xAAVEQEBAAAAAAAAAAAAAAAAAAAAEf/aAAgBAwEBPyGn/8QAFREBAQAAAAAAAAAAAAAAAAAAABH/2gAIAQIBAT8hp//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8hH//Z'));
        $upload = new UploadedFile($temporaryUpload, 'captura final.jpg', 'image/jpeg', null, true);

        $storage = new ProductionFileStorage($this->projectDirectory);
        $stored = $storage->storeDesignReference($upload);

        self::assertStringStartsWith('references/', $stored->internalPath);
        self::assertSame('captura final.jpg', $stored->originalName);
        self::assertSame('image/jpeg', $stored->mimeType);
        self::assertSame(64, strlen($stored->sha256));
        self::assertFileExists($storage->absolutePath($stored->internalPath));
        self::assertFileDoesNotExist($this->projectDirectory.'/public/'.$stored->internalPath);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($directory);
    }
}
