<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Http\Controllers;

use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\Http\ReportController;
use Basalto\Core\Community\Moderator;
use Basalto\Core\Community\Warnings;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Modules\Forum\Actions\Moderation;
use Basalto\Modules\Forum\Actions\ModerationRequests;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Thread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Moderation an Ort und Stelle (CR-006 FO-15): moderierende Kundenkonten bedienen dieselben Aktionen wie das Team im
 * Extranet — je Forum mit dem Recht «Moderieren». Verwarnen und «Als Spammer entfernen» brauchen das Recht in
 * mindestens einem Forum; das Team (verknüpfte Konten) lässt sich so nicht entfernen.
 */
final class ModerationController
{
    public function __construct(private readonly Forum $forum, private readonly ModerationRequests $requests, private readonly Moderation $moderation) {}

    public function threads(Request $request): RedirectResponse
    {
        [$site, $customer, $lang, $back] = $this->context($request);
        $result = $this->requests->threads($site, Moderator::member($customer), $request, fn (Node $n): bool => $this->forum->canModerate($site, $customer, $n), $lang);

        return $this->answer($request, $result, $back);
    }

    public function posts(Request $request): RedirectResponse
    {
        [$site, $customer, $lang, $back] = $this->context($request);
        $result = $this->requests->posts($site, Moderator::member($customer), $request, fn (Node $n): bool => $this->forum->canModerate($site, $customer, $n), $lang);

        return $this->answer($request, $result, $back);
    }

    public function report(Request $request): RedirectResponse
    {
        [$site, $customer, $lang, $back] = $this->context($request);
        $result = $this->requests->report($site, Moderator::member($customer), $request, fn (Node $n): bool => $this->forum->canModerate($site, $customer, $n), $lang);

        return redirect($back)->with(ReportController::STATUS, $result['message']);
    }

    /** Verwarnen (FO-17) — mit Grund, Punkten, Gültigkeit; auf Wunsch mit Bezug auf einen Beitrag. */
    public function warn(Request $request): RedirectResponse
    {
        [$site, $customer, $lang, $back] = $this->context($request);
        $target = $this->target($site, $customer, $request);
        $data = $request->validate(['grund' => ['required', 'string', 'min:3', 'max:200'], 'punkte' => ['required', 'integer', 'min:0', 'max:100'], 'tage' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'beitrag' => ['nullable', 'integer']]);
        $result = app(Warnings::class)->warn($site, $target, Moderator::member($customer), (string) $data['grund'], (int) $data['punkte'],
            isset($data['tage']) ? (int) $data['tage'] : null, null, isset($data['beitrag']) ? Forum::ATTACHMENTS : null, isset($data['beitrag']) ? (int) $data['beitrag'] : null);
        $message = match ($result['consequence']) {
            'banned' => __('Verwarnt — die Punkte reichen für eine Sperre.', [], $lang),
            'muted' => __('Verwarnt — die Punkte reichen für eine Schreibsperre.', [], $lang),
            default => __('Verwarnt.', [], $lang),
        };

        return redirect($back)->with(ReportController::STATUS, $message);
    }

    /** «Als Spammer entfernen» (FO-17): sperren, alles in den Papierkorb, Meldungen erledigt. */
    public function spammer(Request $request): RedirectResponse
    {
        [$site, $customer, $lang] = $this->context($request);
        $target = $this->target($site, $customer, $request);
        $result = $this->moderation->removeSpammer($site, Moderator::member($customer), $target);
        $base = Html::pagePath($request->input('_basis'));

        return redirect($base === '/' ? '/' : rtrim($base, '/'))->with(ReportController::STATUS, __('Entfernt: :threads Themen und :posts Beiträge liegen im Papierkorb, das Konto ist gesperrt.',
            ['threads' => $result['threads'], 'posts' => $result['posts']], $lang));
    }

    /** Wen es betrifft — ein Konto der Site, nicht man selbst und nicht das Team. */
    private function target(Site $site, Customer $customer, Request $request): Customer
    {
        abort_unless($this->forum->moderatesSomewhere($site, $customer), 403);
        $target = Customer::query()->withoutGlobalScopes()->where('site_id', $site->id)->find((int) $request->input('konto'));
        abort_if($target === null, 404);
        abort_if($target->id === $customer->id || CommunityProfile::query()->withoutGlobalScopes()->where('customer_id', $target->id)->whereNotNull('user_id')->exists(), 403);

        return $target;
    }

    /** @param  array{ok: bool, message: string, thread?: Thread|null}  $result */
    private function answer(Request $request, array $result, string $back): RedirectResponse
    {
        $thread = $result['thread'] ?? null;
        if ($thread instanceof Thread) {
            $base = Html::pagePath($request->input('_basis'));

            return redirect(($base === '/' ? '' : rtrim($base, '/')).'/thema/'.$thread->pathSegment())->with(ReportController::STATUS, $result['message']);
        }

        return redirect($back)->with(ReportController::STATUS, $result['message']);
    }

    /** @return array{0: Site, 1: Customer, 2: string, 3: string} */
    private function context(Request $request): array
    {
        $site = app(CurrentSite::class)->get();
        $customer = CustomerAuth::current();
        abort_if($site === null || $customer === null, 403);
        $lang = is_string($request->input('_sprache')) && in_array($request->input('_sprache'), $site->localeCodes(), true) ? (string) $request->input('_sprache') : $site->default_locale;
        app()->setLocale($lang);

        return [$site, $customer, $lang, Html::pagePath($request->input('_seite'))];
    }
}
