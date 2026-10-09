// Character / word counter under the editor, counted on PLAIN TEXT.
//
// Why not Filament's `maxLength()`: earlier 5.x releases validated the raw HTML string (tags,
// attributes and entities included), so `<p>Hello</p>` was 12 "characters"
// (filamentphp/filament#17028); newer ones count tiptap-php's getText(). Neither has a counter
// in the browser or a word limit. Here the editor document is flattened to its text.
//
// THE DEFINITION (mirrored in PHP by Support\PlainText - a shared fixture test pins both):
//   - Every text block (paragraph, heading, list item paragraph, table cell paragraph, code
//     block ...) yields its text; a hard break inside it is "\n". Blocks are joined with "\n".
//     Empty blocks are dropped.
//   - Atoms add nothing: images, embeds, custom blocks, mentions, merge tags.
//   - characters = Unicode code points of that text with every "\n" removed (so paragraph and
//     line breaks are free; spaces count).
//   - words = runs of non-whitespace; whitespace is the explicit list below (the same code
//     points in PHP), "\n" included - JS `\s` and PCRE `\s` disagree, so neither is used.
//
// Configuration comes from the query string of this module's URL (see CharacterCountPlugin):
// `max`, `max-words`, `words` (show the word count), `warn` (0..1), the label templates
// `t-chars`, `t-chars-max`, `t-words`, `t-words-max`, `t-over-chars`, `t-over-words` with
// `:count` / `:max` placeholders, and `css`.
//
// IMPORTANT: `window` and `import.meta` are read ONLY inside the default-export factory.

import { ensureStylesheet, paramsOf } from './ui-style.js'

const WHITESPACE = ' \\t\\n\\r\\f\\u000b\\u00a0\\u1680\\u2000-\\u200a\\u2028\\u2029\\u202f\\u205f\\u3000\\ufeff'
const WORD = new RegExp(`[^${WHITESPACE}]+`, 'gu')

/**
 * The plain-text blocks of a ProseMirror document, empty blocks dropped.
 *
 * @param {object} doc ProseMirror Node
 * @returns {string[]}
 */
export const textBlocks = (doc) => {
    const blocks = []

    doc.descendants((node) => {
        if (!node.isTextblock) {
            return true
        }

        let text = ''

        node.forEach((child) => {
            if (child.isText) {
                text += child.text
            } else if (child.type.name === 'hardBreak') {
                text += '\n'
            }
        })

        if (text !== '') {
            blocks.push(text)
        }

        return false
    })

    return blocks
}

/** @param {object} doc ProseMirror Node */
export const plainTextOf = (doc) => textBlocks(doc).join('\n')

/** @param {string} text the output of plainTextOf() */
export const countText = (text) => ({
    characters: Array.from(text.split('\n').join('')).length,
    words: (text.match(WORD) || []).length,
})

/**
 * "near" from `warn` (a share of the limit), "over" above the limit, else "ok". Words and
 * characters are judged separately and the worse state wins.
 */
export const stateOf = ({ characters, words }, { max = null, maxWords = null, warn = 0.9 }) => {
    const rank = { ok: 0, near: 1, over: 2 }
    let state = 'ok'

    for (const [value, limit] of [
        [characters, max],
        [words, maxWords],
    ]) {
        if (limit === null || limit <= 0) {
            continue
        }

        const next = value > limit ? 'over' : value >= limit * warn ? 'near' : 'ok'

        if (rank[next] > rank[state]) {
            state = next
        }
    }

    return state
}

const fill = (template, values) =>
    String(template).replace(/:(count|max)\b/g, (match, name) => String(values[name] ?? match))

const intParam = (params, name) => {
    const value = params.get(name)

    return value !== null && /^\d+$/.test(value) ? Number(value) : null
}

/**
 * Settings from the module's query string.
 *
 * @param {URLSearchParams} params
 */
export const settingsFrom = (params) => {
    const warn = Number(params.get('warn'))

    return {
        max: intParam(params, 'max'),
        maxWords: intParam(params, 'max-words'),
        showWords: params.get('words') === '1',
        warn: warn > 0 && warn <= 1 ? warn : 0.9,
        labels: {
            chars: params.get('t-chars') || ':count',
            charsMax: params.get('t-chars-max') || ':count / :max',
            words: params.get('t-words') || ':count',
            wordsMax: params.get('t-words-max') || ':count / :max',
            overChars: params.get('t-over-chars') || '',
            overWords: params.get('t-over-words') || '',
        },
        css: params.get('css'),
    }
}

/**
 * The counter element's text for the current numbers.
 */
export const labelFor = (counts, settings) => {
    const { labels } = settings
    const parts = [
        settings.max === null
            ? fill(labels.chars, { count: counts.characters })
            : fill(labels.charsMax, { count: counts.characters, max: settings.max }),
    ]

    if (settings.showWords || settings.maxWords !== null) {
        parts.push(
            settings.maxWords === null
                ? fill(labels.words, { count: counts.words })
                : fill(labels.wordsMax, { count: counts.words, max: settings.maxWords }),
        )
    }

    return parts.join(' · ')
}

/**
 * Build the counter and attach it to `host` (the editor's root element). Returns a controller
 * with update(doc) and destroy().
 *
 * The visible text is NOT a live region (it changes on every key). A separate visually hidden
 * `role="status"` element speaks only when the state flips to "over", saying which limit.
 */
export const createCounter = (host, settings, doc = globalThis.document) => {
    const element = doc.createElement('div')
    const text = doc.createElement('span')
    const status = doc.createElement('span')

    element.className = 'asignua-rte-count'
    element.setAttribute('data-state', 'ok')
    text.className = 'asignua-rte-count-text'
    status.className = 'asignua-rte-sr'
    status.setAttribute('role', 'status')
    element.append(text, status)
    host.appendChild(element)

    let previous = 'ok'

    return {
        element,
        update(counts) {
            const state = stateOf(counts, settings)

            text.textContent = labelFor(counts, settings)
            element.setAttribute('data-state', state)

            if (state === 'over' && previous !== 'over') {
                const overChars = settings.max !== null && counts.characters > settings.max
                const message = overChars ? settings.labels.overChars : settings.labels.overWords

                status.textContent = message
            } else if (state !== 'over') {
                status.textContent = ''
            }

            previous = state
        },
        destroy() {
            element.remove()
        },
    }
}

export default () => {
    const { Extension } = window.FilamentRichEditor.tiptap.core
    const settings = settingsFrom(paramsOf(import.meta.url))
    let counter = null
    let frame = null

    const refresh = (editor) => {
        if (!counter || !editor || editor.isDestroyed) {
            return
        }

        counter.update(countText(plainTextOf(editor.state.doc)))
    }

    // One recount per frame however many transactions arrive (a paste, a collaborative burst).
    const schedule = (editor) => {
        if (frame !== null) {
            return
        }

        const run = () => {
            frame = null
            refresh(editor)
        }

        frame = typeof globalThis.requestAnimationFrame === 'function' ? globalThis.requestAnimationFrame(run) : (run(), null)
    }

    return Extension.create({
        name: 'asignuaCharacterCount',
        onCreate() {
            ensureStylesheet(settings.css)

            // The x-data root holds the toolbar and the editor; the counter goes last.
            const main = this.editor.view.dom.closest('.fi-fo-rich-editor-main')
            const host = main && main.parentElement

            if (!host) {
                return
            }

            counter = createCounter(host, settings)
            refresh(this.editor)
        },
        onTransaction({ transaction }) {
            if (transaction.docChanged) {
                schedule(this.editor)
            }
        },
        onDestroy() {
            if (frame !== null && typeof globalThis.cancelAnimationFrame === 'function') {
                globalThis.cancelAnimationFrame(frame)
            }

            frame = null
            counter?.destroy()
            counter = null
        },
    })
}
