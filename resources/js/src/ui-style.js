// The toolkit's UI stylesheet (fullscreen + counter), loaded by the module that needs it.
//
// The sticky-toolbar CSS is linked by a PANEL plugin, so it exists only where that plugin is
// registered. Fullscreen and the counter are RichContentPlugins that must work without it
// (also in a plain Livewire form outside any panel), so their module links the stylesheet
// itself, once, from the URL the PHP side hands it in its own query string (`css=`).
//
// `window`/`document` are touched only when a function runs, never at the top level.

/**
 * Link `href` once. Returns true when a <link> was added.
 *
 * Only http(s) is accepted. A page that already links the same path (with another ?v=) is left
 * alone.
 */
export const ensureStylesheet = (href) => {
    const doc = globalThis.document

    if (typeof href !== 'string' || href === '' || !doc || !doc.head) {
        return false
    }

    let url

    try {
        url = new URL(href, doc.baseURI)
    } catch {
        return false
    }

    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        return false
    }

    for (const link of Array.from(doc.querySelectorAll('link[rel="stylesheet"]'))) {
        try {
            if (new URL(link.href, doc.baseURI).pathname === url.pathname) {
                return false
            }
        } catch {
            // A <link> with a broken href cannot be the one we want.
        }
    }

    const link = doc.createElement('link')

    link.rel = 'stylesheet'
    link.href = url.href
    doc.head.appendChild(link)

    return true
}

/**
 * The query string of a module URL, as URLSearchParams (empty on any failure).
 *
 * @param {string} moduleUrl `import.meta.url`, passed in by the caller so that this file never
 *   reads `import.meta` at the top level
 */
export const paramsOf = (moduleUrl) => {
    try {
        return new URL(moduleUrl).searchParams
    } catch {
        return new URLSearchParams()
    }
}
