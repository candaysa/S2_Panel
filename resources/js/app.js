import Alpine from 'alpinejs';

/**
 * Turn an API failure into something worth showing a person.
 *
 * Every endpoint answers with { message, errors: { field: [reason, ...] } }
 * (see App\Support\Api). Pages were showing only `message`, which is a
 * stable machine identifier like "invalid_input" or "validation_failed" -
 * so a missing RCON password surfaced as "invalid_input" and a malformed
 * Steam URL as a generic "Something went wrong", with the real reason
 * sitting unread in `errors`.
 *
 * Reasons are looked up in window.apiMessages (populated per page from the
 * translation files) and fall back to the raw key, which is still far more
 * use than the generic message.
 *
 * @param {Response} response
 * @param {object} body    already-parsed JSON body, if the caller has it
 * @param {string} fallback
 */
window.apiError = (response, body, fallback) => {
    const dict = window.apiMessages ?? {};
    const reasons = Object.values(body?.errors ?? {}).flat();

    if (reasons.length > 0) {
        return reasons.map((r) => dict[r] ?? r).join(' ');
    }

    if (body?.message) {
        return dict[body.message] ?? body.message;
    }

    return fallback ?? 'Request failed';
};

/**
 * Fixed colour per Swiftly admin flag, shared by the Admins and Groups
 * pages so a flag reads the same wherever it appears.
 *
 * Hardcoded rather than derived: these eight flags are specific to the
 * CS2_Admin plugin this panel targets, not a general concept the panel
 * could compute a colour for. admin.root gets red because it is the one
 * flag that grants everything else - it should never blend in.
 */
const FLAG_COLORS = {
    'admin.root': 'bg-red-500/10 text-red-400 ring-red-500/20',
    'admin.generic': 'bg-sky-500/10 text-sky-400 ring-sky-500/20',
    'admin.ban': 'bg-orange-500/10 text-orange-400 ring-orange-500/20',
    'admin.mute': 'bg-amber-500/10 text-amber-400 ring-amber-500/20',
    'admin.kick': 'bg-pink-500/10 text-pink-400 ring-pink-500/20',
    'admin.cheats': 'bg-fuchsia-500/10 text-fuchsia-400 ring-fuchsia-500/20',
    'admin.rcon': 'bg-violet-500/10 text-violet-400 ring-violet-500/20',
    'admin.vip': 'bg-emerald-500/10 text-emerald-400 ring-emerald-500/20',
};
const FLAG_FALLBACK = 'bg-surface-raised text-ink-muted ring-line';

window.flagColorClass = (flag) => FLAG_COLORS[flag] ?? FLAG_FALLBACK;

window.Alpine = Alpine;
Alpine.start();
