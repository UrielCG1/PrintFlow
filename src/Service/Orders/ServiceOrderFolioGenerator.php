<?php

declare(strict_types=1);

namespace App\Service\Orders;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class ServiceOrderFolioGenerator
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function next(\DateTimeImmutable $createdAt): string
    {
        $folioYear = (int) $createdAt
            ->setTimezone(new \DateTimeZone('America/Mexico_City'))
            ->format('Y');

        /*
         * LAST_INSERT_ID() es local a la conexión actual de MySQL. Mantiene el
         * consecutivo atómico incluso cuando dos órdenes se crean a la vez.
         */
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO service_order_folio_sequences (folio_year, last_number)
                VALUES (:folioYear, LAST_INSERT_ID(1))
                ON DUPLICATE KEY UPDATE
                    last_number = LAST_INSERT_ID(last_number + 1)
                SQL,
            ['folioYear' => $folioYear],
            ['folioYear' => ParameterType::INTEGER],
        );

        $number = (int) $this->connection->lastInsertId();

        if ($number < 1) {
            throw new \RuntimeException('No fue posible obtener el consecutivo de la orden de servicio.');
        }

        return sprintf('OS-%d-%06d', $folioYear, $number);
    }
}
