<?php

namespace App\Twig\Components\Ui;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Button
{
    public string $variant = 'primary';

    public string $size = 'md';

    public string $type = 'button';

    public ?string $href = null;

    public bool $disabled = false;
}