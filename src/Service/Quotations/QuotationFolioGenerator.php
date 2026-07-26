<?php

declare(strict_types=1);

namespace App\Service\Quotations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class QuotationFolioGenerator
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function next(\DateTimeImmutable $issuedAt): string
    {
        $mexicoCity = new \DateTimeZone('America/Mexico_City');

        $folioYear = (int) $issuedAt
            ->setTimezone($mexicoCity)
            ->format('Y');

        /*
         * LAST_INSERT_ID() pertenece a la conexión MySQL actual.
         * La instrucción es atómica, por lo que dos emisiones simultáneas
         * no pueden obtener el mismo consecutivo.
         */
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO quotation_folio_sequences (
                    folio_year,
                    last_number
                ) VALUES (
                    :folioYear,
                    LAST_INSERT_ID(1)
                )
                ON DUPLICATE KEY UPDATE
                    last_number = LAST_INSERT_ID(last_number + 1)
                SQL,
            ['folioYear' => $folioYear],
            ['folioYear' => ParameterType::INTEGER],
        );

        $number = (int) $this->connection->lastInsertId();

        if ($number < 1) {
            throw new \RuntimeException(
                'No fue posible obtener el consecutivo de la cotización.',
            );
        }

        return sprintf('COT-%d-%06d', $folioYear, $number);
    }
}