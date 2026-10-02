<?php

declare(strict_types=1);

namespace Workbench\App\Livewire;

use Asignua\RichEditorToolkit\Plugins\CustomAttributesPlugin;
use Asignua\RichEditorToolkit\Plugins\EmbedPlugin;
use Asignua\RichEditorToolkit\Plugins\ImageUrlPlugin;
use Asignua\RichEditorToolkit\Plugins\PasteCleanPlugin;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * A form with a RichEditor wearing every plugin of the toolkit.
 */
class DemoForm extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        // @phpstan-ignore property.notFound (magic $form from InteractsWithForms)
        $this->form->fill(['body' => '<p class="lead-in" data-track="hero">Hello</p>']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                RichEditor::make('body')
                    ->plugins([
                        CustomAttributesPlugin::make(),
                        EmbedPlugin::make(),
                        ImageUrlPlugin::make(),
                        PasteCleanPlugin::make(),
                    ])
                    ->toolbarButtons([['bold', 'cleanFormat', 'embed', 'imageUrl']]),
            ])
            ->statePath('data');
    }

    public function render(): View
    {
        /** @var view-string $view */
        $view = 'demo-form';

        return view($view);
    }
}
