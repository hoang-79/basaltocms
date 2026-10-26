<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Anhänge von Besuchern (CR-006 FO-07, FO-K6): Prüfen, Speichern, Binden, Ausliefern. Erlaubte Arten, Grösse je Datei
 * und Kontingent je Konto stehen in den Einstellungen der Site (`community.attachments.*`). Rasterbilder werden neu
 * kodiert (WebP, höchstens 1600 px) — Ort und Kamera fallen weg, eingeschleuster Code auch; GIF bleibt (Animation),
 * SVG ist nie erlaubt. Wer einen gebundenen Anhang sehen darf, sagt das Modul, dem der Inhalt gehört
 * (`readableUsing`, beim Booten angemeldet).
 */
final class Attachments
{
    public const DEFAULT_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'zip', 'txt', 'log', 'json'];

    public const DEFAULT_MAX_MB = 5;

    public const DEFAULT_QUOTA_MB = 50;

    public const MAX_EDGE = 1600;

    /** Besucherrecht: Dateien anhängen. */
    public const PERMISSION = 'community.attach';

    /** @var array<string, Closure(CommunityAttachment, ?Customer): bool> beim Booten gefüllt, danach nur gelesen */
    private array $readers = [];

    public function __construct(private readonly Settings $settings) {}

    /** @param  Closure(CommunityAttachment, ?Customer): bool  $reader */
    public function readableUsing(string $subjectType, Closure $reader): void
    {
        $this->readers[$subjectType] = $reader;
    }

    /** @return list<string> */
    public function types(Site $site): array
    {
        $value = $this->settings->get('community.attachments.types', self::DEFAULT_TYPES, $site);

        return array_values(array_diff(array_map(static fn (mixed $t): string => strtolower(trim((string) $t)), is_array($value) ? $value : self::DEFAULT_TYPES), ['svg', 'svgz', 'html', 'htm', 'php', 'js']));
    }

    public function maxBytes(Site $site): int
    {
        return max(1, (int) $this->settings->get('community.attachments.max_mb', self::DEFAULT_MAX_MB, $site)) * 1024 * 1024;
    }

    /** TB 52: höchstens so viele Anhänge je Beitrag (WoltLab: 20). */
    public function maxFiles(Site $site): int
    {
        return max(1, (int) $this->settings->get('community.attachments.max_files', 20, $site));
    }

    public function quotaBytes(Site $site): int
    {
        return max(1, (int) $this->settings->get('community.attachments.quota_mb', self::DEFAULT_QUOTA_MB, $site)) * 1024 * 1024;
    }

    public function usedBytes(Customer $customer): int
    {
        return (int) CommunityAttachment::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->sum('size');
    }

    /** Ein Fehlertext oder null, wenn die Datei angenommen werden kann. */
    public function problem(Site $site, Customer $customer, UploadedFile $file, ?string $lang = null): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, $this->types($site), true)) {
            return (string) __('Diese Dateiart ist nicht erlaubt. Erlaubt: :types.', ['types' => implode(', ', $this->types($site))], $lang);
        }
        if ((int) $file->getSize() > $this->maxBytes($site)) {
            return (string) __('Die Datei ist grösser als :mb MB.', ['mb' => intdiv($this->maxBytes($site), 1024 * 1024)], $lang);
        }
        if ($this->usedBytes($customer) + (int) $file->getSize() > $this->quotaBytes($site)) {
            return (string) __('Ihr Speicher für Anhänge ist voll (:mb MB). Entfernen Sie alte Anhänge oder fragen Sie das Team.', ['mb' => intdiv($this->quotaBytes($site), 1024 * 1024)], $lang);
        }

        return null;
    }

    /** Speichern (vorher `problem` prüfen). Bilder werden neu kodiert. */
    public function store(Site $site, Customer $customer, UploadedFile $file): CommunityAttachment
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $name = Str::limit(trim((string) preg_replace('/[^\pL\pN ._()-]+/u', '_', $file->getClientOriginalName())), 190, '') ?: 'datei.'.$extension;
        $random = Str::lower(Str::random(16));
        $width = $height = null;
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            [$bytes, $width, $height] = self::reencode((string) file_get_contents($file->getRealPath()));
            $path = 'community-attachments/'.$site->id.'/'.$random.'.webp';
            $mime = 'image/webp';
            $name = (string) preg_replace('/\.[a-z0-9]+$/i', '', $name).'.webp';
        } elseif ($extension === 'gif') {
            $size = @getimagesize($file->getRealPath());
            if ($size === false) {
                throw new RuntimeException('Bild nicht lesbar');
            }
            [$width, $height] = [(int) $size[0], (int) $size[1]];
            $bytes = (string) file_get_contents($file->getRealPath());
            $path = 'community-attachments/'.$site->id.'/'.$random.'.gif';
            $mime = 'image/gif';
        } else {
            $bytes = (string) file_get_contents($file->getRealPath());
            $path = 'community-attachments/'.$site->id.'/'.$random.'.'.$extension;
            $mime = match ($extension) {
                'pdf' => 'application/pdf',
                'zip' => 'application/zip',
                'json' => 'application/json',
                default => 'text/plain',
            };
        }
        Storage::disk()->put($path, $bytes);

        return CommunityAttachment::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'customer_id' => $customer->id, 'path' => $path, 'name' => $name,
            'mime' => $mime, 'size' => strlen($bytes), 'width' => $width, 'height' => $height]);
    }

    /**
     * Ungebundene Anhänge der Person an einen Inhalt binden; fremde und schon gebundene bleiben, wie sie sind.
     *
     * @param  list<int>  $ids
     * @return list<int> die gebundenen Kennungen
     */
    public function assign(Customer $customer, array $ids, string $subjectType, int $subjectId): array
    {
        if ($ids === []) {
            return [];
        }
        $query = CommunityAttachment::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->where('site_id', $customer->site_id)
            ->whereIn('id', $ids)->where('subject_type', '');
        $found = array_values((clone $query)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
        $query->update(['subject_type' => $subjectType, 'subject_id' => $subjectId]);

        return $found;
    }

    /** @return list<CommunityAttachment> */
    public function of(string $subjectType, int $subjectId): array
    {
        return array_values(CommunityAttachment::query()->withoutGlobalScopes()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->orderBy('id')->get()->all());
    }

    public function mayRead(CommunityAttachment $attachment, ?Customer $customer): bool
    {
        if (! $attachment->isAssigned()) {
            return $customer !== null && $attachment->customer_id === $customer->id;
        }
        $reader = $this->readers[$attachment->subject_type] ?? null;

        return $reader !== null && $reader($attachment, $customer);
    }

    public function delete(CommunityAttachment $attachment): void
    {
        Storage::disk()->delete($attachment->path);
        $attachment->delete();
    }

    /**
     * Rasterbild neu kodieren: höchstens 1600 px an der längsten Kante, WebP 82.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private static function reencode(string $bytes): array
    {
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new RuntimeException('Bild nicht lesbar');
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_EDGE / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($w, $h);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $w, $h, $width, $height);
        ob_start();
        imagewebp($target, null, 82);
        $out = (string) ob_get_clean();
        imagedestroy($target);
        imagedestroy($source);

        return [$out, $w, $h];
    }
}
