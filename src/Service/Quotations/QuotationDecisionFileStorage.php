<?php

declare(strict_types=1);

namespace App\Service\Quotations;

use App\Entity\Quotations\Quotation;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

final class QuotationDecisionFileStorage
{
    public function __construct(private readonly SluggerInterface $slugger, private readonly string $projectDir) {}

    /** @return array<string, int|string> */
    public function storePurchaseOrder(Quotation $quotation, UploadedFile $file, string $orderNumber): array
    {
        $client = (string) ($quotation->getClientSnapshot()['business_name'] ?? 'cliente');
        return $this->store($quotation, $file, $this->slugger->slug($client.'-'.$orderNumber)->lower()->toString(), 'purchase-order');
    }

    /** @return array<string, int|string> */
    public function storeResponseScreenshot(Quotation $quotation, UploadedFile $file): array
    {
        $client = (string) ($quotation->getClientSnapshot()['business_name'] ?? 'cliente');
        return $this->store($quotation, $file, $this->slugger->slug($client.'-captura-respuesta')->lower()->toString(), 'response-screenshot');
    }

    /** @param array<string, int|string>|null $metadata */
    public function remove(?array $metadata): void
    {
        $relativePath = $metadata['path'] ?? null;
        if (!is_string($relativePath)) return;
        $path = $this->absolutePath($relativePath);
        if (is_file($path)) @unlink($path);
    }

    /** @param array<string, int|string> $metadata */
    public function resolve(array $metadata): string
    {
        $relativePath = $metadata['path'] ?? null;
        if (!is_string($relativePath) || $relativePath === '') throw new \InvalidArgumentException('La referencia del archivo no es válida.');
        $path = $this->absolutePath($relativePath);
        if (!is_file($path)) throw new \RuntimeException('El archivo solicitado no existe en el almacenamiento.');
        return $path;
    }

    /** @return array<string, int|string> */
    private function store(Quotation $quotation, UploadedFile $file, string $baseName, string $kind): array
    {
        $directory = $this->storageRoot().'/'.$quotation->getId();
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new \RuntimeException('No fue posible crear la carpeta de evidencias de la cotización.');
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $storedName = sprintf('%s.%s', $baseName, $extension);
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $size = $file->getSize() ?: 0;
        $file->move($directory, $storedName);
        return ['kind'=>$kind, 'path'=>$quotation->getId().'/'.$storedName, 'stored_name'=>$storedName, 'original_name'=>$originalName, 'mime_type'=>$mimeType, 'size'=>$size];
    }

    private function storageRoot(): string { return $this->projectDir.'/var/storage/quotation-decisions'; }

    private function absolutePath(string $relativePath): string
    {
        if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) throw new \InvalidArgumentException('La ruta del archivo no es válida.');
        return $this->storageRoot().'/'.$relativePath;
    }
}
