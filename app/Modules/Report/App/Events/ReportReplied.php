<?php

namespace App\Modules\Report\App\Events;

use App\Modules\Report\App\Models\Report;
use App\Modules\Report\App\Models\ReportReply;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReportReplied
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Report $report, public readonly ReportReply $reply)
    {
    }
}