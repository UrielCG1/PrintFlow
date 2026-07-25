<?php

namespace App\Twig\Components\Ui;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Modal
{
    public string $id = '';

    public string $title = '';

    public string $size = 'md';

    public bool $scrollable = false;

    public bool $staticBackdrop = false;

    public bool $keyboard = true;
}