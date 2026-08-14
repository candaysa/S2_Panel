<?php

namespace App\Modules\Appeal\App\Services;

use App\Modules\Appeal\App\Events\AppealCreated;
use App\Modules\Appeal\App\Events\AppealDecided;
use App\Modules\Appeal\App\Mail\AppealApproved;
use App\Modules\Appeal\App\Models\Appeal;
use App\Modules\Audit\App\Services\AuditService;
use App\Modules\Ban\App\Models\AdminBan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Ban appeal flows (C9). Panel-owned data – appeals live in the panel
 * database; every mutation is audit-logged and announced via events so the
 * Webhook module (C17) can react without touching this code.
 *
 * An appeal may only be filed while the player has an ACTIVE ban (checked
 * against the Swiftly admin_bans table, read-only) and only one PENDING
 * appeal may exist at a time. Deciding an appeal never removes the ban –
 * the panel cannot mutate plugin tables – it records the admin decision and
 * (when enabled) emails the template; lifting the ban stays with the admin.
 */
class AppealService
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * File a new appeal against an active ban.
     *
     * @throws InvalidArgumentException when there is no active ban or a
     *                                  PENDING appeal already exists.
     */
    public function create(User $applicant, string $reason, ?int $banId = null): Appeal
    {
        $steamid = (int) $applicant->steam_id;
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('reason_required');
        }

        if (! AdminBan::query()->where('steamid', $steamid)->active()->exists()) {
            throw new InvalidArgumentException('no_active_ban');
        }

        if (Appeal::query()
            ->where('steamid', $steamid)
            ->where('status', self::STATUS_PENDING)
            ->exists()) {
            throw new InvalidArgumentException('duplicate_pending_appeal');
        }

        $appeal = Appeal::query()->create([
            'steamid' => $steamid,
            'name' => $applicant->name,
            'ban_id' => $banId,
            'reason' => $reason,
            'status' => self::STATUS_PENDING,
        ]);

        $this->audit->log('appeal.created', 'appeal', (string) $appeal->id, [
            'steamid' => $steamid,
            'ban_id' => $appeal->ban_id,
        ]);

        Event::dispatch(new AppealCreated($appeal));

        return $appeal;
    }

    /**
     * Decide a PENDING appeal (APPROVED/REJECTED). Rows are locked for the
     * transaction so two admins cannot decide the same appeal concurrently.
     *
     * The AppealApproved mailer is only triggered when the module config
     * enables it (default off – templates exist, nothing is sent).
     *
     * @throws InvalidArgumentException when the appeal is not PENDING or the
     *                                  status is invalid.
     */
    public function decide(Appeal $appeal, string $status, ?string $note, User $decider): Appeal
    {
        if (! in_array($status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            throw new InvalidArgumentException('invalid_status');
        }

        return DB::transaction(function () use ($appeal, $status, $note, $decider): Appeal {
            $locked = Appeal::query()->lockForUpdate()->find($appeal->id);

            if ($locked === null || $locked->status !== self::STATUS_PENDING) {
                throw new InvalidArgumentException('already_decided');
            }

            $locked->update([
                'status' => $status,
                'decided_by' => (int) $decider->steam_id,
                'decision_note' => $note !== null ? trim($note) : null,
                'decided_at' => now(),
            ]);

            $this->audit->log('appeal.decided', 'appeal', (string) $locked->id, [
                'status' => $status,
                'decided_by' => (int) $decider->steam_id,
            ]);

            Event::dispatch(new AppealDecided($locked));

            if (config('modules.modules.appeal.mail_enabled', false)) {
                Mail::to($locked->name)->send(new AppealApproved($locked));
            }

            return $locked;
        });
    }
}