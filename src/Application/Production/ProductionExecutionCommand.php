<?php

declare(strict_types=1);

namespace App\Application\Production;

/**
 * Contrato de una acción proveniente de una pantalla o tableta operativa.
 *
 * commandId identifica el gesto del usuario y permite responder de forma
 * idempotente. expectedOrderVersion evita aplicar una acción nueva sobre una
 * vista ya obsoleta de la orden.
 */
final readonly class ProductionExecutionCommand
{
    public string $commandId;

    public int $expectedOrderVersion;

    public ?string $note;

    public function __construct(string $commandId, int $expectedOrderVersion, ?string $note = null)
    {
        $commandId = trim($commandId);
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $commandId) !== 1) {
            throw new \InvalidArgumentException('El identificador de comando no tiene un formato válido.');
        }

        if ($expectedOrderVersion < 1) {
            throw new \InvalidArgumentException('La versión esperada de la orden debe ser positiva.');
        }

        $note = $note !== null ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }
        if ($note !== null && (mb_strlen($note) < 5 || mb_strlen($note) > 500)) {
            throw new \InvalidArgumentException('El comentario debe tener entre 5 y 500 caracteres.');
        }

        $this->commandId = $commandId;
        $this->expectedOrderVersion = $expectedOrderVersion;
        $this->note = $note;
    }
}
