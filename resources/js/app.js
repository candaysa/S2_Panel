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

window.Alpine = Alpine;
Alpine.start();
