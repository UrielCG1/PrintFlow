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
    name: 'app:quotations:expire',
    description: 'Marca como expiradas las cotizaciones emitidas cuya vigencia ya concluyó.',
)]
final class ExpireOverdueQuotationsCommand extends Command
{
    public function __construct(private readonly QuotationManager $quotationManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expiredCount = $this->quotationManager->expireOverdue();
        $io = new SymfonyStyle($input, $output);

        $io->success(sprintf(
            '%d %s marcada%s como expirada%s.',
            $expiredCount,
            $expiredCount === 1 ? 'cotización' : 'cotizaciones',
            $expiredCount === 1 ? '' : 's',
            $expiredCount === 1 ? '' : 's',
        ));

        return Command::SUCCESS;
    }
}
