<?php

declare(strict_types=1);

namespace App\Service\Quotations;

final class PdfBrandLogoProvider
{
    private ?string $dataUri = null;

    public function __construct(private readonly string $projectDir)
    {
    }

    public function dataUri(): string
    {
        if ($this->dataUri !== null) {
            return $this->dataUri;
        }

        $logoPath = $this->projectDir . '/assets/images/brand/logo.png';

        if (!is_file($logoPath) || !is_readable($logoPath)) {
            throw new \RuntimeException('No se encontró el logotipo institucional para generar el PDF.');
        }

        $logoContents = file_get_contents($logoPath);

        if ($logoContents === false) {
            throw new \RuntimeException('No se pudo leer el logotipo institucional para generar el PDF.');
        }

        return $this->dataUri = 'data:image/png;base64,' . base64_encode($logoContents);
    }
}
