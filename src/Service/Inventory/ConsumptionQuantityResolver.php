<?php
declare(strict_types=1);
namespace App\Service\Inventory;
use App\Enum\Inventory\QuantitySource;

final class ConsumptionQuantityResolver
{
    /** @return array{actual_quantity:?string,posted_quantity:string,quantity_source:QuantitySource} */
    public function resolve(string $plannedQuantity, ?string $actualQuantity): array
    {
        $planned=$this->normalize($plannedQuantity);
        if ($actualQuantity===null || trim($actualQuantity)==='') return ['actual_quantity'=>null,'posted_quantity'=>$planned,'quantity_source'=>QuantitySource::ESTIMATED];
        $actual=$this->normalize($actualQuantity);
        return ['actual_quantity'=>$actual,'posted_quantity'=>$actual,'quantity_source'=>QuantitySource::MEASURED];
    }
    private function normalize(string $value):string
    {
        $value=trim(str_replace(',','.',$value));
        if(preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D',$value)!==1)throw new \InvalidArgumentException('La cantidad debe ser no negativa y tener máximo seis decimales.');
        [$integer,$decimal]=array_pad(explode('.',$value,2),2,'');
        return $integer.'.'.str_pad($decimal,6,'0');
    }
}
