<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Plugins;

use Asignua\RichEditorToolkit\Assets;
use Asignua\RichEditorToolkit\Support\RemoteImageException;
use Asignua\RichEditorToolkit\Support\RemoteImageFetcher;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Tiptap\Core\Extension;

/**
 * Cleans a paste from Word / Google Docs at the moment of Ctrl+V: the structure stays, while
 * colours, font sizes, backgrounds and every class/id/style they carry disappear.
 *
 * Plus the toolbar button `cleanFormat` ("Clear formatting", {@see self::getEditorTools()}):
 * the same cleanup on the SELECTED fragment of content that is already there, keeping what
 * should survive — headings, lists, links (relative, `#anchor` and `tel:` too; only script
 * schemes go), custom blocks, embeds, images (also ones inserted by URL) and code blocks. With no selection
 * the button is a deliberate no-op. There is no dialog, so {@see self::getEditorActions()} is
 * empty. All the work is in `resources/js/src/paste-clean.js`: the paste goes through the
 * `transformPastedHTML` hook, the button through the TipTap command `asignuaCleanFormat`.
 *
 * Not to be confused with Filament's stock `clearFormatting`: that one runs
 * `clearNodes().unsetAllMarks()` and wipes headings, lists and bold too — the opposite of
 * "keep the structure, drop the junk".
 *
 * NOTE: there is NO server half. Unlike {@see CustomAttributesPlugin} this plugin must NOT be
 * added to the front-end renderer: the cleanup happens exactly once, at paste time, and the
 * database already holds clean HTML. A second pass at render time would strip the class/style
 * that CustomAttributesPlugin exists to keep, on every page.
 *
 * The two are in direct tension — one preserves attributes, the other strips them. What keeps
 * them apart is a single thing: the `data-pm-slice` cutoff inside the JS module, which leaves a
 * paste from INSIDE an editor alone.
 *
 * The button must also be listed in the editor's toolbar:
 * `->toolbarButtons([[..., 'cleanFormat']])`. An unknown button name makes Filament throw.
 *
 * Strict mode ({@see self::strict()}, config `paste_clean.strict`): on top of the cleanup the
 * paste is reduced to what the field's toolbar offers. Filament loads the whole TipTap schema
 * whatever `toolbarButtons()` says, so a field with only a Bold button still accepts a pasted
 * table, heading or link (filamentphp/filament discussions #20484, #20048). In strict mode no
 * `table` button turns tables into paragraphs, no heading button turns headings into
 * paragraphs (a missing level moves to the nearest offered one, on a tie the deeper), no list
 * button turns items into paragraphs, and a mark without its button (bold, italic, underline,
 * strike, subscript, superscript, code, highlight, link) is unwrapped to its text. It also
 * applies to a paste from inside an editor, which the cleanup itself leaves alone: the toolbar
 * is the field's rule and two editors on a page may differ.
 *
 * HOW THE BROWSER KNOWS THE TOOLBAR: a plugin cannot see the field, so the module reads the
 * rendered toolbar: each stock button carries the TipTap name it toggles in its Alpine class
 * binding (`isActive('bold', [])`), which is independent of the translated label. A field whose
 * toolbar cannot be read is not restricted ("cannot tell" never means "strip everything"). The
 * details are in `resources/js/src/paste-strict.js`.
 *
 * Images ({@see self::uploadImages()}, config `paste_clean.upload_images`, off by default): by
 * default a picture pasted from Word or Google Docs is removed, because what the clipboard carries
 * is a `file:///` path or a URL the browser cannot read. With the option on, the pictures that can
 * be recovered are handed to Filament's own "paste an image" upload, and only the rest is removed
 * (and counted for the editor). Word's bytes come from the clipboard's RTF flavour, in the browser;
 * Google Docs' pictures are fetched on the SERVER ({@see self::getEditorActions()},
 * {@see RemoteImageFetcher}). The mechanics are in `resources/js/src/paste-images.js`.
 */
class PasteCleanPlugin implements RichContentPlugin
{
    /** @var list<string>|null */
    protected ?array $keepLinkPrefixes = null;

    protected ?bool $strict = null;

    protected ?bool $uploadImages = null;

    /** @var list<string>|null */
    protected ?array $remoteImageHosts = null;

    protected bool|Closure|null $remoteImageAuthorization = null;

    /** The editor action the browser mounts to have a remote picture fetched. */
    public const string FETCH_ACTION = 'asignuaFetchRemoteImage';

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Link href prefixes the "Clear formatting" button keeps whatever their scheme. The button
     * already keeps relative links (e.g. `/internal-link/`), so this is needed only for a
     * prefix it would otherwise refuse. Defaults to the `paste_clean.keep_link_prefixes`
     * config, which defaults to none.
     *
     * @param list<string> $prefixes
     */
    public function keepLinkPrefixes(array $prefixes): static
    {
        $this->keepLinkPrefixes = $prefixes;

        return $this;
    }

    /**
     * Reduce a paste to what the field's toolbar offers (see the class docblock). Defaults to
     * the `paste_clean.strict` config, which defaults to off.
     */
    public function strict(bool $strict = true): static
    {
        $this->strict = $strict;

        return $this;
    }

    public function isStrict(): bool
    {
        return $this->strict ?? (bool) config('rich-editor-toolkit.paste_clean.strict', false);
    }

    /**
     * Upload the pictures of a Word / Google Docs paste instead of removing them (see the class
     * docblock). Only works on a field with file attachments (Filament's own upload is what stores
     * the file) and honours that field's accepted types and maximum size. Defaults to the
     * `paste_clean.upload_images` config, which defaults to off.
     */
    public function uploadImages(bool $upload = true): static
    {
        $this->uploadImages = $upload;

        return $this;
    }

    public function isUploadingImages(): bool
    {
        return $this->uploadImages ?? (bool) config('rich-editor-toolkit.paste_clean.upload_images', false);
    }

    /**
     * Hosts whose pictures the SERVER may download for the editor (Google Docs serves its images
     * from `lh*.googleusercontent.com`): `*.example.com` = any subdomain, otherwise an exact name.
     * Defaults to `paste_clean.remote_image_hosts`. An empty list turns remote pictures off and
     * leaves Word's.
     *
     * @param list<string> $hosts
     */
    public function remoteImageHosts(array $hosts): static
    {
        $this->remoteImageHosts = $hosts;

        return $this;
    }

    /**
     * Who may ask the server to download a picture. The action is a server-side request whose URL
     * comes from the browser, so it is never open by default: with nothing set it needs a LOGGED-IN
     * user (`auth()->check()`, the default of the `paste_clean.remote_image_guests` config = false).
     *
     * Pass `true` / `false`, or a closure `fn (RichEditor $component): bool` (the policy of the
     * record being edited, a role check, ...). A refusal answers `['error' => 'unauthorized']`. The
     * field's own rules (it must take attachments and not be disabled) apply on top.
     */
    public function authorizeRemoteImages(bool|Closure $authorization = true): static
    {
        $this->remoteImageAuthorization = $authorization;

        return $this;
    }

    public function canFetchRemoteImages(RichEditor $component): bool
    {
        $authorization = $this->remoteImageAuthorization;

        if ($authorization instanceof Closure) {
            return (bool) $authorization($component);
        }

        if (is_bool($authorization)) {
            return $authorization;
        }

        return auth()->check() || (bool) config('rich-editor-toolkit.paste_clean.remote_image_guests', false);
    }

    /**
     * @return list<string>
     */
    public function getRemoteImageHosts(): array
    {
        $hosts = $this->remoteImageHosts ?? (array) config('rich-editor-toolkit.paste_clean.remote_image_hosts', []);

        return array_values(array_unique(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            array_filter($hosts, static fn (mixed $host): bool => is_string($host) && trim($host) !== ''),
        )));
    }

    /**
     * @return list<string>
     */
    public function getKeepLinkPrefixes(): array
    {
        $prefixes = $this->keepLinkPrefixes ?? (array) config('rich-editor-toolkit.paste_clean.keep_link_prefixes', []);

        return array_values(array_filter($prefixes, static fn (mixed $prefix): bool => is_string($prefix) && $prefix !== ''));
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        $params = ['keep' => $this->getKeepLinkPrefixes()];

        if ($this->isStrict()) {
            $params['strict'] = ['1'];
        }

        $modules = [Assets::url('paste-clean', $params)];

        if ($this->isUploadingImages()) {
            $modules[] = Assets::url('paste-images', [
                'host' => $this->getRemoteImageHosts(),
                'action' => [self::FETCH_ACTION],
                'msg' => [__('rich-editor-toolkit::rich-editor-toolkit.paste_images_failed')],
            ]);
        }

        return $modules;
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('cleanFormat')
                ->label(__('rich-editor-toolkit::rich-editor-toolkit.clear_formatting'))
                // An eraser — "wipe the styling"; deliberately unlike the link icons next to it.
                ->icon(new HtmlString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 21-4.3-4.3c-1-1-1-2.5 0-3.4l9.6-9.6c1-1 2.5-1 3.4 0l5.6 5.6c1 1 1 2.5 0 3.4L13 21"/><path d="M22 21H7"/><path d="m5 11 9 9"/></svg>'))
                // `?.` like all the stock code: before TipTap initialises, editor === undefined.
                ->jsHandler('$getEditor()?.chain().focus().asignuaCleanFormat().run()'),
        ];
    }

    /**
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        if (!$this->isUploadingImages() || $this->getRemoteImageHosts() === []) {
            return [];
        }

        return [
            // Mounted by `paste-images.js` WITHOUT a modal: Filament runs a modal-less action at
            // once and hands its return value back to the browser, which is what makes this a
            // call-and-answer seam (the same Livewire request as every other toolbar action: CSRF,
            // session, the page's own authorisation). It never returns an exception message.
            Action::make(self::FETCH_ACTION)
                ->label(__('rich-editor-toolkit::rich-editor-toolkit.paste_images_fetch'))
                ->action(fn (array $arguments, RichEditor $component): array => $this->fetchRemoteImage($arguments['url'] ?? null, $component)),
        ];
    }

    /**
     * The server half of a Google Docs picture. Answers `['dataUrl' => ...]` or `['error' => code]`;
     * a `data:` URL because Filament's own paste handler then does the rest (type and size check,
     * upload, the attachment record) exactly as for any other pasted picture.
     *
     * @internal public for the test suite; reached through the editor action
     *
     * @return array{dataUrl?: string, error?: string}
     */
    public function fetchRemoteImage(mixed $url, RichEditor $component): array
    {
        $hosts = $this->getRemoteImageHosts();

        // The field must really take attachments: this action must not become a free proxy for
        // fields that never asked for pictures.
        if (!is_string($url) || $hosts === [] || $component->isDisabled() || !$component->hasFileAttachments()) {
            return ['error' => 'disabled'];
        }

        if (!$this->canFetchRemoteImages($component)) {
            return ['error' => 'unauthorized'];
        }

        // Two buckets, both must have room: the viewer's total, and the viewer on THIS field (one
        // runaway field cannot eat the whole allowance, and a flood of fields cannot multiply it).
        // The viewer is the user id or, for a guest, the IP: behind a reverse proxy that is only
        // the visitor's address when the app trusts the proxy (TrustProxies); otherwise every guest
        // shares the proxy's address, and with a too-trusting list a guest can pick his own.
        $perMinute = max(1, (int) config('rich-editor-toolkit.paste_clean.remote_image_rate_limit', 60));
        $perField = max(1, (int) config('rich-editor-toolkit.paste_clean.remote_image_rate_limit_per_field', 30));
        $viewer = auth()->id() ?? request()->ip() ?? 'guest';

        if (!RateLimiter::attempt('asignua-rte-remote-image:'.$viewer, $perMinute, static fn (): bool => true, 60)
            || !RateLimiter::attempt('asignua-rte-remote-image:'.$viewer.':'.$component->getStatePath(), $perField, static fn (): bool => true, 60)) {
            return ['error' => 'rate'];
        }

        $maxBytes = max(1, (int) config('rich-editor-toolkit.paste_clean.remote_image_max_kb', 5120)) * 1024;
        $fieldMaxKb = $component->getFileAttachmentsMaxSize();

        if ($fieldMaxKb !== null && $fieldMaxKb > 0) {
            $maxBytes = min($maxBytes, $fieldMaxKb * 1024);
        }

        $accept = $component->getFileAttachmentsAcceptedFileTypes();

        try {
            $image = app(RemoteImageFetcher::class)->fetch($url, $hosts, $maxBytes, $accept === null || $accept === [] ? null : array_values($accept));
        } catch (RemoteImageException $exception) {
            return ['error' => $exception->reason];
        }

        return ['dataUrl' => $image->toDataUrl()];
    }
}
