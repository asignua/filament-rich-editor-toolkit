// Re-apply DOM state that Livewire's morph strips.
//
// Our modules (fullscreen, source view) mark the field wrapper `.fi-fo-rich-editor` with a class,
// an inline custom property, a `disabled` flag on toolbar buttons. Any Livewire round trip of the
// component that holds the editor (Save, `$wire.$refresh()`, an upload, a live field elsewhere)
// morphs that markup back to what the server rendered, and the marks vanish while our own state
// ("active") stays. Livewire reports every element it updated through the JS hook
// `morph.updated` ({ el, component }); `Livewire.hook()` returns the function that unregisters it.
//
// `onMorphed(win, callback)` subscribes to it and returns `off`. It is tolerant of a page without
// Livewire (plain pages, jsdom tests: nothing happens) and of Livewire that loads AFTER this
// module (the hook is attached on the document's `livewire:init` event, once). Call `off()` when
// the mode ends; it also cancels a still-pending `livewire:init` wait.
//
// `touches(root, el)` tells whether a morphed element concerns `root`: it is the wrapper itself,
// one of its ancestors (the wrapper's parent carries our min-height placeholder) or something
// inside it (a toolbar button whose `disabled` was reset).
//
// IMPORTANT: `window` is read ONLY from the argument, never at import time.

export const touches = (root, el) => Boolean(el) && (el === root || el.contains(root) || root.contains(el))

/**
 * @param {Window|undefined} win
 * @param {(payload: {el: Element}) => void} callback
 * @returns {() => void} off
 */
export const onMorphed = (win, callback) => {
    const doc = win?.document
    let unhook = null
    let cancelled = false

    const attach = () => {
        const livewire = win?.Livewire

        if (cancelled || unhook || typeof livewire?.hook !== 'function') {
            return false
        }

        const result = livewire.hook('morph.updated', callback)

        unhook = typeof result === 'function' ? result : () => {}

        return true
    }

    const onInit = () => {
        attach()
    }

    if (!attach()) {
        doc?.addEventListener('livewire:init', onInit, { once: true })
    }

    return () => {
        cancelled = true
        doc?.removeEventListener('livewire:init', onInit)
        unhook?.()
        unhook = null
    }
}
