<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Requests;

use Basalto\Core\Models\MediaFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Metadaten eines Mediums (Mockup 14): Titel, Urheber, Alt-Text je Sprache, Fokuspunkt, Ordner. */
final class UpdateMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:200'],
            'credit' => ['nullable', 'string', 'max:200'],
            'alt' => ['nullable', 'array'],
            'alt.*' => ['nullable', 'string', 'max:500'],
            'focus_x' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'focus_y' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'folder_id' => ['nullable', 'integer', Rule::exists(MediaFolder::class, 'id')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['title' => __('Titel'), 'credit' => __('Urheber'), 'alt.*' => __('Alt-Text'), 'focus_x' => __('Fokuspunkt'), 'focus_y' => __('Fokuspunkt'), 'folder_id' => __('Ordner')];
    }
}
