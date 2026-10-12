// Fullscreen for one rich editor: the field wrapper (`.fi-fo-rich-editor`) becomes a fixed
// full-viewport overlay (CSS in the toolkit's UI stylesheet), toggled by the toolbar button
// `fullscreen` through the TipTap command `asignuaToggleFullscreen`; Esc leaves it.
//
// Why a class on the wrapper and not the top layer (Popover API / <dialog>): an element in the
// top layer paints ABOVE every ordinary z-index, including Filament's own modals - the link,
// embed or image-by-URL dialog opened from the fullscreen toolbar would open BEHIND the editor.
// A fixed overlay at z-index 35 sits above the topbar and sidebar (30) and below the modals (40).
// The price: `position: fixed` follows an ancestor that has `transform`, `filter` or `contain`;
// in that case the overlay fills that ancestor. An `overflow` on an ancestor (section, tab,
// repeater item - the sticky CSS even switches them to `overflow: clip`) does not clip a fixed
// element.
//
// What entering does: adds the class, locks the page scroll (`html.asignua-rte-fullscreen-lock`),
// keeps the layout from collapsing (the wrapper leaves the flow, so its parent gets the old
// height as `min-height`), focuses the editor. Leaving undoes all of it and restores the page
// scroll position. Esc leaves unless something on top wants it: a Filament modal that does not
// contain the editor, an open toolbar dropdown, or a handler that already called
// preventDefault(). When it does leave, it stops the event there, so that an editor inside a modal
// does not close the modal with the same key press.
//
// A Livewire round trip of the component that holds the editor morphs the wrapper back to the
// server markup and strips what we added (the class, the inline `--asignua-rte-fullscreen-z`, the
// parent's `min-height`) while the page lock and the button stay pressed. While active, the
// factory therefore listens to Livewire's `morph.updated` hook (livewire-morph.js) and puts those
// three back whenever the wrapper, its parent or something inside was morphed. The morph itself is
// never touched.
//
// Configuration comes from the query string of this module's URL: `css` (the UI stylesheet,
// linked once) and `z` (an integer, sets --asignua-rte-fullscreen-z on the wrapper).
//
// IMPORTANT: `window` and `import.meta` are read ONLY inside the default-export factory.

import { ensureStylesheet, paramsOf } from './ui-style.js'
import { onMorphed, touches } from './livewire-morph.js'

export const ACTIVE_CLASS = 'asignua-rte-fullscreen'
export const LOCK_CLASS = 'asignua-rte-fullscreen-lock'

/**
 * @param {Element} root the field wrapper
 * @param {{z?: number|null, win?: Window, doc?: Document, onEscape?: () => void}} options
 *   `onEscape` runs after Esc left fullscreen: the toolbar learns about it only from an editor
 *   transaction, which a key handler outside the editor does not produce.
 */
export const createFullscreen = (root, options = {}) => {
    const win = options.win || globalThis.window
    const doc = options.doc || globalThis.document
    let active = false
    let scroll = null
    let parentMinHeight = null
    let listener = null
    let offMorph = null
    let placeholder = null

    const otherModalIsOpen = () =>
        Array.from(doc.querySelectorAll('.fi-modal.fi-modal-open')).some((modal) => !modal.contains(root))

    const dropdownIsOpen = () => root.querySelector('[aria-expanded="true"]') !== null

    // ProseMirror calls preventDefault() on EVERY Esc pressed inside a focused editor (its
    // captureKeyDown treats Esc like Enter), so `defaultPrevented` alone would make Esc dead while
    // typing. A prevented Esc from our own editor is still ours, unless a mention / merge-tag
    // suggestion list was open: that list takes the Esc and removes itself before we see the
    // event, so whether it was open is recorded in the capture phase, ahead of ProseMirror.
    let suggestionWasOpen = false

    const suggestionIsOpen = () => doc.querySelector('body > .fi-dropdown-panel.fi-dropdown-list') !== null

    const onKeydownCapture = (event) => {
        if (event.key === 'Escape') {
            suggestionWasOpen = suggestionIsOpen()
        }
    }

    const preventedByOwnEditor = (event) =>
        !suggestionWasOpen &&
        event.target instanceof win.Element &&
        root.contains(event.target) &&
        event.target.closest('.ProseMirror') !== null

    const onKeydown = (event) => {
        if (event.key !== 'Escape' || (event.defaultPrevented && !preventedByOwnEditor(event))) {
            return
        }

        if (otherModalIsOpen() || dropdownIsOpen()) {
            return
        }

        event.preventDefault()

        // This Esc is ours: it leaves fullscreen and nothing else. A field inside a Filament modal
        // would otherwise close the modal with it (the modal listens for Escape on `window`, one step
        // after `document`, where this handler sits), losing the dialog's unsaved form.
        event.stopPropagation()
        api.exit()
        options.onEscape?.()
    }

    const applyMarks = () => {
        if (Number.isInteger(options.z)) {
            root.style.setProperty('--asignua-rte-fullscreen-z', String(options.z))
        }

        root.classList.add(ACTIVE_CLASS)

        const parent = root.parentElement

        if (parent && placeholder) {
            parent.style.minHeight = placeholder
        }
    }

    // Livewire morphed something around the editor: put our marks back (idempotent).
    const onMorph = ({ el }) => {
        if (active && touches(root, el)) {
            applyMarks()
            doc.documentElement.classList.add(LOCK_CLASS)
        }
    }

    const api = {
        isActive: () => active,
        enter() {
            if (active) {
                return false
            }

            active = true
            scroll = { x: win.scrollX, y: win.scrollY }

            // The wrapper is about to leave the flow; without this the page below it jumps up.
            const parent = root.parentElement

            if (parent) {
                parentMinHeight = { value: parent.style.minHeight }
                placeholder = `${root.getBoundingClientRect().height}px`
                parent.style.minHeight = placeholder
            }

            if (Number.isInteger(options.z)) {
                root.style.setProperty('--asignua-rte-fullscreen-z', String(options.z))
            }

            root.classList.add(ACTIVE_CLASS)
            doc.documentElement.classList.add(LOCK_CLASS)
            offMorph = onMorphed(win, onMorph)
            listener = onKeydown
            doc.addEventListener('keydown', onKeydownCapture, true)
            doc.addEventListener('keydown', listener)

            return true
        },
        exit() {
            if (!active) {
                return false
            }

            active = false
            offMorph?.()
            offMorph = null
            placeholder = null
            root.classList.remove(ACTIVE_CLASS)
            doc.documentElement.classList.remove(LOCK_CLASS)
            doc.removeEventListener('keydown', onKeydownCapture, true)
            doc.removeEventListener('keydown', listener)
            listener = null

            const parent = root.parentElement

            if (parent && parentMinHeight) {
                parent.style.minHeight = parentMinHeight.value
                parentMinHeight = null
            }

            if (scroll && (win.scrollX !== scroll.x || win.scrollY !== scroll.y)) {
                win.scrollTo(scroll.x, scroll.y)
            }

            scroll = null

            return true
        },
        toggle() {
            return active ? api.exit() : api.enter()
        },
        destroy() {
            api.exit()
        },
    }

    return api
}

export default () => {
    const { Extension } = window.FilamentRichEditor.tiptap.core
    const params = paramsOf(import.meta.url)
    const z = /^\d+$/.test(params.get('z') || '') ? Number(params.get('z')) : null
    let fullscreen = null

    return Extension.create({
        name: 'asignuaFullscreen',
        addStorage() {
            return { active: false }
        },
        onCreate() {
            ensureStylesheet(params.get('css'))

            const root = this.editor.view.dom.closest('.fi-fo-rich-editor')

            if (root) {
                fullscreen = createFullscreen(root, {
                    z,
                    onEscape: () => {
                        this.storage.active = false

                        // Same marker transaction as the command: re-evaluates the button's pressed state.
                        if (!this.editor.isDestroyed) {
                            this.editor.view.dispatch(this.editor.state.tr.setMeta('asignuaFullscreen', false))
                        }
                    },
                })
            }
        },
        onDestroy() {
            fullscreen?.destroy()
            fullscreen = null
        },
        addCommands() {
            const extension = this

            return {
                asignuaToggleFullscreen:
                    () =>
                    ({ tr, dispatch, view }) => {
                        // `can()` runs a command without `dispatch` to ask whether it would work.
                        if (!dispatch || !fullscreen) {
                            return Boolean(fullscreen)
                        }

                        fullscreen.toggle()
                        extension.storage.active = fullscreen.isActive()

                        // An empty transaction with a marker: the editor's 'transaction' event is
                        // what makes the toolbar button re-evaluate its active (pressed) state.
                        tr.setMeta('asignuaFullscreen', extension.storage.active)
                        view.focus()

                        return true
                    },
            }
        },
    })
}
