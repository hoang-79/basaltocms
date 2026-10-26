<?php

declare(strict_types=1);

namespace Basalto\Core\Security;

use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Abgleich mit Stop Forum Spam (TB 50, CR-009 Nr. 12): Neue Kundenkonten und Kommentare lassen sich gegen die
 * öffentliche Liste bekannter Spam-Absender prüfen. Aus, bis der Betreiber es einschaltet — denn dazu gehen die
 * IP-Adresse und ein MD5-Hash der E-Mail-Adresse (nie die Adresse selbst) an stopforumspam.com. Ab einer Zuversicht
 * (Standard 80 %) gilt ein Eintrag als Treffer. Ausfälle und Zeitüberschreitungen lassen durch; Antworten bleiben einen
 * Tag im Cache.
 */
final class StopForumSpam
{
    public function __construct(private readonly Settings $settings) {}

    public function enabled(?Site $site): bool
    {
        return (bool) $this->settings->get('spam.sfs', false, $site);
    }

    /** `email` oder `ip`, wenn die Liste sie kennt — sonst null. */
    public function listed(?Site $site, ?string $email, ?string $ip): ?string
    {
        if (! $this->enabled($site) || (($email === null || $email === '') && ($ip === null || $ip === ''))) {
            return null;
        }
        $threshold = max(1, min(100, (int) $this->settings->get('spam.sfs_confidence', 80, $site)));
        $hash = $email === null || $email === '' ? null : md5(mb_strtolower(trim($email)));
        $result = Cache::remember('basalto.sfs.'.hash('sha256', ($hash ?? '').'|'.($ip ?? '')), now()->addDay(), function () use ($hash, $ip): array {
            try {
                $response = Http::timeout(3)->acceptJson()->get((string) config('basalto.spam.stopforumspam_url'), array_filter(['json' => '', 'emailhash' => $hash, 'ip' => $ip, 'confidence' => ''], static fn (?string $v): bool => $v !== null));
                if (! $response->successful() || $response->json('success') !== 1) {
                    return [];
                }

                return ['email' => (float) $response->json('emailhash.confidence', 0), 'ip' => (float) $response->json('ip.confidence', 0)];
            } catch (Throwable $exception) {
                Log::warning('Stop Forum Spam nicht erreichbar: '.$exception->getMessage());

                return [];
            }
        });
        foreach (['email', 'ip'] as $kind) {
            if (($result[$kind] ?? 0) >= $threshold) {
                return $kind;
            }
        }

        return null;
    }
}
