<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Forms\SpamGuard;
use Basalto\Core\Help\LegalTexts;
use Basalto\Core\Legal\Cancellation\ContractCancellations;
use Basalto\Core\Legal\Cancellation\ContractSource;
use Basalto\Core\Legal\Cancellation\ContractSources;
use Basalto\Core\Mail\MailBrand;
use Basalto\Core\Models\ContractCancellation;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;

/**
 * «Verträge hier kündigen» (CR-011 TB 59, § 312k BGB): eigenständige Seite ohne Anmeldung, Skript und Fremdressource —
 * Art der Kündigung, Name, E-Mail-Adresse, Vertrag, Kennung und Zeitpunkt, dann «Jetzt kündigen». Danach die
 * Eingangsbestätigung mit Datum und Uhrzeit (signierte Adresse, zum Speichern) und dieselbe Bestätigung per Mail.
 * Ohne CSRF wie die Formulare (Zeit-Token, Honigtopf und Limit je IP), damit die Seite in jedem Cache funktioniert.
 */
final class CancellationController
{
    private const FORM = '_kuendigen';

    public function __construct(
        private readonly CurrentSite $current,
        private readonly ContractSources $sources,
        private readonly ContractCancellations $cancellations,
        private readonly SpamGuard $guard,
    ) {}

    public function show(Request $request): Response
    {
        [$site, $sources] = $this->context();
        $lang = $this->language($site, $request->query('sprache'));

        return $this->form($site, $sources, $lang, [], new MessageBag);
    }

    public function store(Request $request): Response|RedirectResponse
    {
        [$site, $sources] = $this->context();
        $lang = $this->language($site, $request->input('_sprache'));
        $old = $request->only(['kind', 'reason', 'name', 'email', 'contract', 'reference', 'when', 'wish_date']);

        $spam = $this->guard->reason($request, self::FORM, $site->id);
        if ($spam !== null) {
            $message = $spam === 'limit' ? __('Zu viele Versuche — bitte senden Sie in einer Minute noch einmal.', [], $lang)
                : __('Das hat nicht geklappt — bitte senden Sie das Formular noch einmal.', [], $lang);

            return $this->form($site, $sources, $lang, $old, new MessageBag(['form' => $message]), 422);
        }

        $keys = array_map(static fn (ContractSource $source): string => $source->key(), $sources);
        $validator = Validator::make($request->all(), [
            'kind' => ['required', Rule::in([ContractCancellation::ORDINARY, ContractCancellation::EXTRAORDINARY])],
            'reason' => ['nullable', 'required_if:kind,'.ContractCancellation::EXTRAORDINARY, 'string', 'max:2000'],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'contract' => ['required', Rule::in([...$keys, ContractCancellation::OTHER])],
            'reference' => ['nullable', 'string', 'max:200', 'required_if:contract,'.ContractCancellation::OTHER],
            'when' => ['required', Rule::in(['next', 'date'])],
            'wish_date' => ['nullable', 'required_if:when,date', 'date_format:Y-m-d', 'after_or_equal:today'],
        ], [
            'reason.required_if' => __('Nennen Sie den Grund für die ausserordentliche Kündigung.', [], $lang),
            'reference.required_if' => __('Beschreiben Sie den Vertrag, damit wir ihn finden.', [], $lang),
            'wish_date.required_if' => __('Wählen Sie das Datum, zu dem der Vertrag enden soll.', [], $lang),
            'wish_date.after_or_equal' => __('Das Datum darf nicht in der Vergangenheit liegen.', [], $lang),
        ], [
            'kind' => __('Art der Kündigung', [], $lang), 'name' => __('Name', [], $lang), 'email' => __('E-Mail-Adresse', [], $lang),
            'contract' => __('Vertrag', [], $lang), 'when' => __('Zeitpunkt', [], $lang), 'wish_date' => __('Datum', [], $lang),
        ]);
        if ($validator->fails()) {
            return $this->form($site, $sources, $lang, $old, $validator->errors(), 422);
        }
        $data = $validator->validated();
        $cancellation = $this->cancellations->receive($site, [
            'kind' => (string) $data['kind'],
            'reason' => $data['kind'] === ContractCancellation::EXTRAORDINARY ? trim((string) $data['reason']) : null,
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'contract' => (string) $data['contract'],
            'reference' => is_string($data['reference'] ?? null) && trim($data['reference']) !== '' ? (string) $data['reference'] : null,
            'wish_date' => $data['when'] === 'date' && is_string($data['wish_date'] ?? null) ? $data['wish_date'] : null,
        ], $lang);

        return redirect()->to($this->cancellations->receiptUrl($cancellation), 303);
    }

    public function receipt(int $cancellation): Response
    {
        [$site] = $this->context();
        $item = ContractCancellation::query()->whereKey($cancellation)->where('site_id', $site->id)->firstOrFail();

        return $this->page($site, $item->locale, 'receipt', ['item' => $this->summary($site, $item)]);
    }

    public function confirmRevoke(int $cancellation): Response
    {
        [$site] = $this->context();
        $item = ContractCancellation::query()->whereKey($cancellation)->where('site_id', $site->id)->firstOrFail();
        $action = $item->revocable() ? URL::temporarySignedRoute('basalto.cancel.revoke.store', now()->addHour(), ['cancellation' => $item->id], false) : null;

        return $this->page($site, $item->locale, 'revoke', ['item' => $this->summary($site, $item), 'action' => $action, 'done' => null]);
    }

    public function revoke(int $cancellation): Response
    {
        [$site] = $this->context();
        $item = ContractCancellation::query()->whereKey($cancellation)->where('site_id', $site->id)->firstOrFail();
        $done = $this->cancellations->revoke($item);

        return $this->page($site, $item->locale, 'revoke', ['item' => $this->summary($site, $item->refresh()), 'action' => null, 'done' => $done]);
    }

    /** @return array{0: Site, 1: list<ContractSource>} */
    private function context(): array
    {
        $site = $this->current->get();
        abort_if($site === null, 404);
        $sources = $this->sources->for($site);
        abort_if($sources === [], 404);

        return [$site, $sources];
    }

    private function language(Site $site, mixed $wanted): string
    {
        return is_string($wanted) && in_array($wanted, $site->localeCodes(), true) ? $wanted : $site->default_locale;
    }

    /**
     * @param  list<ContractSource>  $sources
     * @param  array<string, mixed>  $old
     */
    private function form(Site $site, array $sources, string $lang, array $old, MessageBag $errors, int $status = 200): Response
    {
        $contracts = array_map(static fn (ContractSource $source): array => ['key' => $source->key(), 'label' => __($source->label(), [], $lang), 'hint' => __($source->hint(), [], $lang)], $sources);
        $contracts[] = ['key' => ContractCancellation::OTHER, 'label' => __('Anderer Vertrag', [], $lang), 'hint' => __('Welcher Vertrag? Zum Beispiel Bestellnummer oder Produkt', [], $lang)];

        return $this->page($site, $lang, 'form', [
            'contracts' => $contracts,
            'old' => $old + ['kind' => ContractCancellation::ORDINARY, 'when' => 'next', 'contract' => count($contracts) === 2 ? $contracts[0]['key'] : null],
            'errors' => $errors,
            'spam' => ['honeypot' => SpamGuard::HONEYPOT, 'token' => SpamGuard::TOKEN, 'value' => $this->guard->token(self::FORM)],
            'action' => route('basalto.cancel.store', [], false),
            'today' => CarbonImmutable::now($site->timezone)->toDateString(),
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function page(Site $site, string $lang, string $view, array $data, int $status = 200): Response
    {
        app()->setLocale($lang);
        $brand = app(MailBrand::class);
        $brand->useSite($site, $lang);
        $vendor = $brand->current();
        $brand->reset();
        $languages = array_map(static fn (string $code): array => ['code' => $code, 'url' => ContractCancellations::PATH.($code === $site->default_locale ? '' : '?sprache='.$code), 'current' => $code === $lang],
            $view === 'form' ? $site->localeCodes() : []);

        return response()->view('basalto::cancel', $data + [
            'view' => $view, 'lang' => $lang, 'site' => $site, 'vendor' => $vendor, 'languages' => count($languages) > 1 ? $languages : [],
            'withdrawal' => LegalTexts::publicUrl('widerruf', $lang),
        ], $status)->header('Content-Language', $lang)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    /** @return array<string, mixed> */
    private function summary(Site $site, ContractCancellation $item): array
    {
        $received = $item->received_at->timezone($site->timezone);

        return [
            'number' => $item->number, 'status' => $item->status, 'date' => $received->format('d.m.Y'), 'time' => $received->format('H:i'),
            'kind' => $item->kind, 'reason' => $item->reason, 'name' => $item->name, 'email' => $item->email, 'reference' => $item->reference,
            'contract' => $this->cancellations->describe($item, $item->locale), 'wish' => $item->wish_date?->format('d.m.Y'),
            'ends' => $item->ends_at?->format('d.m.Y'), 'notes' => $this->cancellations->notes($item, $item->locale),
            'outcome' => $this->cancellations->outcome($item, $item->locale),
        ];
    }
}
