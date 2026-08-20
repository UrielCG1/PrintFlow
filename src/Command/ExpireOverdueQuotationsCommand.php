<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Quotations\QuotationManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:quotations:expire-overdue',
    description: 'Marca como expiradas las cotizaciones emitidas o enviadas cuya vigencia ya concluyó.',
)]
final class ExpireOverdueQuotationsCommand extends Command
{
    public function __construct(
        private readonly QuotationManager $quotationManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $expiredCount = $this->quotationManager->expireOverdue();

        if ($expiredCount === 0) {
            $io->success('No hay cotizaciones vencidas pendientes de expirar.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%d %s %s expirado correctamente.',
            $expiredCount,
            $expiredCount === 1 ? 'cotización' : 'cotizaciones',
            $expiredCount === 1 ? 'se ha' : 'se han',
        ));

        return Command::SUCCESS;
    }
}
