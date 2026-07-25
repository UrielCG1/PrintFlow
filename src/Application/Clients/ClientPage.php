<?php

namespace App\Application\Clients;

use App\Entity\Clients\Client;

final readonly class ClientPage
{
    /**
     * @param list<Client> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $currentPage,
        public int $pageCount,
    ) {
    }
}