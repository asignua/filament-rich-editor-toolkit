// Reads the settings the PHP plugin put into the query string of this module's own URL
// (`.../custom-attributes.js?v=ab12cd&type=paragraph&attr=data-track`).
//
// Why the URL and not a global: the module is loaded by Filament with a dynamic `import()`,
// so there is no place to hand it an options object, and a `window.*` global would have to be
// printed by a render hook that every host must remember to register. The URL travels with
// the module and doubles as the cache key, so a changed allow-list is never served stale.
//
// `import.meta` is read only when this function is CALLED (from inside a factory), never at
// module top level, so the modules still import under Node for the test suite.

/**
 * @param {string|undefined} moduleUrl `import.meta.url` of the calling module
 * @param {string} name query parameter, repeated for lists
 * @returns {string[]}
 */
export const readList = (moduleUrl, name) => {
    try {
        return new URL(moduleUrl).searchParams.getAll(name).filter((value) => value !== '')
    } catch {
        return []
    }
}
