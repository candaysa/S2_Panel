<?php

namespace App\Modules\Rank\App\Services;

use App\Models\User;
use App\Modules\Audit\App\Services\AuditService;
use App\Modules\Rank\App\Models\PlayerNote;
use App\Support\SteamId;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Staff notes on a player's profile (C5). Append/delete only - see
 * PlayerNote's migration for why there is no edit.
 */
class PlayerNoteService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * @return Collection<int, PlayerNote>
     */
    public function listFor(string $steamId): Collection
    {
        if (! SteamId::isValid($steamId)) {
            throw new InvalidArgumentException('invalid_steamid');
        }

        $steamId64 = (int) SteamId::parse($steamId)->steamId64();

        return PlayerNote::query()
            ->where('steamid', $steamId64)
            ->latest('id')
            ->get();
    }

    public function create(string $steamId, string $note, User $author): PlayerNote
    {
        if (! SteamId::isValid($steamId)) {
            throw new InvalidArgumentException('invalid_steamid');
        }

        $steamId64 = (int) SteamId::parse($steamId)->steamId64();

        $row = PlayerNote::query()->create([
            'steamid' => $steamId64,
            'note' => $note,
            'author_steamid' => (int) $author->steam_id,
            'author_name' => $author->name,
            'created_at' => now(),
        ]);

        $this->audit->log('player_note.created', 'player', (string) $steamId64, [
            'note_id' => $row->id,
        ]);

        return $row;
    }

    /**
     * Any admin.generic holder may delete any note (matching the gate on
     * reading them) - notes are a shared staff tool, not one author's
     * private property, and immunity/ownership rules for who wrote what
     * belong to the admin plugin, not this table.
     */
    public function delete(int $id): bool
    {
        $note = PlayerNote::query()->find($id);

        if ($note === null) {
            throw new InvalidArgumentException('note_not_found');
        }

        $steamId64 = $note->steamid;
        $deleted = (bool) $note->delete();

        if ($deleted) {
            $this->audit->log('player_note.deleted', 'player', (string) $steamId64, [
                'note_id' => $id,
            ]);
        }

        return $deleted;
    }
}
