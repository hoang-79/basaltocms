<?php

declare(strict_types=1);

namespace Basalto\Core\Community\Http;

use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityRichText;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vorschau der Editor-Insel (TB 49): das Dokument so, wie es nach dem Absenden erscheint — gesäubert mit derselben
 * Positivliste, Erwähnungen mit dem heutigen Namen. Gespeichert wird nichts.
 */
final class PreviewController
{
    public function __invoke(Request $request, CurrentSite $sites): JsonResponse
    {
        abort_if(CustomerAuth::current() === null, 403);
        $request->validate(['doc' => ['required', 'string', 'max:400000'], '_sprache' => ['nullable', 'string', 'max:5']]);
        $site = $sites->require();
        $doc = CommunityRichText::clean(json_decode((string) $request->input('doc'), true));
        $mentioned = CommunityRichText::mentions($doc);
        $names = $mentioned === [] ? [] : CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereIn('id', $mentioned)->pluck('name', 'id')->all();
        $lang = in_array($request->input('_sprache'), $site->localeCodes(), true) ? (string) $request->input('_sprache') : $site->default_locale;

        return response()->json(['html' => CommunityRichText::toHtml($doc, $lang, $names, static fn (int $id): string => '#beitrag-'.$id)]);
    }
}
