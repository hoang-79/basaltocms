<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Http;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\CustomerLogin;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\FrontendAccounts\Notifications\AccountDeletedNotification;
use Basalto\Core\FrontendAccounts\Notifications\PrivacyRequestReceivedNotification;
use Basalto\Core\Legal\Cancellation\ContractSources;
use Basalto\Core\Models\PrivacyRequest;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\Notify;
use Basalto\Core\Privacy\PersonData;
use Basalto\Core\Sites\CurrentSite;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die Rechte nach DSGVO und DSG im Kundenkonto (CR-011 TB 61): Daten herunterladen, Angaben berichtigen, ein Anliegen
 * mit Frist stellen, das Konto löschen — jeweils nur für diese Site ({@see PersonData::exportForSite()}).
 */
final class PrivacyController
{
    private const BAG = 'datenschutz';

    public function __construct(private readonly CurrentSite $current, private readonly PersonData $data, private readonly Recorder $recorder) {}

    /** Art. 15 und 20 DSGVO: alles zur Person auf dieser Site als JSON. */
    public function export(Request $request): Response
    {
        [$site, $customer] = $this->context();
        $json = (string) json_encode($this->data->exportForSite($customer->email, $site), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->recorder->record('privacy.self_export', $customer, null, ['site' => $site->id], $site);
        $name = sprintf('daten-%s-%s.json', $site->handle, CarbonImmutable::now($site->timezone)->format('Y-m-d'));

        return response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'no-store']);
    }

    /** Art. 16 DSGVO: Name, Firma und Adresse selbst berichtigen. */
    public function update(Request $request): RedirectResponse
    {
        [$site, $customer, $lang, $back] = $this->context($request);
        $validator = Validator::make($request->all(), [
            'first_name' => ['nullable', 'string', 'max:80'], 'last_name' => ['nullable', 'string', 'max:80'], 'company' => ['nullable', 'string', 'max:160'], 'street' => ['nullable', 'string', 'max:160'],
            'postal_code' => ['nullable', 'string', 'max:20'], 'city' => ['nullable', 'string', 'max:120'], 'country' => ['nullable', 'string', 'size:2', 'alpha'],
        ], [], ['first_name' => __('Vorname', [], $lang), 'last_name' => __('Nachname', [], $lang), 'company' => __('Firma', [], $lang), 'street' => __('Strasse und Nummer', [], $lang), 'postal_code' => __('PLZ', [], $lang),
            'city' => __('Ort', [], $lang), 'country' => __('Land', [], $lang)]);
        if ($validator->fails()) {
            return redirect(Html::url($back, [], 'datenschutz-angaben'))->withErrors($validator, self::BAG)->withInput();
        }
        $values = array_map(static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null, $validator->validated());
        if (is_string($values['country'] ?? null)) {
            $values['country'] = strtoupper($values['country']);
        }
        $before = $customer->only(array_keys($values));
        $customer->forceFill($values)->save();
        $this->recorder->record('privacy.self_rectified', $customer, $before, $values, $site);

        return redirect(Html::url($back, [], 'datenschutz-angaben'))->with('account.status', __('Ihre Angaben sind gespeichert.', [], $lang));
    }

    /** Art. 18, 21 und 12 Abs. 3 DSGVO: ein Anliegen mit Frist von einem Monat — an das Team der Site. */
    public function request(Request $request): RedirectResponse
    {
        [$site, $customer, $lang, $back] = $this->context($request);
        $validator = Validator::make($request->all(), [
            'kind' => ['required', Rule::in(array_keys(PrivacyRequest::KINDS))],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ], [], ['kind' => __('Art', [], $lang), 'message' => __('Ihr Anliegen', [], $lang)]);
        if ($validator->fails()) {
            return redirect(Html::url($back, [], 'datenschutz-anliegen'))->withErrors($validator, self::BAG)->withInput();
        }
        $now = CarbonImmutable::now();
        $item = PrivacyRequest::query()->create(['site_id' => $site->id, 'customer_id' => $customer->id, 'email' => $customer->email, 'name' => $customer->name,
            'kind' => (string) $request->input('kind'), 'message' => trim((string) $request->input('message')), 'locale' => $lang, 'status' => PrivacyRequest::OPEN,
            'received_at' => $now, 'due_at' => $now->addMonth()]);
        $this->recorder->record('privacy.request_received', $item, null, ['kind' => $item->kind], $site);
        $customer->notify((new PrivacyRequestReceivedNotification($item->id))->locale($lang));
        Notify::make('privacy.requests')->site($site)->title('Anliegen zum Datenschutz: :kind', ['kind' => PrivacyRequest::kindLabel($item->kind, 'de')])
            ->body(':email — Antwort bis :date.', ['email' => $item->email, 'date' => $item->due_at->timezone($site->timezone)->format('d.m.Y')])
            ->url(route('basalto.privacy.requests.index'), 'Anliegen öffnen')->toArea($site, 'privacy')->send();

        return redirect(Html::url($back, [], 'datenschutz-anliegen'))
            ->with('account.status', __('Danke — Ihr Anliegen ist eingegangen. Wir antworten bis :date per E-Mail.', ['date' => $item->due_at->timezone($site->timezone)->format('d.m.Y')], $lang));
    }

    /** Art. 17 DSGVO: das Konto auf dieser Site löschen — nicht, solange ein Vertrag läuft. */
    public function destroy(Request $request): RedirectResponse
    {
        [$site, $customer, $lang, $back] = $this->context($request);
        $validator = Validator::make($request->all(), [
            'confirm' => ['required', 'string', static function (string $attribute, mixed $value, \Closure $fail) use ($customer, $lang): void {
                if (CustomerLogin::normalize((string) $value) !== CustomerLogin::normalize($customer->email)) {
                    $fail(__('Die E-Mail-Adresse stimmt nicht mit der Ihres Kontos überein.', [], $lang));
                }
            }],
            'verstanden' => ['accepted'],
        ], ['verstanden.accepted' => __('Bestätigen Sie, dass sich das Löschen nicht rückgängig machen lässt.', [], $lang)]);
        $running = $this->runningContracts($site, $customer);
        if ($running !== []) {
            $validator->after(static function ($v) use ($running, $lang): void {
                $v->errors()->add('contracts', __('Es läuft noch :contracts — kündigen Sie zuerst über «Verträge hier kündigen», dann lässt sich das Konto löschen.', ['contracts' => implode(', ', $running)], $lang));
            });
        }
        if ($validator->fails()) {
            return redirect(Html::url($back, [], 'datenschutz-loeschen'))->withErrors($validator, self::BAG);
        }
        $email = $customer->email;
        // Die Bestätigung geht vor dem Löschen hinaus — danach gibt es die Adresse im Konto nicht mehr
        Notification::route('mail', $email)->route('site', $site->id)->notify((new AccountDeletedNotification($site->name))->locale($lang));
        CustomerAuth::logout($request);
        $result = $this->data->deleteForSite($email, $site);
        $this->recorder->record('privacy.self_deleted', null, null, ['site' => $site->id, 'weitere' => $result['weitere']], $site);

        return redirect('/')->with('account.status', __('Ihr Konto ist gelöscht. Die Bestätigung ist per E-Mail unterwegs.', [], $lang));
    }

    /**
     * Laufende Verträge der Person auf dieser Site (SaaS-Abo, Agentur-Lizenz …) — so beschrieben, wie sie gekündigt werden.
     *
     * @return list<string>
     */
    private function runningContracts(Site $site, Customer $customer): array
    {
        $out = [];
        foreach (app(ContractSources::class)->for($site) as $source) {
            foreach ($source->find($site, $customer->email, null) as $contract) {
                $out[] = $source->describe($contract, $customer->preferredLocale());
            }
        }

        return $out;
    }

    /**
     * @return array{0: Site, 1: Customer, 2: string, 3: string}
     */
    private function context(?Request $request = null): array
    {
        $site = $this->current->get();
        $customer = CustomerAuth::current();
        abort_if($site === null || $customer === null || $customer->site_id !== $site->id, 403);
        $request ??= request();
        $lang = in_array($request->input('_sprache'), $site->localeCodes(), true) ? (string) $request->input('_sprache') : $site->default_locale;

        return [$site, $customer, $lang, Html::pagePath($request->input('_seite'))];
    }
}
