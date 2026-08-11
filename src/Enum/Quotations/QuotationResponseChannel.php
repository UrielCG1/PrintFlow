<?php

declare(strict_types=1);

namespace App\Enum\Quotations;

enum QuotationResponseChannel: string
{
    case EMAIL = 'EMAIL';
    case WHATSAPP = 'WHATSAPP';
    case PHONE = 'PHONE';
    case IN_PERSON = 'IN_PERSON';

    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'Correo electrónico',
            self::WHATSAPP => 'WhatsApp',
            self::PHONE => 'Teléfono',
            self::IN_PERSON => 'Presencial',
        };
    }
}
