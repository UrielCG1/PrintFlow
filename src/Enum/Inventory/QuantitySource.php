<?php
declare(strict_types=1);
namespace App\Enum\Inventory;
enum QuantitySource: string { case ESTIMATED='ESTIMATED'; case MEASURED='MEASURED'; case DERIVED='DERIVED'; case MACHINE='MACHINE'; }
