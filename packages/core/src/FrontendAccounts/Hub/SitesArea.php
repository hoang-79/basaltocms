<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Hub;

use Basalto\Core\Access\Access;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Saas\Plan;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Saas\Trial;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Bereich «Sites» im Kundenkonto (TB 48) — die Verbindung zum CMS: Gehört die E-Mail des Kontos auch einem Benutzer
 * des Backends, stehen hier dessen Sites in der Cloud (Plan, Testphase, öffnen, Backend) und, wenn er diese Site
 * mitbetreut, der Weg ins Backend. Die E-Mail ist durch die Anmeldung per Code bestätigt. Ohne beides, aber mit offener
 * Registrierung, wirbt der Bereich für eine eigene Site.
 */
final class SitesArea implements AccountArea
{
    private bool $backend = false;

    /** @var list<array{site: Site, account: Account}> */
    private array $cloud = [];

    public function key(): string
    {
        return 'sites';
    }

    public function position(): int
    {
        return 10;
    }

    public function pitch(AccountPage $page): ?AreaPitch
    {
        return Saas::enabled() ? new AreaPitch('globe', $page->t('Ihre Sites'), $page->t('Sites in der Cloud öffnen, ins Backend springen, Plan und Testphase im Blick.')) : null;
    }

    public function summary(AccountPage $page): ?AreaSummary
    {
        $customer = $page->customer;
        if ($customer === null) {
            return null;
        }
        $user = self::user($customer->email);
        $this->backend = $user instanceof Authenticatable && app(Access::class)->definition($user, $page->site) !== null;
        $this->cloud = $user === null || ! Saas::enabled() ? [] : self::cloudSites($user);
        $start = $page->setting('start');
        $invite = Saas::registrationOpen() && $start !== '';
        if (! $this->backend && $this->cloud === [] && ! $invite) {
            return null;
        }
        $todos = [];
        $events = [];
        foreach ($this->cloud as ['site' => $site, 'account' => $account]) {
            $days = Trial::daysLeft($account);
            if (Trial::inTrial($account) && $days <= 7) {
                $todos[] = new AreaTodo('clock', $days > 0 ? trans_choice('Die Testphase von «:site» endet in :count Tag|Die Testphase von «:site» endet in :count Tagen', $days, ['site' => $site->name, 'count' => $days], $page->lang)
                    : $page->t('Die Testphase von «:site» ist abgelaufen', ['site' => $site->name]), new AreaAction($page->t('Plan wählen'), $site->publicUrl(self::backendPath('konto/abo')), 'arrow'), 'warn');
            }
            $created = $site->getAttribute('created_at');
            if ($created instanceof \DateTimeInterface) {
                $events[] = new AreaEvent($created, 'globe', $page->t('Site «:site» angelegt', ['site' => $site->name]), $site->publicUrl('/'));
            }
        }
        $actions = [];
        if ($this->backend) {
            $actions[] = new AreaAction($page->t('Ins Backend'), '/'.self::backendPath(), 'layout');
        }
        if ($invite) {
            $actions[] = new AreaAction($page->t('Neue Site'), $start, 'plus', $this->cloud === [] && ! $this->backend);
        }
        $count = count($this->cloud) + ($this->backend ? 1 : 0);

        return new AreaSummary($page->t('Sites'), 'globe', $count > 0 ? $count : null, $count === 0 ? '' : trans_choice(':count Site|:count Sites', $count, ['count' => $count], $page->lang),
            $todos === [] ? '' : 'warn', $todos, $events, $actions, $invite && $count > 0 ? [new AreaAction($page->t('Neue Site'), $start, 'plus')] : [], [], true);
    }

    public function render(AccountPage $page, AreaSummary $summary): string
    {
        $rows = '';
        if ($this->backend) {
            $host = (string) parse_url($page->site->publicUrl('/'), PHP_URL_HOST);
            $rows .= sprintf('<li class="konto-zeile"><span class="konto-sechseck konto-sechseck--dunkel" aria-hidden="true">%s</span><div class="konto-zeile__text"><b>%s</b><small>%s · %s</small></div>'
                .'<span class="konto-chip konto-chip--dunkel">%s</span><div class="konto-knoepfe"><a class="konto-knopf konto-knopf--dunkel" href="/%s">%s %s</a></div></li>',
                Icons::svg('layout'), Html::e($page->site->name), Html::e($host), Html::e($page->t('Sie arbeiten im Backend dieser Site mit')), Html::e($page->t('diese Site')),
                Html::e(self::backendPath()), Icons::mini('layout'), Html::e($page->t('Backend öffnen')));
        }
        foreach ($this->cloud as ['site' => $site, 'account' => $account]) {
            $plan = Plan::find($account->plan);
            $days = Trial::daysLeft($account);
            [$chip, $tone] = match (true) {
                Trial::inTrial($account) && $days > 0 => [trans_choice('Testphase · noch :count Tag|Testphase · noch :count Tage', $days, ['count' => $days], $page->lang), $days <= 7 ? 'warn' : ''],
                Trial::inTrial($account) => [$page->t('Testphase abgelaufen'), 'fehler'],
                $account->cancelled_at !== null => [$page->t('gekündigt'), 'warn'],
                default => [$page->t('aktiv'), 'ok'],
            };
            $rows .= sprintf('<li class="konto-zeile"><span class="konto-sechseck konto-sechseck--blau" aria-hidden="true">%s</span><div class="konto-zeile__text"><a href="%s">%s</a><small>%s · %s</small></div>'
                .'<span class="konto-chip%s">%s</span><div class="konto-knoepfe"><a class="konto-knopf" href="%2$s">%s %s</a><a class="konto-knopf konto-knopf--dunkel" href="%s">%s %s</a></div></li>',
                Icons::svg('globe'), Html::e($site->publicUrl('/')), Html::e($site->name), Html::e((string) parse_url($site->publicUrl('/'), PHP_URL_HOST)),
                Html::e($page->t('Cloud :plan', ['plan' => $plan?->label() ?? $account->plan])), $tone === '' ? '' : ' konto-chip--'.$tone, Html::e($chip),
                Icons::mini('external'), Html::e($page->t('Öffnen')), Html::e($site->publicUrl(self::backendPath())), Icons::mini('layout'), Html::e($page->t('Ins Backend')));
        }
        $html = $rows === '' ? '' : '<ul class="konto-zeilen">'.$rows.'</ul>';
        if ($this->cloud === [] && Saas::registrationOpen() && $page->setting('start') !== '') {
            $html .= sprintf('<div class="konto-leer"><span class="konto-sechseck konto-sechseck--lava" aria-hidden="true">%s</span><p>%s</p><a class="konto-knopf konto-knopf--lava" href="%s">%s %s</a></div>',
                Icons::svg('rocket'), Html::e($page->t('Ihre eigene Site mit Basalto: :days Tage kostenlos testen, ohne Karte — mit derselben E-Mail-Adresse erscheint sie danach hier.', ['days' => Saas::trialDays()])),
                Html::e($page->setting('start')), Icons::mini('plus'), Html::e($page->t('Neue Site')));
        }

        return $html;
    }

    /** Der Benutzer des Backends mit dieser E-Mail — oder null. */
    private static function user(string $email): ?Model
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $model::query()->where('email', mb_strtolower(trim($email)))->first();
    }

    /**
     * Sites der SaaS-Konten, in denen der Benutzer Mitglied ist (nicht das Konto der Installation, nichts Gelöschtes).
     *
     * @return list<array{site: Site, account: Account}>
     */
    private static function cloudSites(Model $user): array
    {
        $ids = DB::table('account_user')->where('user_id', $user->getKey())->pluck('account_id')->all();
        $out = [];
        foreach (Account::query()->whereIn('id', $ids)->whereNull('erased_at')->orderBy('id')->get() as $account) {
            if (! Saas::isCustomer($account)) {
                continue;
            }
            foreach ($account->sites()->withoutGlobalScopes()->orderBy('id')->get() as $site) {
                $out[] = ['site' => $site, 'account' => $account];
            }
        }

        return $out;
    }

    private static function backendPath(string $path = ''): string
    {
        return trim((string) config('basalto.backend.path', 'admin'), '/').($path === '' ? '' : '/'.$path);
    }
}
