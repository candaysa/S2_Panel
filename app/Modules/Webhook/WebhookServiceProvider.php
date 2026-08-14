<?php

namespace App\Modules\Webhook;

use App\Modules\Appeal\App\Events\AppealCreated;
use App\Modules\Appeal\App\Events\AppealDecided;
use App\Modules\Auth\Events\UserRegistered;
use App\Modules\Health\App\Events\HealthAlert;
use App\Modules\Rcon\App\Events\RconActionPerformed;
use App\Modules\Report\App\Events\ReportClosed;
use App\Modules\Report\App\Events\ReportCreated;
use App\Modules\Report\App\Events\ReportReplied;
use App\Modules\Webhook\App\Services\WebhookService;
use App\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class WebhookServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'webhook';
    }

    protected function registerModule(): void
    {
        //
    }

    protected function bootModule(): void
    {
        $this->registerEventListeners();
    }

    /**
     * Fan every supported domain event out to the subscribed webhooks.
     * Listeners are cheap: dispatch() only queues jobs for webhooks that
     * selected the event, and the queue is asynchronous.
     */
    private function registerEventListeners(): void
    {
        $service = app(WebhookService::class);

        Event::listen(UserRegistered::class, static function (UserRegistered $event) use ($service): void {
            $service->dispatch('user.registered', [
                'title' => 'New user registered',
                'description' => 'A new account signed in to the panel for the first time.',
                'fields' => [
                    ['name' => 'Name', 'value' => (string) $event->user->name],
                    ['name' => 'SteamID', 'value' => (string) $event->user->steam_id],
                ],
            ]);
        });

        Event::listen(RconActionPerformed::class, static function (RconActionPerformed $event) use ($service): void {
            $service->dispatch('admin.action', [
                'title' => 'Admin action performed',
                'description' => 'A player action was executed via RCON.',
                'fields' => [
                    ['name' => 'Action', 'value' => strtoupper($event->action)],
                    ['name' => 'Target', 'value' => $event->target],
                    ['name' => 'Detail', 'value' => $event->detail ?? '–'],
                    ['name' => 'Server ID', 'value' => (string) $event->serverId],
                    ['name' => 'Result', 'value' => $event->ok ? 'OK' : 'FAILED'],
                ],
            ]);
        });

        Event::listen(ReportCreated::class, static function (ReportCreated $event) use ($service): void {
            $report = $event->report;
            $service->dispatch('report.created', [
                'title' => "Report #{$report->id}",
                'description' => 'A new report ticket was opened.',
                'fields' => [
                    ['name' => 'Type', 'value' => (string) $report->ticket_type],
                    ['name' => 'Reporter', 'value' => (string) ($report->reporter_name ?? $report->reporter_steamid)],
                    ['name' => 'Target', 'value' => (string) ($report->target_name ?? $report->target_steamid)],
                    ['name' => 'Reason', 'value' => (string) $report->report_reason],
                ],
            ]);
        });

        Event::listen(ReportReplied::class, static function (ReportReplied $event) use ($service): void {
            $report = $event->report;
            $service->dispatch('report.replied', [
                'title' => "Report #{$report->id} – new reply",
                'description' => 'A reply was added to a report ticket.',
                'fields' => [
                    ['name' => 'Author', 'value' => (string) ($event->reply->author_name ?? $event->reply->author_steamid)],
                    ['name' => 'Message', 'value' => (string) $event->reply->message],
                ],
            ]);
        });

        Event::listen(ReportClosed::class, static function (ReportClosed $event) use ($service): void {
            $report = $event->report;
            $service->dispatch('report.closed', [
                'title' => "Report #{$report->id} closed",
                'description' => 'A report ticket was closed.',
                'fields' => [
                    ['name' => 'Status', 'value' => (string) $report->status],
                    ['name' => 'Resolution', 'value' => (string) ($report->resolution ?? '–')],
                ],
            ]);
        });

        Event::listen(AppealCreated::class, static function (AppealCreated $event) use ($service): void {
            $appeal = $event->appeal;
            $service->dispatch('appeal.created', [
                'title' => "Appeal #{$appeal->id}",
                'description' => 'A new ban appeal was submitted.',
                'fields' => [
                    ['name' => 'Name', 'value' => (string) $appeal->name],
                    ['name' => 'SteamID', 'value' => (string) $appeal->steamid],
                    ['name' => 'Reason', 'value' => (string) $appeal->reason],
                ],
            ]);
        });

        Event::listen(AppealDecided::class, static function (AppealDecided $event) use ($service): void {
            $appeal = $event->appeal;
            $service->dispatch('appeal.decided', [
                'title' => "Appeal #{$appeal->id} decided",
                'description' => 'An admin decided a ban appeal.',
                'fields' => [
                    ['name' => 'Name', 'value' => (string) $appeal->name],
                    ['name' => 'SteamID', 'value' => (string) $appeal->steamid],
                    ['name' => 'Decision', 'value' => (string) $appeal->status],
                    ['name' => 'Note', 'value' => (string) ($appeal->decision_note ?? '–')],
                ],
            ]);
        });

        Event::listen(HealthAlert::class, static function (HealthAlert $event) use ($service): void {
            $service->dispatch('health.alert', [
                'title' => 'Health alert',
                'description' => 'A monitored component went down.',
                'fields' => [
                    ['name' => 'Component', 'value' => $event->component],
                    ['name' => 'Status', 'value' => strtoupper($event->status)],
                    ['name' => 'Message', 'value' => $event->message ?? '–'],
                ],
            ]);
        });
    }
}