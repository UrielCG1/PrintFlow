<?php
declare(strict_types=1);
namespace App\Enum\Production;
enum CalculationMethod: string
{
    case FIXED='FIXED'; case AREA='AREA'; case LENGTH='LENGTH'; case PERIMETER='PERIMETER'; case VOLUME='VOLUME';
    case PERCENTAGE='PERCENTAGE'; case AREA_YIELD='AREA_YIELD'; case PERIMETER_SPACING='PERIMETER_SPACING';
    case SHEET_LAYOUT='SHEET_LAYOUT'; case ROLL_LAYOUT='ROLL_LAYOUT';
}
