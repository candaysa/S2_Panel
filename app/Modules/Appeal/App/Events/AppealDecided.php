<?php

namespace App\Modules\Appeal\App\Events;

use App\Modules\Appeal\App\Models\Appeal;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppealDecided
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Appeal $appeal)
    {
    }
}