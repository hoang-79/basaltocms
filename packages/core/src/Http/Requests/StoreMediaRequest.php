<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Requests;

use Basalto\Core\Models\MediaFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload in die Mediathek (K-08): eine Datei (`file`) oder mehrere (`files[]`) — die Regeln je Datei prüft der
 * Controller einzeln, damit eine Abweisung die anderen nicht aufhält; hier nur Form und Ordner.
 */
final class StoreMediaRequest extends FormRequest
{
    public const MIMES = 'image/jpeg,image/png,image/webp,image/gif,image/svg+xml,application/pdf';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required_without:files', 'file'],
            'files' => ['required_without:file', 'array', 'max:200'],
            'files.*' => ['file'],
            'folder_id' => ['nullable', 'integer', Rule::exists(MediaFolder::class, 'id')],
        ];
    }

    /**
     * Die Regeln je Datei — Typ und Grösse aus der Konfiguration.
     *
     * @return list<string>
     */
    public static function fileRules(): array
    {
        return ['file', 'max:'.(int) config('basalto.media.max_kilobytes', 10240), 'mimetypes:'.self::MIMES];
    }
}
