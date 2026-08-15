<?php

namespace App\Modules\Report\App\Services;

use App\Modules\Audit\App\Services\AuditService;
use App\Modules\Report\App\Events\ReportClosed;
use App\Modules\Report\App\Events\ReportCreated;
use App\Modules\Report\App\Events\ReportReplied;
use App\Modules\Report\App\Models\Report;
use App\Modules\Report\App\Models\ReportReply;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * Report/ticket flows (C8). Panel-owned data – tickets live in the panel
 * database, every mutation is audit-logged and announced via events so the
 * Webhook module (C17) can react without touching this code.
 */
class ReportService
{
    public const TYPE_REPORT = 'report';

    public const TYPE_APPLICATION = 'admin_application';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const RESOLUTION_APPROVED = 'APPROVED';

    public const RESOLUTION_REJECTED = 'REJECTED';

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * Open a new ticket (either a player report or an admin application).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $reporter, array $data): Report
    {
        $ticketType = $data['ticket_type'] ?? self::TYPE_REPORT;

        if (! in_array($ticketType, [self::TYPE_REPORT, self::TYPE_APPLICATION], true)) {
            throw new InvalidArgumentException('invalid_ticket_type');
        }

        $reason = trim((string) ($data['report_reason'] ?? ''));

        if ($reason === '') {
            throw new InvalidArgumentException('report_reason_required');
        }

        $report = Report::query()->create([
            'ticket_type' => $ticketType,
            'status' => self::STATUS_OPEN,
            'reporter_steamid' => (int) $reporter->steam_id,
            'reporter_name' => $reporter->name,
            'target_steamid' => isset($data['target_steamid']) ? (int) $data['target_steamid'] : null,
            'target_name' => $data['target_name'] ?? null,
            'report_reason' => $reason,
            'server_id' => $data['server_id'] ?? null,
        ]);

        $this->audit->log('report.created', 'report', (string) $report->id, [
            'ticket_type' => $ticketType,
            'target_steamid' => $report->target_steamid,
        ]);

        Event::dispatch(new ReportCreated($report));

        return $report;
    }

    /**
     * Append a reply to an open ticket.
     */
    public function reply(Report $report, User $author, string $message): ReportReply
    {
        $message = trim($message);

        if ($message === '') {
            throw new InvalidArgumentException('message_required');
        }

        $reply = ReportReply::query()->create([
            'report_id' => $report->id,
            'author_steamid' => (int) $author->steam_id,
            'author_name' => $author->name,
            'message' => $message,
            'created_at' => now(),
        ]);

        $this->audit->log('report.replied', 'report', (string) $report->id, [
            'reply_id' => $reply->id,
        ]);

        Event::dispatch(new ReportReplied($report, $reply));

        return $reply;
    }

    /**
     * Close a ticket and record its resolution.
     */
    public function close(Report $report, string $resolution): Report
    {
        if (! in_array($resolution, [self::RESOLUTION_APPROVED, self::RESOLUTION_REJECTED], true)) {
            throw new InvalidArgumentException('invalid_resolution');
        }

        $report->update([
            'status' => self::STATUS_CLOSED,
            'resolution' => $resolution,
        ]);

        $this->audit->log('report.closed', 'report', (string) $report->id, [
            'resolution' => $resolution,
        ]);

        Event::dispatch(new ReportClosed($report));

        return $report;
    }

    /**
     * Remove a ticket (and its replies, cascade).
     */
    public function destroy(Report $report): bool
    {
        $id = $report->id;

        $deleted = (bool) $report->delete();

        if ($deleted) {
            $this->audit->log('report.deleted', 'report', (string) $id);
        }

        return $deleted;
    }

    /**
     * Tickets a user is involved with (reporter or author of a reply).
     */
    public function myTickets(User $user, ?string $status = null, int $perPage = 25, ?string $ticketType = null): LengthAwarePaginator
    {
        $reporter = (int) $user->steam_id;

        return Report::query()
            ->where('reporter_steamid', $reporter)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($ticketType !== null, fn ($q) => $q->where('ticket_type', $ticketType))
            ->with('replies')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * All tickets, newest first (staff view).
     */
    public function all(?string $status = null, int $perPage = 25, ?string $ticketType = null): LengthAwarePaginator
    {
        return Report::query()
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($ticketType !== null, fn ($q) => $q->where('ticket_type', $ticketType))
            ->with('replies')
            ->latest('id')
            ->paginate($perPage);
    }
}