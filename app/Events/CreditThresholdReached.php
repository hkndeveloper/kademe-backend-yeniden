<?php

namespace App\Events;

use App\Models\Participant;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Katılımcının kredisi belirlenen eşiğin altına düştüğünde tetiklenir.
 * İleride SMS gateway, e-posta veya sistem bildirimi listener'ları bu event'e bağlanabilir.
 */
class CreditThresholdReached
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Participant $participant,
        public readonly int $threshold
    ) {
    }
}
