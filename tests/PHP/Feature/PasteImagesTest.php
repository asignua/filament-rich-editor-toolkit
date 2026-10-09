<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Tests\Feature;

use Asignua\RichEditorToolkit\Plugins\PasteCleanPlugin;
use Asignua\RichEditorToolkit\Support\RemoteImageFetcher;
use Asignua\RichEditorToolkit\Tests\TestCase;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Workbench\App\Livewire\DemoForm;

/**
 * The PHP half of "upload pasted images": the plugin's switches and module URL, and the editor
 * action that the browser mounts to have a Google Docs picture fetched.
 */
class PasteImagesTest extends TestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01";

    private const string URL = 'https://lh7-rt.googleusercontent.com/docsz/abc?key=1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(RemoteImageFetcher::class, new RemoteImageFetcher(fn (string $host): array => ['142.250.74.33']));
        // The action needs a logged-in user by default; the guest cases log out again.
        $this->actingAs($this->user = \Workbench\App\Models\User::factory()->create());
    }

    private \Workbench\App\Models\User $user;

    // --- switches ----------------------------------------------------------------------------------

    public function test_nothing_changes_by_default(): void
    {
        $plugin = PasteCleanPlugin::make();

        $this->assertFalse($plugin->isUploadingImages());
        $this->assertCount(1, $plugin->getTipTapJsExtensions());
        $this->assertSame([], $plugin->getEditorActions());
    }

    public function test_upload_images_adds_the_module_with_hosts_action_and_message(): void
    {
        $modules = PasteCleanPlugin::make()->uploadImages()->getTipTapJsExtensions();

        $this->assertCount(2, $modules);
        $this->assertStringContainsString('paste-clean.js', $modules[0]);
        $this->assertStringContainsString('paste-images.js', $modules[1]);
        $this->assertStringContainsString('host=%2A.googleusercontent.com', $modules[1]);
        $this->assertStringContainsString('action='.PasteCleanPlugin::FETCH_ACTION, $modules[1]);
        $this->assertStringContainsString('msg='.rawurlencode(__('rich-editor-toolkit::rich-editor-toolkit.paste_images_failed')), $modules[1]);
        $this->assertStringContainsString(':count', __('rich-editor-toolkit::rich-editor-toolkit.paste_images_failed'));
    }

    public function test_the_config_switches_it_on_and_the_method_overrides_the_config(): void
    {
        config()->set('rich-editor-toolkit.paste_clean.upload_images', true);

        $this->assertTrue(PasteCleanPlugin::make()->isUploadingImages());
        $this->assertFalse(PasteCleanPlugin::make()->uploadImages(false)->isUploadingImages());
        $this->assertCount(1, PasteCleanPlugin::make()->uploadImages(false)->getTipTapJsExtensions());
    }

    public function test_the_host_list_comes_from_the_config_or_the_method_and_is_cleaned(): void
    {
        $this->assertSame(['*.googleusercontent.com'], PasteCleanPlugin::make()->getRemoteImageHosts());

        config()->set('rich-editor-toolkit.paste_clean.remote_image_hosts', [' IMG.Example.com ', '', 5, 'img.example.com']);
        $this->assertSame(['img.example.com'], PasteCleanPlugin::make()->getRemoteImageHosts());

        $this->assertSame(['a.test'], PasteCleanPlugin::make()->remoteImageHosts(['a.test'])->getRemoteImageHosts());
    }

    public function test_without_hosts_there_is_no_server_action_and_no_hosts_in_the_module_url(): void
    {
        $plugin = PasteCleanPlugin::make()->uploadImages()->remoteImageHosts([]);

        $this->assertSame([], $plugin->getEditorActions());
        $this->assertStringNotContainsString('host=', $plugin->getTipTapJsExtensions()[1]);
    }

    public function test_a_field_takes_attachments_only_with_the_attach_files_button_or_explicitly(): void
    {
        // Filament's rule, which the whole feature rests on: no attachments, no `localFiles` in the browser.
        $this->assertTrue($this->contained(RichEditor::make('a')->toolbarButtons(['bold', 'attachFiles']))->hasFileAttachments());
        $this->assertFalse($this->contained(RichEditor::make('b')->toolbarButtons(['bold']))->hasFileAttachments());
        $this->assertTrue($this->contained(RichEditor::make('c')->toolbarButtons(['bold'])->fileAttachments(true))->hasFileAttachments());
    }

    public function test_the_action_is_registered_with_the_editor_only_when_enabled(): void
    {
        $names = fn (PasteCleanPlugin $plugin): array => array_map(fn ($action): ?string => $action->getName(), $plugin->getEditorActions());

        $this->assertSame([PasteCleanPlugin::FETCH_ACTION], $names(PasteCleanPlugin::make()->uploadImages()));
        $this->assertSame([], $names(PasteCleanPlugin::make()));
        $this->assertStringContainsString('paste-images.js', Livewire::test(DemoForm::class)->html());
    }

    // --- the action ---------------------------------------------------------------------------------

    private function editor(bool $attachments = true, bool $disabled = false): RichEditor
    {
        return $this->contained(RichEditor::make('body')->fileAttachments($attachments)->disabled($disabled));
    }

    private function contained(RichEditor $editor): RichEditor
    {
        // A field needs a schema to be evaluated (disabled state, attachments, toolbar).
        Schema::make(Livewire::test(DemoForm::class)->instance())->components([$editor])->getFlatComponents();

        return $editor;
    }

    public function test_the_action_answers_with_a_data_url(): void
    {
        Http::fake(['*' => Http::response(self::PNG)]);

        $result = PasteCleanPlugin::make()->uploadImages()->fetchRemoteImage(self::URL, $this->editor());

        $this->assertSame(['dataUrl' => 'data:image/png;base64,'.base64_encode(self::PNG)], $result);
    }

    public function test_the_action_returns_error_codes_never_messages(): void
    {
        Http::fake(['*' => Http::response(self::PNG)]);
        $plugin = PasteCleanPlugin::make()->uploadImages();

        $this->assertSame(['error' => 'host'], $plugin->fetchRemoteImage('https://example.com/a.png', $this->editor()));
        $this->assertSame(['error' => 'url'], $plugin->fetchRemoteImage('http://lh7-rt.googleusercontent.com/a.png', $this->editor()));
        $this->assertSame(['error' => 'host'], $plugin->fetchRemoteImage('https://127.0.0.1/a.png', $this->editor()));
        $this->assertSame(['error' => 'disabled'], $plugin->fetchRemoteImage(['https://x'], $this->editor()));
        $this->assertSame(['error' => 'disabled'], $plugin->fetchRemoteImage(null, $this->editor()));
    }

    public function test_a_private_answer_from_dns_is_an_error_code(): void
    {
        $this->app->instance(RemoteImageFetcher::class, new RemoteImageFetcher(fn (string $host): array => ['127.0.0.1']));
        Http::fake();

        $this->assertSame(['error' => 'private'], PasteCleanPlugin::make()->uploadImages()->fetchRemoteImage(self::URL, $this->editor()));
        Http::assertNothingSent();
    }

    public function test_a_field_without_file_attachments_or_a_disabled_one_is_not_a_proxy(): void
    {
        Http::fake(['*' => Http::response(self::PNG)]);
        $plugin = PasteCleanPlugin::make()->uploadImages();

        $this->assertSame(['error' => 'disabled'], $plugin->fetchRemoteImage(self::URL, $this->editor(attachments: false)));
        $this->assertSame(['error' => 'disabled'], $plugin->fetchRemoteImage(self::URL, $this->editor(disabled: true)));
        Http::assertNothingSent();
    }

    public function test_the_fields_own_limits_apply(): void
    {
        Http::fake(['*' => Http::response(self::PNG.str_repeat('x', 3000))]);
        $plugin = PasteCleanPlugin::make()->uploadImages();

        $this->assertSame(['error' => 'size'], $plugin->fetchRemoteImage(self::URL, $this->editor()->fileAttachmentsMaxSize(2)));
        $this->assertArrayHasKey('dataUrl', $plugin->fetchRemoteImage(self::URL, $this->editor()->fileAttachmentsMaxSize(4)));
        $this->assertSame(['error' => 'type'], $plugin->fetchRemoteImage(self::URL, $this->editor()->fileAttachmentsAcceptedFileTypes(['image/jpeg'])));
    }

    public function test_the_configured_cap_applies_when_the_field_has_none(): void
    {
        config()->set('rich-editor-toolkit.paste_clean.remote_image_max_kb', 1);
        Http::fake(['*' => Http::response(self::PNG.str_repeat('x', 3000))]);

        $this->assertSame(['error' => 'size'], PasteCleanPlugin::make()->uploadImages()->fetchRemoteImage(self::URL, $this->editor()));
    }

    public function test_the_download_rate_is_limited_per_viewer(): void
    {
        config()->set('rich-editor-toolkit.paste_clean.remote_image_rate_limit', 2);
        Http::fake(['*' => Http::response(self::PNG)]);
        $plugin = PasteCleanPlugin::make()->uploadImages();

        $this->assertArrayHasKey('dataUrl', $plugin->fetchRemoteImage(self::URL, $this->editor()));
        $this->assertArrayHasKey('dataUrl', $plugin->fetchRemoteImage(self::URL, $this->editor()));
        $this->assertSame(['error' => 'rate'], $plugin->fetchRemoteImage(self::URL, $this->editor()));
        Http::assertSentCount(2);
    }

    public function test_the_browser_gets_the_answer_back_from_the_modal_less_action(): void
    {
        Http::fake(['*' => Http::response(self::PNG)]);

        $test = Livewire::test(DemoForm::class)
            ->call('mountAction', PasteCleanPlugin::FETCH_ACTION, ['url' => self::URL], ['schemaComponent' => 'form.body']);

        $returns = $test->effects['returns'] ?? [];

        $this->assertSame([['dataUrl' => 'data:image/png;base64,'.base64_encode(self::PNG)]], $returns);
        Http::assertSentCount(1);
    }

    public function test_a_foreign_url_sent_to_the_action_is_refused_without_a_request(): void
    {
        Http::fake();

        $test = Livewire::test(DemoForm::class)
            ->call('mountAction', PasteCleanPlugin::FETCH_ACTION, ['url' => 'https://169.254.169.254/latest/meta-data/'], ['schemaComponent' => 'form.body']);

        $this->assertSame([['error' => 'host']], $test->effects['returns'] ?? []);
        Http::assertNothingSent();
    }

    public function test_a_guest_is_refused_by_default_and_nothing_is_fetched(): void
    {
        Http::fake(['*' => Http::response(self::PNG)]);
        auth()->logout();

        $this->assertSame(['error' => 'unauthorized'], PasteCleanPlugin::make()->uploadImages()->fetchRemoteImage(self::URL, $this->editor()));
        Http::assertNothingSent();
    }

    public function test_guests_can_be_allowed_by_config_or_per_field(): void
    {
        Http::fake(['*' => Http::response(self::PNG)]);
        auth()->logout();

        config()->set('rich-editor-toolkit.paste_clean.remote_image_guests', true);
        $this->assertArrayHasKey('dataUrl', PasteCleanPlugin::make()->uploadImages()->fetchRemoteImage(self::URL, $this->editor()));

        config()->set('rich-editor-toolkit.paste_clean.remote_image_guests', false);
        $this->assertSame(['error' => 'unauthorized'], PasteCleanPlugin::make()->uploadImages()->fetchRemoteImage(self::URL, $this->editor()));
        $this->assertArrayHasKey('dataUrl', PasteCleanPlugin::make()->uploadImages()->authorizeRemoteImages()->fetchRemoteImage(self::URL, $this->editor()));
    }

    public function test_a_closure_decides_and_receives_the_field(): void
    {
        Http::fake(['*' => Http::response(self::PNG)]);
        $seen = null;
        $plugin = PasteCleanPlugin::make()->uploadImages()->authorizeRemoteImages(function (RichEditor $component) use (&$seen): bool {
            $seen = $component->getName();

            return false;
        });

        $this->assertSame(['error' => 'unauthorized'], $plugin->fetchRemoteImage(self::URL, $this->editor()));
        $this->assertSame('body', $seen);
        $this->assertSame(['error' => 'unauthorized'], PasteCleanPlugin::make()->uploadImages()->authorizeRemoteImages(false)->fetchRemoteImage(self::URL, $this->editor()));
        Http::assertNothingSent();
    }

    public function test_the_per_field_bucket_is_separate_from_the_viewers_total(): void
    {
        config()->set('rich-editor-toolkit.paste_clean.remote_image_rate_limit', 10);
        config()->set('rich-editor-toolkit.paste_clean.remote_image_rate_limit_per_field', 1);
        Http::fake(['*' => Http::response(self::PNG)]);
        $plugin = PasteCleanPlugin::make()->uploadImages();
        $other = $this->contained(RichEditor::make('summary')->fileAttachments(true));

        $this->assertArrayHasKey('dataUrl', $plugin->fetchRemoteImage(self::URL, $this->editor()));
        $this->assertSame(['error' => 'rate'], $plugin->fetchRemoteImage(self::URL, $this->editor()), 'the second download on the same field');
        $this->assertArrayHasKey('dataUrl', $plugin->fetchRemoteImage(self::URL, $other), 'another field still has its own room');
    }

    public function test_a_refused_request_does_not_burn_the_other_bucket(): void
    {
        config()->set('rich-editor-toolkit.paste_clean.remote_image_rate_limit', 3);
        config()->set('rich-editor-toolkit.paste_clean.remote_image_rate_limit_per_field', 1);
        Http::fake(['*' => Http::response(self::PNG)]);
        $plugin = PasteCleanPlugin::make()->uploadImages();
        $first = $this->editor();

        $this->assertArrayHasKey('dataUrl', $plugin->fetchRemoteImage(self::URL, $first));

        // Refused by the field bucket: none of these may count against the viewer's total.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(['error' => 'rate'], $plugin->fetchRemoteImage(self::URL, $first));
        }

        $this->assertArrayHasKey('dataUrl', $plugin->fetchRemoteImage(self::URL, $this->contained(RichEditor::make('b')->fileAttachments(true))));
        $this->assertArrayHasKey('dataUrl', $plugin->fetchRemoteImage(self::URL, $this->contained(RichEditor::make('c')->fileAttachments(true))));
        $this->assertSame(['error' => 'rate'], $plugin->fetchRemoteImage(self::URL, $this->contained(RichEditor::make('d')->fileAttachments(true))), 'the viewer total is now used up');
    }

    // --- the module ------------------------------------------------------------------------------------

    public function test_the_php_and_js_agree_on_the_names_and_the_host_rule(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/src/paste-images.js');

        $this->assertStringContainsString("'".PasteCleanPlugin::FETCH_ACTION."'", $js);
        $this->assertStringContainsString('name: \'asignuaPasteImages\'', $js);
    }
}
