<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Backend\Dates;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\PasskeyUser;

/** Passkeys des angemeldeten Kontos: Liste und Registrierung über die Fortify-Endpunkte (laravel/passkeys). */
final class PasskeyController
{
    public function __construct(private readonly Dates $dates) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('core::Passkeys', [
            'passkeys' => $this->passkeysOf($request->user()),
            'routes' => ['options' => route('passkey.registration-options'), 'store' => route('passkey.store'), 'profile' => route('basalto.profile')],
            'supported' => true,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function passkeysOf(?Authenticatable $user): array
    {
        if (! $user instanceof PasskeyUser) {
            return [];
        }
        $list = [];
        foreach ($user->passkeys()->get() as $passkey) {
            $list[] = ['id' => $passkey->getKey(), 'name' => (string) $passkey->getAttribute('name'),
                'created_at' => $this->dates->dateTime($passkey->getAttribute('created_at')), 'last_used_at' => $this->dates->dateTime($passkey->getAttribute('last_used_at'))];
        }

        return $list;
    }
}
