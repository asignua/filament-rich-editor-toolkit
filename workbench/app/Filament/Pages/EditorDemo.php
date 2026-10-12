<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Pages;

use Asignua\RichEditorToolkit\Plugins\CustomAttributesPlugin;
use Asignua\RichEditorToolkit\Plugins\EmbedPlugin;
use Asignua\RichEditorToolkit\Plugins\FullscreenPlugin;
use Asignua\RichEditorToolkit\Plugins\ImagePropertiesPlugin;
use Asignua\RichEditorToolkit\Plugins\ImageUrlPlugin;
use Asignua\RichEditorToolkit\Plugins\LinkOptionsPlugin;
use Asignua\RichEditorToolkit\Plugins\PasteCleanPlugin;
use Asignua\RichEditorToolkit\Plugins\SourceCodePlugin;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * A hand-testing page: every toolkit plugin on one editor, plus the same editor in a repeater and in a modal.
 */
class EditorDemo extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?string $title = 'Article';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        // @phpstan-ignore property.notFound (magic $form)
        $this->form->fill([
            'body' => <<<'HTML'
                <h2>Warehouse opening in Lviv</h2>
                <p>Acme Supply opens its third warehouse on <strong>1 November</strong>. Orders placed after 20 October ship from the new site.</p>
                <p>Read the <a href="https://example.com/terms" target="_blank" rel="noopener noreferrer nofollow">delivery terms</a> before placing a bulk order.</p>
                <ul><li>12 000 m² of storage</li><li>Same-day dispatch for orders before 14:00</li></ul>
                HTML,
            'sections' => [['body' => '<p>A short note inside a repeater item.</p>']],
        ]);
    }

    public static function editor(string $name): RichEditor
    {
        return RichEditor::make($name)
            ->plugins([
                CustomAttributesPlugin::make(),
                EmbedPlugin::make(),
                ImageUrlPlugin::make(),
                ImagePropertiesPlugin::make(),
                LinkOptionsPlugin::make(),
                PasteCleanPlugin::make()->strict()->uploadImages(),
                FullscreenPlugin::make(),
                SourceCodePlugin::make(),
            ])
            ->fileAttachmentsDisk('public')
            ->resizableImages()
            ->floatingToolbars(fn (RichEditor $component): array => [
                ...$component->getDefaultFloatingToolbars(),
                'image' => ['imageProperties'],
            ])
            ->textLength(max: 1000, maxWords: 150) // @phpstan-ignore method.notFound (macro)
            ->toolbarButtons([
                ['bold', 'italic', 'link', 'linkOptions'],
                ['h2', 'h3', 'bulletList', 'orderedList', 'table'],
                ['attachFiles', 'imageUrl', 'imageProperties', 'embed'],
                ['cleanFormat', 'sourceCode', 'fullscreen'],
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Content')->schema([static::editor('body')]),
                Section::make('Sections')->schema([
                    Repeater::make('sections')->schema([static::editor('body')]),
                ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->submit('save')])]),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('note')
                ->label('Edit in a modal')
                ->schema([static::editor('note')])
                ->fillForm(['note' => '<p>An editor inside a modal: try fullscreen and Esc.</p>'])
                ->action(fn (array $data) => Notification::make()->title('Saved: '.strlen((string) $data['note']).' bytes')->success()->send()),
        ];
    }

    public function save(): void
    {
        // @phpstan-ignore property.notFound (magic $form)
        $state = $this->form->getState();

        Notification::make()->title('Saved')->body(e(mb_substr((string) json_encode($state), 0, 300)))->success()->send();
    }
}
