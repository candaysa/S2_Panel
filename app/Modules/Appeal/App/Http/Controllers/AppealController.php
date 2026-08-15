<?php

namespace App\Modules\Appeal\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Appeal\App\Models\Appeal;
use App\Modules\Appeal\App\Services\AppealService;
use App\Models\User;
use App\Support\Api;
use App\Support\TicketAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Ban appeal endpoints (C9).
 *
 * Any authenticated player with an ACTIVE ban may file one PENDING appeal.
 * Players manage their own appeals; staff - whichever flags Settings >
 * Tickets names, see TicketAccess - see everything; deciding
 * (PENDING -> APPROVED/REJECTED) always requires admin.root because it is
 * the panel-side decision signal for unbanning, independent of that setting.
 */
class AppealController extends Controller
{
    public function __construct(private readonly AppealService $appeals)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $staff = TicketAccess::isStaff($user);

        $query = Appeal::query()
            ->when(! $staff, fn ($q) => $q->where('steamid', (int) $user->steam_id))
            ->latest('id');

        $perPage = min((int) $request->query('per_page', 25), 100);

        $appeals = $query->paginate($perPage);

        return Api::success($appeals->items(), [
            'pagination' => [
                'total' => $appeals->total(),
                'per_page' => $appeals->perPage(),
                'current_page' => $appeals->currentPage(),
                'last_page' => $appeals->lastPage(),
            ],
            'visible' => $staff ? 'all' : 'mine',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:1|max:4000',
            'ban_id' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $data = $validator->validated();

        try {
            $appeal = $this->appeals->create(
                Auth::user(),
                (string) $data['reason'],
                isset($data['ban_id']) ? (int) $data['ban_id'] : null
            );
        } catch (InvalidArgumentException $e) {
            return Api::error(Api::MSG_INVALID_INPUT, ['reason' => [$e->getMessage()]], 422);
        }

        return Api::success($appeal, ['created' => true]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $appeal = $this->findAppeal($id);

        if ($appeal === null) {
            return Api::notFound();
        }

        if (! $this->canManage(Auth::user(), $appeal)) {
            return Api::forbidden();
        }

        return Api::success($appeal);
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $appeal = $this->findAppeal($id);

        if ($appeal === null) {
            return Api::notFound();
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:APPROVED,REJECTED',
            'decision_note' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $data = $validator->validated();

        try {
            $appeal = $this->appeals->decide(
                $appeal,
                (string) $data['status'],
                $data['decision_note'] ?? null,
                Auth::user()
            );
        } catch (InvalidArgumentException $e) {
            return Api::error(Api::MSG_INVALID_INPUT, ['status' => [$e->getMessage()]], 422);
        }

        return Api::success($appeal, ['decided' => true]);
    }

    private function findAppeal(string $id): ?Appeal
    {
        if (! ctype_digit($id)) {
            return null;
        }

        return Appeal::query()->find((int) $id);
    }

    /**
     * Owner of the appeal, or any configured ticket-staff flag. Fail-closed.
     */
    private function canManage(User $user, Appeal $appeal): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        if ((int) $user->steam_id === (int) $appeal->steamid) {
            return true;
        }

        return TicketAccess::isStaff($user);
    }
}