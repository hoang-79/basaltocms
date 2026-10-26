<?php

declare(strict_types=1);

namespace Basalto\Core\Community\Http;

use Basalto\Core\Community\Censor;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityWallPost;
use Basalto\Core\Community\MessageBody;
use Basalto\Core\Community\Wall;
use Basalto\Core\Content\RichText;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Pinnwand (TB 52): einen Eintrag schreiben (Text der Editor-Insel, Zensur wie in Beiträgen) oder löschen. */
final class WallController
{
    public function __construct(private readonly Wall $wall, private readonly CurrentSite $sites) {}

    public function store(Request $request, int $profile): RedirectResponse
    {
        $site = $this->sites->require();
        $author = CustomerAuth::current();
        $owner = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->find($profile);
        $ownerCustomer = $owner?->customer_id === null ? null : Customer::query()->withoutGlobalScopes()->find($owner->customer_id);
        abort_if($owner === null || $ownerCustomer === null, 404);
        abort_unless($this->wall->canWrite($site, $author, $ownerCustomer), 403);
        $lang = is_string($request->input('_sprache')) && in_array($request->input('_sprache'), $site->localeCodes(), true) ? (string) $request->input('_sprache') : $site->default_locale;
        $back = Html::pagePath($request->input('_seite')).'?reiter=pinnwand';
        $body = MessageBody::fromRequest($request);
        if ($body === null) {
            return redirect($back.'#pinnwand-schreiben')->withErrors(['body' => __('Bitte etwas schreiben.', [], $lang)], 'forum')->withInput();
        }
        $error = MessageBody::length($body) > 2000 ? __('Der Eintrag ist zu lang (höchstens :max Zeichen).', ['max' => 2000], $lang)
            : app(Censor::class)->error($site, $lang, RichText::toText($body));
        if ($error !== null) {
            return redirect($back.'#pinnwand-schreiben')->withErrors(['body' => $error], 'forum')->withInput();
        }
        $post = $this->wall->post($site, $author, $owner, $body);

        return redirect($back.'#pinnwand-'.$post->id);
    }

    public function destroy(Request $request, int $post): RedirectResponse
    {
        $site = $this->sites->require();
        $entry = CommunityWallPost::query()->withoutGlobalScopes()->where('site_id', $site->id)->find($post);
        $owner = $entry === null ? null : CommunityProfile::query()->withoutGlobalScopes()->find($entry->profile_id);
        $ownerCustomer = $owner?->customer_id === null ? null : Customer::query()->withoutGlobalScopes()->find($owner->customer_id);
        abort_if($entry === null || $ownerCustomer === null, 404);
        abort_unless($this->wall->canDelete($site, CustomerAuth::current(), $entry, $ownerCustomer), 403);
        $entry->delete();

        return redirect(Html::pagePath($request->input('_seite')).'?reiter=pinnwand#pinnwand');
    }
}
