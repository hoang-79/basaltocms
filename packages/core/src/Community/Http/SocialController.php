<?php

declare(strict_types=1);

namespace Basalto\Core\Community\Http;

use Basalto\Core\Community\Censor;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\Conversation;
use Basalto\Core\Community\Conversations;
use Basalto\Core\Community\MessageBody;
use Basalto\Core\Community\Profiles;
use Basalto\Core\Community\Relations;
use Basalto\Core\Community\Sanctions;
use Basalto\Core\Content\RichText;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Folgen, Blockieren und Konversationen aus dem Frontend (CR-006 FO-18, FO-19) — angemeldet, mit Profil, ohne Skript
 * bedienbar. Wer eine Schreibsperre hat, beginnt keine Konversation und antwortet nicht. Rückmeldungen gehen in die
 * Sitzung (`community.status`), Fehler in die Tasche `forum`.
 */
final class SocialController
{
    public function __construct(private readonly Relations $relations, private readonly Conversations $conversations, private readonly Profiles $profiles) {}

    public function follow(Request $request): RedirectResponse
    {
        [$customer, , $lang, $back] = $this->context($request);
        $other = $this->other($customer, $request->input('konto'));
        $request->input('modus') === 'entfolgen' ? $this->relations->unfollow($customer, $other) : $this->relations->follow($customer, $other);

        return redirect($back)->with(ReportController::STATUS, $request->input('modus') === 'entfolgen'
            ? __('Sie folgen :name nicht mehr.', ['name' => $this->profiles->displayName($other)], $lang)
            : __('Sie folgen jetzt :name.', ['name' => $this->profiles->displayName($other)], $lang));
    }

    public function block(Request $request): RedirectResponse
    {
        [$customer, , $lang, $back] = $this->context($request);
        $other = $this->other($customer, $request->input('konto'));
        abort_if(CommunityProfile::query()->withoutGlobalScopes()->where('customer_id', $other->id)->whereNotNull('user_id')->exists() && $request->input('modus') !== 'freigeben', 403);
        $request->input('modus') === 'freigeben' ? $this->relations->unblock($customer, $other) : $this->relations->block($customer, $other);

        return redirect($back)->with(ReportController::STATUS, $request->input('modus') === 'freigeben'
            ? __(':name ist nicht mehr blockiert.', ['name' => $this->profiles->displayName($other)], $lang)
            : __(':name ist blockiert: Beiträge sind zugeklappt, Nachrichten kommen nicht mehr an.', ['name' => $this->profiles->displayName($other)], $lang));
    }

    /** Eine Konversation beginnen: Empfänger als Anzeigenamen, mit Komma getrennt. */
    public function start(Request $request): RedirectResponse
    {
        [$customer, $site, $lang, $back] = $this->context($request, true);
        if (filled($request->input('website'))) {
            return redirect($back);
        }
        $body = MessageBody::fromRequest($request);
        $names = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('an', '')))));
        $recipients = $this->recipients($site, $customer, $names);
        $validator = Validator::make(['betreff' => $request->input('betreff'), 'body' => $body === null ? null : 'ok', 'an' => $names === [] ? null : 'ok'],
            ['betreff' => ['required', 'string', 'min:3', 'max:160'], 'body' => ['required'], 'an' => ['required']], [], ['betreff' => __('Betreff', [], $lang), 'body' => __('Nachricht', [], $lang), 'an' => __('An', [], $lang)]);
        $validator->after(function (ValidatorInstance $v) use ($names, $recipients, $customer, $body, $site, $lang, $request): void {
            $missing = array_diff(array_map(Profiles::key(...), $names), array_map(static fn (Customer $c): string => Profiles::key(app(Profiles::class)->displayName($c)), $recipients));
            if ($names !== [] && $missing !== []) {
                $v->errors()->add('an', __('Diese Namen gibt es nicht: :names', ['names' => implode(', ', $missing)], $lang));
            }
            if (count($recipients) >= Conversations::MAX_PARTICIPANTS) {
                $v->errors()->add('an', __('Höchstens :max Teilnehmer.', ['max' => Conversations::MAX_PARTICIPANTS - 1], $lang));
            }
            $refusing = $this->conversations->refusing($customer, $recipients);
            if ($refusing !== []) {
                $v->errors()->add('an', __(':names möchte keine Nachrichten von Ihnen.', ['names' => implode(', ', array_map(fn (Customer $c): string => $this->profiles->displayName($c), $refusing))], $lang));
            }
            $this->checkText($v, $site, $body, $lang, (string) $request->input('betreff', ''));
        });
        if ($validator->fails() || $body === null) {
            return redirect($back)->withErrors($validator, 'forum')->withInput();
        }
        $conversation = $this->conversations->start($site, $customer, $recipients, (string) $request->input('betreff'), $body);

        return redirect($this->base($request).'/konversationen/'.$conversation->id);
    }

    public function reply(Request $request, int $conversation): RedirectResponse
    {
        [$customer, $site, $lang, $back] = $this->context($request, true);
        $model = $this->conversation($site, $customer, $conversation);
        $body = MessageBody::fromRequest($request);
        $validator = Validator::make(['body' => $body === null ? null : 'ok'], ['body' => ['required']], [], ['body' => __('Nachricht', [], $lang)]);
        $validator->after(fn (ValidatorInstance $v) => $this->checkText($v, $site, $body, $lang));
        if ($validator->fails() || $body === null) {
            return redirect($back.'#antworten')->withErrors($validator, 'forum')->withInput();
        }
        $message = $this->conversations->reply($site, $model, $customer, $body);

        return redirect($back.'#nachricht-'.$message->id);
    }

    public function invite(Request $request, int $conversation): RedirectResponse
    {
        [$customer, $site, $lang, $back] = $this->context($request, true);
        $model = $this->conversation($site, $customer, $conversation);
        $names = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('an', '')))));
        $recipients = $this->conversations->refusing($customer, $this->recipients($site, $customer, $names)) === [] ? $this->recipients($site, $customer, $names) : [];
        if ($recipients === []) {
            return redirect($back)->withErrors(['an' => __('Niemand eingeladen — Namen prüfen; wer Sie blockiert hat, lässt sich nicht einladen.', [], $lang)], 'forum');
        }
        $added = $this->conversations->invite($site, $model, $customer, $recipients);

        return redirect($back)->with(ReportController::STATUS, trans_choice(':count Person eingeladen.|:count Personen eingeladen.', $added, ['count' => $added], $lang));
    }

    public function leave(Request $request, int $conversation): RedirectResponse
    {
        [$customer, $site, $lang] = $this->context($request);
        $this->conversations->leave($this->conversation($site, $customer, $conversation), $customer);

        return redirect($this->base($request).'/konversationen')->with(ReportController::STATUS, __('Sie haben die Konversation verlassen.', [], $lang));
    }

    /** @param  array<string, mixed>|null  $body */
    private function checkText(ValidatorInstance $v, Site $site, ?array $body, string $lang, string ...$texts): void
    {
        if (MessageBody::length($body) > MessageBody::MAX_TEXT) {
            $v->errors()->add('body', __('Die Nachricht ist zu lang (höchstens :max Zeichen).', ['max' => MessageBody::MAX_TEXT], $lang));
        }
        $censor = app(Censor::class)->error($site, $lang, $body === null ? '' : RichText::toText($body), ...$texts);
        if ($censor !== null) {
            $v->errors()->add('body', $censor);
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<Customer>
     */
    private function recipients(Site $site, Customer $from, array $names): array
    {
        if ($names === []) {
            return [];
        }
        $ids = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereIn('name_key', array_map(Profiles::key(...), $names))
            ->where('customer_id', '!=', $from->id)->pluck('customer_id');

        return array_values(Customer::query()->withoutGlobalScopes()->whereIn('id', $ids)->get()->all());
    }

    private function conversation(Site $site, Customer $customer, int $id): Conversation
    {
        $model = Conversation::query()->withoutGlobalScopes()->where('site_id', $site->id)->find($id);
        abort_if($model === null || ! $this->conversations->canRead($model, $customer), 404);

        return $model;
    }

    private function other(Customer $customer, mixed $id): Customer
    {
        $other = Customer::query()->withoutGlobalScopes()->where('site_id', $customer->site_id)->find(is_numeric($id) ? (int) $id : 0);
        abort_if($other === null || $other->id === $customer->id, 404);

        return $other;
    }

    /** @return array{0: Customer, 1: Site, 2: string, 3: string} */
    private function context(Request $request, bool $writes = false): array
    {
        $site = app(CurrentSite::class)->get();
        $customer = CustomerAuth::current();
        abort_if($site === null || $customer === null || $this->profiles->of($customer) === null, 403);
        abort_if($writes && app(Sanctions::class)->isMuted($customer), 403);
        $lang = is_string($request->input('_sprache')) && in_array($request->input('_sprache'), $site->localeCodes(), true) ? (string) $request->input('_sprache') : $site->default_locale;
        app()->setLocale($lang);

        return [$customer, $site, $lang, Html::pagePath($request->input('_seite'))];
    }

    private function base(Request $request): string
    {
        $base = Html::pagePath($request->input('_basis'));

        return $base === '/' ? '' : rtrim($base, '/');
    }
}
