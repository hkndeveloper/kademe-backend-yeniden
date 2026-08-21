<?php

namespace App\Services\PeriodClosure;

use App\Models\Period;

interface PeriodClosureChecker
{
    /**
     * @return array<int, array{code:string,count:int,message:string,metadata?:array}>
     */
    public function check(Period $period): array;
}
