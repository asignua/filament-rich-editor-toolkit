// What paste-images.js and paste-clean.js must agree on, although they are two separate bundles
// (esbuild gives each its own copy of any shared module, so state here would NOT be shared): the
// set of one-time tokens of the pastes currently being recovered, kept on `globalThis` under a
// `Symbol.for` key.
//
// WHY. paste-images.js turns the pictures of a Word / Google Docs paste into `data:` images;
// Filament uploads them and replaces `src` with the temporary upload URL (+ a `data-id`); only
// then does paste-clean.js see the images, and its image triage keeps an image only when it points
// at THIS site. A temporary URL on S3 (presigned), or on a different scheme/host than
// `location.origin` (APP_URL behind a proxy, a separate asset domain), is "foreign" to that test, so
// the pictures that were just uploaded would be removed again.
//
// THE TOKEN. paste-images.js stamps every image it recovered with `data-asignua-recovered=<token>`
// and registers the token here for the duration of the paste. Filament changes `src` and `data-id`
// of the SAME element and leaves other attributes alone, so the stamp arrives intact. A token is
// random and unguessable, so HTML that came from a web page cannot carry a valid one; a forged
// `data-id` alone (which is all a hostile page can supply) keeps no foreign image.

export const RECOVERED_ATTRIBUTE = 'data-asignua-recovered'

const KEY = Symbol.for('asignua-rte.recovered-tokens')

/** @returns {Set<string>} */
export const recoveredTokens = () => {
    globalThis[KEY] ??= new Set()

    return globalThis[KEY]
}

/**
 * A fresh unguessable token (crypto when there is one).
 */
export const newToken = () => {
    const crypto = globalThis.crypto

    if (crypto && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID()
    }

    if (crypto && typeof crypto.getRandomValues === 'function') {
        return Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('')
    }

    return `${Math.random().toString(36).slice(2)}${Date.now().toString(36)}${Math.random().toString(36).slice(2)}`
}
