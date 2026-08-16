<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Conserva metadatos físicos administrados por migraciones que no forman parte
 * del estado de una entidad: comentarios SQL, defaults, columnas generadas y
 * nombres de índices. Las columnas y restricciones de negocio siguen siendo
 * comparadas normalmente; este listener solo normaliza activos conocidos.
 */
final class DatabaseManagedSchemaListener
{
    /** @var array<string,list<string>> */
    private const GENERATED_COLUMNS = [
        'material_variants' => ['default_material_key'],
        'supplier_material_variants' => ['preferred_variant_key'],
    ];

    /** @var array<string,list<string>> */
    private const DATABASE_MANAGED_INDEXES = [
        'material_variants' => ['uniq_material_variants_default'],
        'supplier_material_variants' => ['uniq_preferred_supplier_variant'],
    ];

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $generatedSchema = $event->getSchema();
        $schemaManager = $event->getEntityManager()->getConnection()->createSchemaManager();

        foreach ($generatedSchema->getTables() as $generatedTable) {
            $tableName = $generatedTable->getObjectName()->toString();

            if (!$schemaManager->tablesExist([$tableName])) {
                continue;
            }

            $actualTable = $schemaManager->introspectTable($tableName);
            $this->preserveCommentsAndDefaults($generatedTable, $actualTable);
            $this->preserveGeneratedColumns($tableName, $generatedTable, $actualTable);
            $this->normalizeIndexes($tableName, $generatedTable, $actualTable);
        }
    }

    private function preserveCommentsAndDefaults(Table $generated, Table $actual): void
    {
        $generated->setComment($actual->getComment() ?? '');

        foreach ($generated->getColumns() as $column) {
            $columnName = $column->getObjectName()->toString();
            if (!$actual->hasColumn($columnName)) {
                continue;
            }

            $actualColumn = $actual->getColumn($columnName);
            $column->setComment($actualColumn->getComment());
            $column->setDefault($actualColumn->getDefault());
        }
    }

    private function preserveGeneratedColumns(string $tableName, Table $generated, Table $actual): void
    {
        foreach (self::GENERATED_COLUMNS[$tableName] ?? [] as $columnName) {
            if ($generated->hasColumn($columnName) || !$actual->hasColumn($columnName)) {
                continue;
            }

            $actualColumn = $actual->getColumn($columnName);
            $rawOptions = $actualColumn->toArray();
            $options = array_intersect_key($rawOptions, array_flip([
                'default',
                'notnull',
                'length',
                'precision',
                'scale',
                'fixed',
                'unsigned',
                'autoincrement',
                'columnDefinition',
                'comment',
                'values',
            ]));

            $platformOptions = array_intersect_key($rawOptions, array_flip([
                'default_constraint_name',
                'enumType',
                'jsonb',
                'version',
            ]));
            if ($platformOptions !== []) {
                $options['platformOptions'] = $platformOptions;
            }
            $generated->addColumn(
                $columnName,
                Type::lookupName($actualColumn->getType()),
                $options,
            );
        }
    }

    private function normalizeIndexes(string $tableName, Table $generated, Table $actual): void
    {
        foreach ($generated->getIndexes() as $generatedIndex) {
            if ($this->isPrimary($generatedIndex)) {
                continue;
            }

            $actualIndex = $this->equivalentIndex($generatedIndex, $actual);
            $actualName = $actualIndex?->getObjectName()->toString();
            $generatedName = $generatedIndex->getObjectName()->toString();
            if ($actualName !== null && $actualName !== $generatedName) {
                $generated->renameIndex($generatedName, $actualName);
            }
        }

        foreach (self::DATABASE_MANAGED_INDEXES[$tableName] ?? [] as $indexName) {
            if (!$actual->hasIndex($indexName) || $generated->hasIndex($indexName)) {
                continue;
            }

            $index = $actual->getIndex($indexName);
            if ($index->getType() === IndexType::UNIQUE) {
                $generated->addUniqueIndex($this->columnNames($index), $indexName);
            } else {
                $generated->addIndex($this->columnNames($index), $indexName);
            }
        }
    }

    private function equivalentIndex(Index $expected, Table $actual): ?Index
    {
        foreach ($actual->getIndexes() as $candidate) {
            if ($this->isPrimary($candidate)) {
                continue;
            }

            if ($candidate->getType() === $expected->getType()
                && array_map('strtolower', $this->columnNames($candidate)) === array_map('strtolower', $this->columnNames($expected))
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function columnNames(Index $index): array
    {
        return array_map(
            static fn ($column): string => $column->getColumnName()->toString(),
            $index->getIndexedColumns(),
        );
    }

    private function isPrimary(Index $index): bool
    {
        return strtolower($index->getObjectName()->toString()) === 'primary';
    }
}
