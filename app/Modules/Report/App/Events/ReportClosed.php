<?php

namespace App\Modules\Report\App\Events;

use App\Modules\Report\App\Models\Report;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReportClosed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Report $report)
    {
    }
}