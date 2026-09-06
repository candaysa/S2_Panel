<?php

namespace App\Modules\Report\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Report\App\Models\Report;
use App\Modules\Report\App\Services\ReportService;
use App\Models\User;
use App\Support\Api;
use App\Support\TicketAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Report/ticket endpoints (C8).
 *
 * Visibility model: every authenticated user opens tickets (player report or
 * admin application) and reads/replies to their own; staff - whichever admin
 * group Settings > Tickets names for that ticket's category, see
 * TicketAccess - see and manage everything in it. Closing always requires
 * admin.root regardless of that setting.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $perPage = min((int) $request->query('per_page', 25), 100);
        $ticketType = $request->query('ticket_type');
        $ticketType = in_array($ticketType, ['report', 'admin_application'], true) ? (string) $ticketType : null;

        // No specific category requested (the panel's own UI always sends
        // one): staff if configured for either, since "all tickets" then
        // spans both.
        $staff = $ticketType !== null
            ? TicketAccess::isStaff($user, $ticketType)
            : (TicketAccess::isStaff($user, 'report') || TicketAccess::isStaff($user, 'admin_application'));

        $tickets = $staff
            ? $this->reports->all($request->query('status'), $perPage, $ticketType)
            : $this->reports->myTickets($user, $request->query('status'), $perPage, $ticketType);

        return Api::success($tickets->items(), [
            'pagination' => [
                'total' => $tickets->total(),
                'per_page' => $tickets->perPage(),
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
            ],
            'visible' => $staff ? 'all' : 'mine',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ticket_type' => 'sometimes|string|in:report,admin_application',
            'report_reason' => 'required|string|min:1|max:4000',
            'target_steamid' => 'nullable|string|max:32',
            'target_name' => 'nullable|string|max:64',
            'server_id' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $data = $validator->validated();

        if (isset($data['target_steamid']) && ! is_numeric($data['target_steamid'])) {
            return Api::error(Api::MSG_INVALID_INPUT, ['target_steamid' => ['invalid_steamid_format']], 422);
        }

        try {
            $report = $this->reports->create(Auth::user(), $data);
        } catch (InvalidArgumentException $e) {
            return Api::error(Api::MSG_INVALID_INPUT, ['ticket_type' => [$e->getMessage()]], 422);
        }

        return Api::success($report->load('replies'), ['created' => true]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $report = $this->findTicket($id);

        if ($report === null) {
            return Api::notFound();
        }

        if (! $this->canManage(Auth::user(), $report)) {
            return Api::forbidden();
        }

        return Api::success($report->load('replies'));
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $report = $this->findTicket($id);

        if ($report === null) {
            return Api::notFound();
        }

        if (! $this->canManage(Auth::user(), $report)) {
            return Api::forbidden();
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|min:1|max:4000',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        try {
            $reply = $this->reports->reply($report, Auth::user(), (string) $validator->validated()['message']);
        } catch (InvalidArgumentException $e) {
            return Api::error(Api::MSG_INVALID_INPUT, ['message' => [$e->getMessage()]], 422);
        }

        return Api::success($reply);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $report = $this->findTicket($id);

        if ($report === null) {
            return Api::notFound();
        }

        $validator = Validator::make($request->all(), [
            'resolution' => 'required|string|in:APPROVED,REJECTED',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        try {
            $report = $this->reports->close($report, (string) $validator->validated()['resolution']);
        } catch (InvalidArgumentException $e) {
            return Api::error(Api::MSG_INVALID_INPUT, ['resolution' => [$e->getMessage()]], 422);
        }

        return Api::success($report->load('replies'), ['closed' => true]);
    }

    public function destroy(string $id): JsonResponse
    {
        $report = $this->findTicket($id);

        if ($report === null) {
            return Api::notFound();
        }

        $this->reports->destroy($report);

        return Api::success(['deleted' => true]);
    }

    private function findTicket(string $id): ?Report
    {
        if (! ctype_digit($id)) {
            return null;
        }

        return Report::query()->find((int) $id);
    }

    /**
     * Owner, reporter, or a member of this ticket's configured staff group.
     * Fail-closed.
     */
    private function canManage(User $user, Report $report): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        if ((int) $user->steam_id === (int) $report->reporter_steamid) {
            return true;
        }

        return TicketAccess::isStaff($user, $report->ticket_type);
    }
}