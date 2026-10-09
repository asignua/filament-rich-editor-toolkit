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
// Configuration comes from the query string of this module's URL: `css` (the UI stylesheet,
// linked once) and `z` (an integer, sets --asignua-rte-fullscreen-z on the wrapper).
//
// IMPORTANT: `window` and `import.meta` are read ONLY inside the default-export factory.

import { ensureStylesheet, paramsOf } from './ui-style.js'

export const ACTIVE_CLASS = 'asignua-rte-fullscreen'
export const LOCK_CLASS = 'asignua-rte-fullscreen-lock'

/**
 * @param {Element} root the field wrapper
 * @param {{z?: number|null, win?: Window, doc?: Document}} options
 */
export const createFullscreen = (root, options = {}) => {
    const win = options.win || globalThis.window
    const doc = options.doc || globalThis.document
    let active = false
    let scroll = null
    let parentMinHeight = null
    let listener = null

    const otherModalIsOpen = () =>
        Array.from(doc.querySelectorAll('.fi-modal.fi-modal-open')).some((modal) => !modal.contains(root))

    const dropdownIsOpen = () => root.querySelector('[aria-expanded="true"]') !== null

    const onKeydown = (event) => {
        if (event.key !== 'Escape' || event.defaultPrevented) {
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
                parent.style.minHeight = `${root.getBoundingClientRect().height}px`
            }

            if (Number.isInteger(options.z)) {
                root.style.setProperty('--asignua-rte-fullscreen-z', String(options.z))
            }

            root.classList.add(ACTIVE_CLASS)
            doc.documentElement.classList.add(LOCK_CLASS)
            listener = onKeydown
            doc.addEventListener('keydown', listener)

            return true
        },
        exit() {
            if (!active) {
                return false
            }

            active = false
            root.classList.remove(ACTIVE_CLASS)
            doc.documentElement.classList.remove(LOCK_CLASS)
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
                fullscreen = createFullscreen(root, { z })
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
