<?php

declare(strict_types=1);

namespace Basalto\Core\Community\Http;

use Basalto\Core\Community\CommunityNotice;
use Basalto\Core\Community\CommunityReport;
use Basalto\Core\Community\Notices;
use Basalto\Core\Community\Reports;
use Basalto\Core\Community\Subjects;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Melden aus dem Frontend (CR-006 FO-14) für jeden angemeldeten Inhaltstyp — angemeldet, nur was die Person lesen
 * darf, nie den eigenen Inhalt, je Person einmal. Dazu: einen Hinweis ausblenden (FO-17).
 */
final class ReportController
{
    /** Die Rückmeldung nach dem Melden (Sitzung) — das Forum zeigt sie oben. */
    public const STATUS = 'community.status';

    public function __construct(private readonly Reports $reports, private readonly Subjects $subjects) {}

    public function store(Request $request): RedirectResponse
    {
        $customer = CustomerAuth::current();
        $site = app(CurrentSite::class)->get();
        abort_if($customer === null || $site === null, 403);
        $lang = is_string($request->input('_sprache')) && in_array($request->input('_sprache'), $site->localeCodes(), true) ? (string) $request->input('_sprache') : $site->default_locale;
        $data = $request->validate(['subject' => ['required', 'string', 'max:40'], 'id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'in:'.implode(',', array_keys(CommunityReport::REASONS))], 'note' => ['nullable', 'string', 'max:500']]);
        $type = (string) $data['subject'];
        $id = (int) $data['id'];
        abort_unless($this->reports->has($type) && $this->subjects->known($type) && $this->subjects->readable($type, $id, $customer), 404);
        abort_if($this->subjects->owner($type, $id) === $customer->id, 403);
        $back = Html::pagePath($request->input('_seite')).(is_string($request->input('_anker')) && preg_match('/^[a-z0-9-]{1,40}$/', $request->input('_anker')) === 1 ? '#'.$request->input('_anker') : '');
        if (filled($request->input('website'))) {
            return redirect($back);
        }
        $report = $this->reports->file($site->id, $type, $id, (string) $data['reason'], isset($data['note']) ? (string) $data['note'] : null, $customer, $request->ip());

        return redirect($back)->with(self::STATUS, $report !== null
            ? __('Danke — die Moderation sieht sich den Beitrag an. Wer geschrieben hat, erfährt nicht, wer gemeldet hat.', [], $lang)
            : __('Sie haben diesen Beitrag schon gemeldet.', [], $lang));
    }

    public function dismissNotice(Request $request, int $notice): RedirectResponse
    {
        $customer = CustomerAuth::current();
        $site = app(CurrentSite::class)->get();
        abort_if($customer === null || $site === null, 403);
        $model = CommunityNotice::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('dismissible', true)->findOrFail($notice);
        app(Notices::class)->dismiss($customer, $model);

        return redirect(Html::pagePath($request->input('_seite')));
    }
}
