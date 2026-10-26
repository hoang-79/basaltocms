<?php

declare(strict_types=1);

namespace Basalto\Core\Community\Http;

use Basalto\Core\Community\Profiles;
use Basalto\Core\Community\Sanctions;
use Basalto\Core\Community\Typing;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\Live\Live;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * «Wer schreibt gerade» (TB 49): Die Editor-Insel meldet, dass das Konto an einem Ort tippt oder aufgehört hat. Der Ort
 * kommt mit der Unterschrift der Seite (wie bei der Live-Aktualisierung) — wer sie nicht hat, durfte die Seite nicht
 * lesen. Der Name kommt aus dem eigenen Profil, nie aus der Anfrage.
 */
final class TypingController
{
    public function __construct(private readonly Typing $typing, private readonly Live $live, private readonly Profiles $profiles, private readonly CurrentSite $sites) {}

    public function __invoke(Request $request): Response
    {
        $customer = CustomerAuth::current();
        abort_if($customer === null, 403);
        $data = $request->validate(['key' => ['required', 'string', 'max:80'], 'token' => ['required', 'string', 'size:32'], 'aktiv' => ['required', 'boolean']]);
        $site = $this->sites->require();
        $key = (string) $data['key'];
        abort_unless(Typing::isKey($key) && $this->live->valid($site->id, $key, (string) $data['token']), 403);
        $profile = $this->profiles->of($customer);
        // Ohne Profil oder mit Schreibsperre tippt man nicht sichtbar
        if ($profile !== null && ! app(Sanctions::class)->isMuted($customer)) {
            $this->typing->update($site->id, $key, $profile, (bool) $data['aktiv']);
        }

        return response()->noContent();
    }
}
