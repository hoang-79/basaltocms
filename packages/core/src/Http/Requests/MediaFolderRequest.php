<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Requests;

use Basalto\Core\Models\MediaFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ordner der Mediathek anlegen oder umbenennen. */
final class MediaFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', Rule::exists(MediaFolder::class, 'id')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => __('Name'), 'parent_id' => __('Übergeordneter Ordner')];
    }
}
