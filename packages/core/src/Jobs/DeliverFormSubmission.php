<?php

declare(strict_types=1);

namespace Basalto\Core\Jobs;

use Basalto\Core\Forms\FormCatalog;
use Basalto\Core\Forms\FormDefinition;
use Basalto\Core\Forms\FormField;
use Basalto\Core\Models\FormSubmission;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\FormSubmittedNotification;
use Basalto\Core\Notifications\Notify;
use Basalto\Core\Support\BackendLocale;
use Basalto\Core\Support\PublicAddress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Die Aktionen eines Eingangs (M-11): Mail an die Empfänger und Webhook (JSON, signiert mit HMAC-SHA256 im Kopf
 * `X-Basalto-Signature`). Jede Aktion für sich: scheitert die Mail, läuft der Webhook trotzdem, und der Eingang trägt
 * den Grund («Mail nicht zugestellt»), damit die Redaktion ihn im Backend erneut senden kann. Ohne gespeicherten
 * Eingang (`store: false`) kommen die Werte im Auftrag mit und verschwinden danach.
 *
 * @phpstan-type Payload array{locale: string, page_path: string|null, data: array<string, mixed>, created_at: string}
 */
final class DeliverFormSubmission implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  Payload|null  $payload */
    public function __construct(public readonly int $siteId, public readonly string $handle, public readonly ?int $submissionId, public readonly ?array $payload = null, public readonly bool $onlyMail = false) {}

    public function handle(FormCatalog $catalog, PublicAddress $addresses): void
    {
        $site = Site::query()->find($this->siteId);
        $form = $site === null ? null : $catalog->find($site, $this->handle);
        if ($site === null || $form === null) {
            return;
        }
        $submission = $this->submissionId === null ? null : FormSubmission::query()->withoutGlobalScopes()->find($this->submissionId);
        $payload = $submission !== null
            ? ['locale' => $submission->locale, 'page_path' => $submission->page_path, 'data' => $submission->data, 'created_at' => (string) $submission->created_at?->toIso8601String()]
            : $this->payload;
        if ($payload === null) {
            return;
        }
        $mail = $this->mail($site, $form, $payload, $submission);
        $submission?->forceFill($mail)->save();
        if (! $this->onlyMail) {
            $webhook = $this->webhook($site, $form, $catalog, $addresses, $payload);
            $submission?->forceFill($webhook)->save();
            // TB 27: Glocke für alle mit «Formulare und Eingänge» — Einsendungen eines Formulars stehen in einer Zeile
            try {
                Notify::make('forms.submitted')->title('Neue Einsendung im Formular «:form»', ['form' => $form->label($site->default_locale)])
                    ->titleMany(':count neue Einsendungen im Formular «:form»')
                    ->url($submission !== null ? route('basalto.forms.inbox.show', [$form->handle, $submission->id]) : route('basalto.forms.inbox', $form->handle), 'Eingang öffnen')
                    ->collapse('form:'.$form->handle)->toArea($site)->send();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @param  Payload  $payload
     * @return array{mail_status: string, mail_error: string|null}
     */
    private function mail(Site $site, FormDefinition $form, array $payload, ?FormSubmission $submission): array
    {
        $recipients = $form->recipients();
        if ($recipients === []) {
            return ['mail_status' => 'none', 'mail_error' => null];
        }
        $lang = $site->default_locale;
        $data = $payload['data'];
        $lines = [];
        $replyTo = null;
        foreach ($form->inputs() as $field) {
            if ($field->type === 'hidden' && ! isset($data[$field->name])) {
                continue;
            }
            $value = $data[$field->name] ?? null;
            $lines[] = ['label' => $field->label($lang), 'value' => self::text($field, $value)];
            if ($replyTo === null && $field->type === 'email' && is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                $replyTo = $value;
            }
        }
        $subject = (string) preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => Str::limit(self::text(null, $data[$m[1]] ?? ''), 60, '…'),
            $form->text('subject', $lang) ?? (string) __('Formular «:form» auf :site', ['form' => $form->label($lang), 'site' => $site->name], $lang));
        try {
            $url = $submission === null ? null : route('basalto.forms.inbox.show', [$form->handle, $submission->id]);
        } catch (Throwable) {
            $url = null;
        }
        try {
            // Rahmen der Mail in der Sprache der Konten, sonst in der Sprache der Seite mit dem Formular (N-01)
            BackendLocale::notify($recipients, new FormSubmittedNotification($subject, $form->label($lang), $site->name, $lines, $replyTo, $url),
                in_array($lang, BackendLocale::LOCALES, true) ? $lang : null);

            return ['mail_status' => 'sent', 'mail_error' => null];
        } catch (Throwable $e) {
            return ['mail_status' => 'failed', 'mail_error' => Str::limit($e->getMessage(), 480, '…')];
        }
    }

    /**
     * @param  Payload  $payload
     * @return array{webhook_status: string|null, webhook_error: string|null}
     */
    private function webhook(Site $site, FormDefinition $form, FormCatalog $catalog, PublicAddress $addresses, array $payload): array
    {
        $url = $form->webhookUrl();
        if ($url === null) {
            return ['webhook_status' => null, 'webhook_error' => null];
        }
        $record = $catalog->record($site, $form->handle);
        if ($record->webhook_secret === null || $record->webhook_secret === '') {
            $record->forceFill(['webhook_secret' => Str::random(40)])->save();
        }
        $body = (string) json_encode(['form' => $form->handle, 'site' => $site->handle, 'locale' => $payload['locale'], 'page' => $payload['page_path'],
            'submitted_at' => $payload['created_at'], 'data' => $payload['data']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            [$host, $port, $ip] = $addresses->check($url, __('Webhooks'));
            $response = Http::timeout(10)->connectTimeout(5)->withOptions(['allow_redirects' => false, 'curl' => PublicAddress::pin($host, $port, $ip)])
                ->withHeaders(['X-Basalto-Signature' => 'sha256='.hash_hmac('sha256', $body, (string) $record->webhook_secret), 'Content-Type' => 'application/json'])
                ->withBody($body, 'application/json')->post($url);

            return $response->successful() ? ['webhook_status' => 'sent', 'webhook_error' => null] : ['webhook_status' => 'failed', 'webhook_error' => sprintf('HTTP %d', $response->status())];
        } catch (Throwable $e) {
            return ['webhook_status' => 'failed', 'webhook_error' => Str::limit($e->getMessage(), 480, '…')];
        }
    }

    private static function text(?FormField $field, mixed $value): string
    {
        if (is_array($value)) {
            return isset($value['name']) ? __(':file (Datei im Backend)', ['file' => (string) $value['name']]) : implode(', ', array_map('strval', array_filter($value, 'is_scalar')));
        }
        if (is_bool($value) || ($field?->type === 'checkbox')) {
            return $value ? 'ja' : 'nein';
        }

        return trim((string) $value);
    }
}
