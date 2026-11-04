<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Backend\Backend;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Backend\Schema\Action;
use Basalto\Core\Backend\Schema\AdminPage;
use Basalto\Core\Backend\Schema\Column;
use Basalto\Core\Backend\Schema\Filter;
use Basalto\Core\Backend\Schema\Form;
use Basalto\Core\Backend\Schema\FormField;
use Basalto\Core\Backend\Schema\Row;
use Basalto\Core\Backend\Schema\Stat;
use Basalto\Core\Backend\Schema\Table;
use Basalto\Core\Payments\CheckoutGateway;
use Basalto\Core\Payments\Payment;
use Basalto\Core\Payments\PaymentException;
use Basalto\Core\Payments\PaymentSetting;
use Basalto\Core\Payments\SitePayments;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Verwaltung › Zahlungen (CR-018, TB 103): das eigene Stripe-Konto der Site für Module und Plugins (Anzahlung einer
 * Reservation, Kauf eines Gutscheins) und die Liste der Zahlungen. Der geheime Schlüssel geht nie zurück an den
 * Browser; leer gelassen bleibt der gespeicherte. Das Geld geht direkt an den Betreiber.
 */
final class PaymentSettingsController
{
    public const CURRENCIES = ['CHF', 'EUR'];

    private const STATES = [Payment::OPEN, Payment::PAID, Payment::EXPIRED];

    public function __construct(private readonly SitePayments $payments, private readonly CurrentSite $current, private readonly Dates $dates, private readonly Recorder $recorder) {}

    public function index(Request $request): AdminPage
    {
        $site = $this->current->require();
        $setting = $this->payments->setting($site);
        $configured = $this->payments->configured($site);
        $state = in_array($request->query('stand'), self::STATES, true) ? (string) $request->query('stand') : null;
        $counts = [];
        foreach (self::STATES as $status) {
            $counts[$status] = $this->query($site->id)->where('status', $status)->count();
        }
        $paid = $this->query($site->id);
        $paid->where('status', Payment::PAID);
        $paid->where('paid_at', '>=', now()->subDays(30));
        $sum = (int) $paid->sum('amount');
        $list = $this->query($site->id);
        if ($state !== null) {
            $list->where('status', $state);
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $list->where(static fn ($query) => $query->whereLike('description', '%'.$q.'%')->orWhereLike('email', '%'.$q.'%')->orWhereLike('reference', '%'.$q.'%'));
        }
        $items = $list->orderByDesc('id')->paginate(50)->withQueryString();
        $currency = $setting->currency ?? 'CHF';
        // Herkunft mit dem Namen des Moduls bzw. Plugins statt seiner Kennung
        $modules = [];
        foreach (app(Backend::class)->modules() as $module) {
            $modules[(string) $module['handle']] = (string) __((string) $module['label']);
        }

        $page = AdminPage::make(__('Zahlungen'))
            ->crumb(__('Verwaltung › Zahlungen'))
            ->lead(__('Zahlungen auf dieser Site über Ihr eigenes Stripe-Konto — für Plugins wie Reservierungen (Anzahlung) oder Gutscheine. Das Geld geht direkt auf Ihr Konto; welche Zahlungsarten (Karte, TWINT …) angeboten werden, stellen Sie im Stripe-Dashboard ein.'))
            ->stats([
                Stat::make(__('Stripe-Konto'), $configured ? (string) ($setting->account_name ?? 'Stripe') : '—')
                    ->tone($configured ? ($setting->live ? 'success' : 'warning') : 'neutral')
                    ->hint($configured ? ($setting->live ? __('echte Zahlungen') : __('Testmodus')) : __('nicht eingerichtet')),
                Stat::make(__('Bezahlt in 30 Tagen'), $currency.' '.number_format($sum / 100, 2, '.', "'")),
                Stat::make(__('Offen'), $counts[Payment::OPEN]),
            ]);
        if (! $configured) {
            $page->notice(__('Tragen Sie den geheimen Schlüssel Ihres Stripe-Kontos ein (Stripe-Dashboard › Entwickler › API-Schlüssel). Ein eingeschränkter Schlüssel mit Schreibrecht auf «Checkout Sessions» genügt.'), 'grey', __('Noch nicht eingerichtet'));
        } elseif (! $setting->live) {
            $page->notice(__('Mit einem Testschlüssel fliesst kein Geld — zum Ausprobieren mit den Testkarten von Stripe.'), 'warning', __('Testmodus'));
        }
        if ($configured) {
            $page->action(Action::delete(__('Schlüssel entfernen'), route('basalto.payments.destroy'))
                ->confirm(__('Den Stripe-Schlüssel entfernen? Neue Zahlungen gehen dann nicht mehr; bestehende bleiben in der Liste.')));
        }
        $page->form(Form::make(route('basalto.payments.update'), 'put')->title(__('Stripe-Konto'))->submit(__('Speichern'))->fields([
            FormField::password('secret_key', __('Geheimer Schlüssel'))->required(! $configured)->mono()
                ->placeholder($configured ? __('gespeichert — leer lassen, um ihn zu behalten') : 'sk_live_…')
                ->help(__('Beginnt mit sk_live_ bzw. rk_live_ (echte Zahlungen) oder sk_test_ bzw. rk_test_ (Testmodus). Er bleibt verschlüsselt auf dem Server.')),
            FormField::select('currency', __('Währung'), array_combine(self::CURRENCIES, self::CURRENCIES))->half(),
        ])->values(['secret_key' => '', 'currency' => $currency]));

        $table = Table::make('zahlungen')->title(__('Zahlungen'))
            ->columns([Column::text('description', __('Beschreibung')), Column::date('date', __('Datum')), Column::text('module', __('Herkunft')),
                Column::text('email', __('E-Mail')), Column::money('amount', __('Betrag')), Column::badge('status', __('Stand'))])
            ->search($q, __('Beschreibung, E-Mail oder Bezug'))
            ->filter(Filter::make('stand', __('Stand'), $state)
                ->option('', __('Alle'))
                ->option(Payment::PAID, __('Bezahlt'), $counts[Payment::PAID])
                ->option(Payment::OPEN, __('Offen'), $counts[Payment::OPEN])
                ->option(Payment::EXPIRED, __('Abgelaufen'), $counts[Payment::EXPIRED]))
            ->rows($items, fn (Payment $payment): Row => Row::make($payment->id)
                ->cell('date', $this->dates->dateTime($payment->paid_at ?? $payment->created_at))
                ->cell('description', $payment->description)
                ->cell('module', $modules[$payment->module] ?? $payment->module)
                ->cell('email', $payment->email ?? '—')
                ->cell('amount', $payment->amountLabel())
                ->badge('status', match ($payment->status) {
                    Payment::PAID => $payment->live ? __('Bezahlt') : __('Bezahlt (Test)'),
                    Payment::EXPIRED => __('Abgelaufen'),
                    default => __('Offen'),
                }, match ($payment->status) {
                    Payment::PAID => 'success',
                    Payment::EXPIRED => 'neutral',
                    default => 'warning',
                }))
            ->paginate($items)
            ->empty(__('Noch keine Zahlungen'), __('Zahlungen entstehen in Plugins, etwa bei einer Reservation mit Anzahlung.'));

        return $page->table($table);
    }

    public function update(Request $request, CheckoutGateway $gateway): RedirectResponse
    {
        $site = $this->current->require();
        $setting = $this->payments->setting($site);
        $configured = $this->payments->configured($site);
        $validated = $request->validate([
            'secret_key' => [$configured ? 'nullable' : 'required', 'string', 'max:255', 'regex:/^(sk|rk)_(live|test)_[A-Za-z0-9]+$/'],
            'currency' => ['required', Rule::in(self::CURRENCIES)],
        ], ['secret_key.regex' => __('Das ist kein geheimer Schlüssel von Stripe (sk_… oder rk_…). Der öffentliche Schlüssel (pk_…) genügt nicht.')],
            ['secret_key' => __('Geheimer Schlüssel'), 'currency' => __('Währung')]);
        $secret = is_string($validated['secret_key'] ?? null) ? trim($validated['secret_key']) : '';
        $setting ??= new PaymentSetting(['provider' => 'stripe']);
        $setting->site_id = $site->id;
        $before = $setting->exists ? $this->audit($setting) : null;
        if ($secret !== '') {
            try {
                $account = $gateway->account($secret);
            } catch (PaymentException $e) {
                return back()->withErrors(['secret_key' => $e->getMessage()]);
            }
            $setting->secret_key = $secret;
            $setting->account_name = mb_substr($account['name'], 0, 190);
            $setting->live = $account['live'];
        }
        $setting->currency = (string) $validated['currency'];
        $setting->updated_by = (int) $request->user()?->getAuthIdentifier() ?: null;
        $setting->save();
        $this->recorder->record('payments.updated', $setting, $before, [...$this->audit($setting), 'secret_changed' => $secret !== ''], $site);

        return back()->with('status', $secret !== ''
            ? ($setting->live ? __('Stripe-Konto «:name» verbunden — Zahlungen gehen ab jetzt auf dieses Konto.', ['name' => $setting->account_name]) : __('Stripe-Konto «:name» im Testmodus verbunden.', ['name' => $setting->account_name]))
            : __('Gespeichert.'));
    }

    public function destroy(): RedirectResponse
    {
        $site = $this->current->require();
        $setting = $this->payments->setting($site);
        if ($setting !== null) {
            $this->recorder->record('payments.removed', $setting, $this->audit($setting), null, $site);
            $setting->delete();
        }

        return back()->with('status', __('Stripe-Schlüssel entfernt — neue Zahlungen gehen erst wieder mit einem neuen Schlüssel.'));
    }

    /** @return Builder<Payment> */
    private function query(int $siteId): Builder
    {
        $query = Payment::query()->withoutGlobalScope(SiteScope::class);
        $query->where('site_id', $siteId);

        return $query;
    }

    /** @return array<string, mixed> Ohne Schlüssel — nur ob einer gespeichert ist */
    private function audit(PaymentSetting $setting): array
    {
        return ['account' => $setting->account_name, 'live' => $setting->live, 'currency' => $setting->currency, 'has_key' => is_string($setting->secret_key) && $setting->secret_key !== ''];
    }
}
