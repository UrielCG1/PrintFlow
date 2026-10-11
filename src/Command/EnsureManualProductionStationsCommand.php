<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Equipment\EquipmentData;
use App\Application\Equipment\EquipmentManager;
use App\Entity\Equipment\Equipment;
use App\Entity\Operations\Operation;
use App\Entity\Users\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:equipment:ensure-manual-stations', description: 'Registra los puestos manuales de Diseño y Enrollado sin duplicar equipos ni inventar rendimientos.')]
final class EnsureManualProductionStationsCommand extends Command
{
    private const STATIONS = [
        ['EQ-PRE-MESA-01', 'Mesa de preprensa', 'PRE-DISENO'],
        ['EQ-ACA-ESTACION-01', 'Estación de acabados', 'ACA-ENROLLADO'],
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly EquipmentManager $equipmentManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Usuario administrador responsable del alta.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Valida las operaciones y muestra el resultado sin guardar.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $actor = $this->entityManager->getRepository(User::class)->findOneBy(['username' => (string) $input->getOption('actor')]);
        if (!$actor instanceof User || !$actor->isActive() || $actor->getDeletedAt() !== null || !in_array('ROLE_ADMIN', $actor->getRoles(), true)) {
            $io->error('Indica con --actor un usuario administrador activo.');
            return Command::FAILURE;
        }
        $pending = [];
        foreach (self::STATIONS as [$code, $name, $operationCode]) {
            $operation = $this->entityManager->getRepository(Operation::class)->findOneBy(['code' => $operationCode]);
            if (!$operation instanceof Operation || !$operation->isActive() || !$operation->getOperationArea()->isActive()) {
                $io->error('Falta una operación y área activas para '.$operationCode.'. No se registró ningún puesto.');
                return Command::FAILURE;
            }
            $existing = $this->entityManager->getRepository(Equipment::class)->findOneBy(['code' => $code]);
            if ($existing instanceof Equipment) {
                if ($existing->getPrimaryOperation()->getId() !== $operation->getId()) {
                    $io->error('El código '.$code.' ya corresponde a otra operación. Revisa el catálogo.');
                    return Command::FAILURE;
                }
                $io->writeln($code.' ya existe: '.$existing->getName());
                continue;
            }
            $data = new EquipmentData();
            $data->code = $code;
            $data->name = $name;
            $data->primaryOperation = $operation;
            $data->technology = 'Trabajo manual';
            $data->technicalCapacity = 'Duración estimada manualmente por operación; rendimiento pendiente de medir.';
            $data->observations = 'Puesto de trabajo manual para '.$operation->getName().'. La agenda requiere una estimación manual por partida. Sin rendimiento numérico validado.';
            $pending[] = $data;
        }
        if ($input->getOption('dry-run')) {
            foreach ($pending as $data) { $io->writeln('Por registrar: '.$data->code.' — '.$data->name.' — '.$data->primaryOperation->getCode()); }
            return Command::SUCCESS;
        }
        $this->entityManager->wrapInTransaction(function () use ($pending, $actor, $io): void {
            foreach ($pending as $data) {
                $this->equipmentManager->create($data, $actor);
                $io->writeln('Registrado: '.$data->code.' — '.$data->name);
            }
        });
        $io->success('Puestos manuales registrados o previamente existentes. No se asignó rendimiento numérico.');
        return Command::SUCCESS;
    }
}
