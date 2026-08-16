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
 * Colour per admin flag, shared by the Admins and Groups pages so a flag
 * reads the same wherever it appears.
 *
 * The eight CS2_Admin flags below keep their hand-picked colours - admin.root
 * gets red because it is the one flag that grants everything else, it should
 * never blend in. Anything else - the official swiftlys2-plugins/admins
 * plugin's own permission strings, or CS2_Admin installs that go beyond the
 * fixed eight (real data on this panel includes `admin.*`, `@css/*`,
 * `css/generic`) - used to fall through to one flat grey, which read as
 * "uncoloured" next to the known set. Those now get a colour picked
 * deterministically from the flag's own text (a simple string hash into a
 * fixed palette), so every flag is distinguishable and a given flag always
 * lands on the same colour across reloads and pages.
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
const FLAG_PALETTE = [
    'bg-teal-500/10 text-teal-400 ring-teal-500/20',
    'bg-lime-500/10 text-lime-400 ring-lime-500/20',
    'bg-cyan-500/10 text-cyan-400 ring-cyan-500/20',
    'bg-indigo-500/10 text-indigo-400 ring-indigo-500/20',
    'bg-rose-500/10 text-rose-400 ring-rose-500/20',
    'bg-yellow-500/10 text-yellow-400 ring-yellow-500/20',
    'bg-blue-500/10 text-blue-400 ring-blue-500/20',
    'bg-purple-500/10 text-purple-400 ring-purple-500/20',
];

const hashString = (value) => {
    let hash = 0;
    for (let i = 0; i < value.length; i++) {
        hash = (hash * 31 + value.charCodeAt(i)) | 0;
    }
    return Math.abs(hash);
};

window.flagColorClass = (flag) => FLAG_COLORS[flag] ?? FLAG_PALETTE[hashString(String(flag)) % FLAG_PALETTE.length];

window.Alpine = Alpine;
Alpine.start();
