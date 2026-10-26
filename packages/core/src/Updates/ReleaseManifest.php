<?php

declare(strict_types=1);

namespace Basalto\Core\Updates;

use Basalto\Core\Core;
use Basalto\Core\Release\ReleaseSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Das Manifest des neusten Releases beim Hersteller (`/_download/latest.json`, CR-007 U-02): nur geprüft übernommen —
 * Ed25519 über Version, Datei, Grösse und SHA-256 mit dem öffentlichen Release-Schlüssel des Kerns. Notizen und Verlauf
 * sind nicht signiert; sie dienen nur der Anzeige und werden auf Text gekürzt. Mit der Abfrage gehen die
 * Nutzungsangaben aus {@see InstanceId} mit, wenn sie eingeschaltet sind (TB 55).
 *
 * @phpstan-type Note array{version: string, date: string|null, notes: string}
 * @phpstan-type Release array{version: string, file: string, size: int, sha256: string, signature: string, php: string, date: string|null, notes: list<Note>}
 */
final class ReleaseManifest
{
    /** @return Release */
    public function fetch(): array
    {
        try {
            $response = Http::timeout(8)->connectTimeout(4)->acceptJson()->withUserAgent('Basalto/'.Core::VERSION)->withHeaders(InstanceId::headers())
                ->get(UpdateSource::url('_download/latest.json'));
        } catch (ConnectionException) {
            throw new RuntimeException((string) __(':host ist nicht erreichbar.', ['host' => UpdateSource::host()]));
        }
        if ($response->status() === 404) {
            throw new RuntimeException((string) __(':host bietet keine Releases an.', ['host' => UpdateSource::host()]));
        }
        if (! $response->successful()) {
            throw new RuntimeException((string) __(':host antwortet mit :status.', ['host' => UpdateSource::host(), 'status' => $response->status()]));
        }
        try {
            $data = $response->json();
        } catch (Throwable) {
            $data = null;
        }

        return self::parse(is_array($data) ? $data : []);
    }

    /**
     * @param  array<mixed>  $data
     * @return Release
     */
    public static function parse(array $data): array
    {
        $version = is_string($data['version'] ?? null) ? $data['version'] : '';
        $file = is_string($data['file'] ?? null) ? $data['file'] : '';
        $sha = is_string($data['sha256'] ?? null) ? strtolower($data['sha256']) : '';
        $signature = is_string($data['signature'] ?? null) ? $data['signature'] : '';
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,63}$/', $version) !== 1 || preg_match('/^basalto-[0-9A-Za-z.+-]+\.zip$/', $file) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1 || ! is_int($data['size'] ?? null) || $data['size'] <= 0 || $signature === '') {
            throw new RuntimeException((string) __('Das Manifest des Herstellers ist unvollständig.'));
        }
        $manifest = ['version' => $version, 'file' => $file, 'size' => $data['size'], 'sha256' => $sha, 'signature' => $signature];
        if (! ReleaseSignature::verify($manifest, (string) config('basalto.release.public_key'))) {
            throw new RuntimeException((string) __('Die Signatur des Releases passt nicht — das Update wird nicht angeboten.'));
        }
        $notes = [];
        foreach ([['version' => $version, 'created_at' => $data['created_at'] ?? null, 'notes' => $data['notes'] ?? null], ...(is_array($data['history'] ?? null) ? $data['history'] : [])] as $entry) {
            if (is_array($entry) && is_string($entry['version'] ?? null) && is_string($entry['notes'] ?? null) && trim($entry['notes']) !== '') {
                $notes[] = ['version' => mb_substr($entry['version'], 0, 64), 'date' => is_string($entry['created_at'] ?? null) ? mb_substr($entry['created_at'], 0, 10) : null,
                    'notes' => mb_substr(strip_tags($entry['notes']), 0, 4000)];
            }
        }

        return $manifest + ['php' => is_string($data['php'] ?? null) ? mb_substr($data['php'], 0, 20) : '>=8.4',
            'date' => is_string($data['created_at'] ?? null) ? mb_substr($data['created_at'], 0, 10) : null, 'notes' => array_slice($notes, 0, 20)];
    }
}
