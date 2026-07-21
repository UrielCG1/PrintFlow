<?php

namespace App\Twig\Components\Ui;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class PageHeader
{
    public string $title = '';

    public ?string $eyebrow = null;

    public ?string $description = null;
}