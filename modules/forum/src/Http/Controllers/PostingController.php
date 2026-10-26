<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Http\Controllers;

use Basalto\Core\Community\Attachments;
use Basalto\Core\Community\Censor;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\Flood;
use Basalto\Core\Community\Http\ReportController;
use Basalto\Core\Community\Profiles;
use Basalto\Core\Community\VisitorAccess;
use Basalto\Core\Content\RichText;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Actions\Posting;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\ForumSearch;
use Basalto\Modules\Forum\Frontend\ForumMount;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Basalto\Modules\Forum\PostBody;
use Basalto\Modules\Forum\Tags;
use Basalto\Modules\Forum\ThreadFields;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;
use RuntimeException;
use Throwable;

/**
 * Schreiben aus dem Frontend (CR-006 FO-05 bis FO-09): Thema eröffnen (mit Präfix, Themenfeldern und Anhängen),
 * antworten, bearbeiten mit Grund. Nur angemeldet und mit Profil, mit CSRF-Token; ein ausgefüllter Honigtopf wird still
 * verworfen. Fehler kommen in die Tasche `forum`, das Formular füllt sich wieder. Dazu das Zitat für die Editor-Insel.
 */
final class PostingController
{
    public function __construct(private readonly Forum $forum, private readonly Posting $posting, private readonly Profiles $profiles, private readonly Attachments $attachments) {}

    public function storeThread(Request $request): RedirectResponse
    {
        [$site, $customer, $lang, $back] = $this->context($request);
        $node = Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->find((int) $request->input('node'));
        abort_if($node === null, 404);
        abort_unless($this->forum->canStart($site, $customer, $node), 403);
        if ($this->profiles->of($customer) === null || filled($request->input('website'))) {
            return redirect($back);
        }
        $body = PostBody::fromRequest($request);
        $prefixes = array_map('intval', (array) $node->setting('prefixes', []));
        $prefix = $request->filled('praefix') ? (int) $request->input('praefix') : null;
        [$values, $fieldErrors] = ThreadFields::validate($node, is_array($request->input('feld')) ? $request->input('feld') : [], $lang);
        $validator = $this->validator(['title' => $request->input('title'), 'body' => $body === null ? null : 'ok'],
            ['title' => ['required', 'string', 'min:3', 'max:120'], 'body' => ['required']], ['title' => __('Titel', [], $lang), 'body' => __('Beitrag', [], $lang)], $body, $lang);
        $validator->after(static function (ValidatorInstance $v) use ($prefix, $prefixes, $node, $fieldErrors, $lang): void {
            if ($prefix !== null && ! in_array($prefix, $prefixes, true)) {
                $v->errors()->add('praefix', __('Dieses Präfix gibt es in diesem Forum nicht.', [], $lang));
            } elseif ($prefix === null && $prefixes !== [] && (bool) $node->setting('prefix_required', false)) {
                $v->errors()->add('praefix', __('Bitte ein Präfix wählen.', [], $lang));
            }
            foreach ($fieldErrors as $key => $message) {
                $v->errors()->add($key, $message);
            }
        });
        $poll = $this->poll($request, $lang, $validator);
        $this->guard($validator, $site, $customer, $lang, $body, (string) $request->input('title'), $poll['question'] ?? '', ...($poll['options'] ?? []));
        $uploaded = $this->uploads($request, $site, $customer, $lang, $validator);
        if ($validator->fails() || $body === null) {
            return redirect($back)->withErrors($validator, 'forum')->withInput($request->except('anhaenge'));
        }
        $official = $request->boolean('offiziell') && $this->forum->canMarkOfficial($site, $customer, $node);
        $pending = $this->forum->needsApproval($site, $customer, $node);
        $thread = $this->posting->startThread($site, $customer, $node, (string) $request->input('title'), $body, $lang, $this->ipHash($request), $prefix, $values,
            [...$uploaded, ...$this->attachmentIds($request)], $official, $poll, $pending);
        Tags::sync($site, $thread, Tags::parse($request->input('tags')));
        app(ForumSearch::class)->thread($thread->id);
        if ($pending) {
            return redirect($this->base($request).'/'.$node->slug)->with(ReportController::STATUS, __('Danke — Ihr Thema erscheint, sobald die Moderation es freigeschaltet hat.', [], $lang));
        }

        return redirect($this->base($request).'/thema/'.$thread->pathSegment());
    }

    public function storeReply(Request $request, int $thread): RedirectResponse
    {
        [$site, $customer, $lang, $back] = $this->context($request);
        [$model, $node] = $this->threadAndNode($site, $thread);
        abort_unless($this->forum->canReply($site, $customer, $model, $node), 403);
        if ($this->profiles->of($customer) === null || filled($request->input('website'))) {
            return redirect($back);
        }
        $body = PostBody::fromRequest($request);
        $validator = $this->validator(['body' => $body === null ? null : 'ok'], ['body' => ['required']], ['body' => __('Ihre Antwort', [], $lang)], $body, $lang);
        $this->guard($validator, $site, $customer, $lang, $body);
        $uploaded = $this->uploads($request, $site, $customer, $lang, $validator);
        if ($validator->fails() || $body === null) {
            return redirect($back.'#antworten')->withErrors($validator, 'forum')->withInput($request->except('anhaenge'));
        }
        $official = $request->boolean('offiziell') && $this->forum->canMarkOfficial($site, $customer, $node);
        $pending = $this->forum->needsApproval($site, $customer, $node);
        $post = $this->posting->reply($site, $customer, $model, $body, $this->ipHash($request), [...$uploaded, ...$this->attachmentIds($request)], $official, $pending);
        if ($pending) {
            return redirect($back.'#antworten')->with(ReportController::STATUS, __('Danke — Ihre Antwort erscheint, sobald die Moderation sie freigeschaltet hat.', [], $lang));
        }

        return redirect($this->postUrl($request, $model, $post));
    }

    /** Beitrag bearbeiten (FO-08): Text, Grund; beim Startbeitrag auch Titel, Präfix und Themenfelder. */
    public function update(Request $request, int $post): RedirectResponse
    {
        [$site, $customer, $lang, $back] = $this->context($request);
        $model = Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->find($post);
        abort_if($model === null, 404);
        [$thread, $node] = $this->threadAndNode($site, $model->thread_id);
        abort_unless($this->forum->canEdit($site, $customer, $model, $thread, $node), 403);
        $body = PostBody::fromRequest($request);
        $isFirst = $thread->first_post_id === $model->id;
        $rules = ['body' => ['required'], 'reason' => ['nullable', 'string', 'max:200']];
        $data = ['body' => $body === null ? null : 'ok', 'reason' => $request->input('reason')];
        if ($isFirst) {
            $rules['title'] = ['required', 'string', 'min:3', 'max:120'];
            $data['title'] = $request->input('title');
        }
        $validator = $this->validator($data, $rules, ['title' => __('Titel', [], $lang), 'body' => __('Beitrag', [], $lang), 'reason' => __('Grund der Änderung', [], $lang)], $body, $lang);
        $threadChanges = [];
        if ($isFirst) {
            $prefixes = array_map('intval', (array) $node->setting('prefixes', []));
            $prefix = $request->filled('praefix') ? (int) $request->input('praefix') : null;
            [$values, $fieldErrors] = ThreadFields::validate($node, is_array($request->input('feld')) ? $request->input('feld') : [], $lang);
            $validator->after(static function (ValidatorInstance $v) use ($prefix, $prefixes, $node, $fieldErrors, $lang): void {
                if ($prefix !== null && ! in_array($prefix, $prefixes, true)) {
                    $v->errors()->add('praefix', __('Dieses Präfix gibt es in diesem Forum nicht.', [], $lang));
                } elseif ($prefix === null && $prefixes !== [] && (bool) $node->setting('prefix_required', false)) {
                    $v->errors()->add('praefix', __('Bitte ein Präfix wählen.', [], $lang));
                }
                foreach ($fieldErrors as $key => $message) {
                    $v->errors()->add($key, $message);
                }
            });
            $threadChanges = ['title' => (string) $request->input('title'), 'prefix_id' => $prefix, 'values' => $values];
        }
        $this->guard($validator, $site, null, $lang, $body, $isFirst ? (string) $request->input('title') : '');
        $uploaded = $this->uploads($request, $site, $customer, $lang, $validator);
        if ($validator->fails() || $body === null) {
            return redirect($back)->withErrors($validator, 'forum')->withInput($request->except('anhaenge'));
        }
        if ($isFirst) {
            Tags::sync($site, $thread, Tags::parse($request->input('tags')));
        }
        $this->posting->edit($site, $customer, $model, $body, is_string($request->input('reason')) ? $request->input('reason') : null, $threadChanges,
            [...$uploaded, ...$this->attachmentIds($request)]);

        return redirect($this->postUrl($request, $thread->refresh(), $model));
    }

    /** Das Zitat eines Beitrags für die Insel: Verfasser, Kennung, Dokument — nur, wenn die Person das Thema lesen darf. */
    public function quote(int $post): JsonResponse
    {
        $site = app(CurrentSite::class)->get();
        abort_if($site === null, 404);
        $model = Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->find($post);
        abort_if($model === null || ! $this->readable($model->id, CustomerAuth::current()), 404);
        $profile = $model->customer_id === null ? null : CommunityProfile::query()->withoutGlobalScopes()->where('customer_id', $model->customer_id)->first();

        return response()->json(['post' => $model->id, 'author' => $profile !== null ? $profile->name : (string) __('Ehemaliges Mitglied'), 'doc' => $model->body]);
    }

    /** Darf die Person den Beitrag (und seine Anhänge) lesen? */
    public function readable(int $postId, ?Customer $customer): bool
    {
        $post = Post::query()->withoutGlobalScope(SiteScope::class)->where('is_disabled', false)->find($postId);
        $thread = $post === null ? null : Thread::query()->withoutGlobalScope(SiteScope::class)->where('is_disabled', false)->find($post->thread_id);
        $node = $thread === null ? null : Node::query()->withoutGlobalScopes()->find($thread->node_id);
        if ($node === null) {
            return false;
        }
        $site = $node->site;

        return $site !== null && $this->forum->can($site, $customer, Forum::READ, $node);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $names
     * @param  array<string, mixed>|null  $body
     */
    private function validator(array $data, array $rules, array $names, ?array $body, string $lang): ValidatorInstance
    {
        $validator = Validator::make($data, $rules, [], $names);
        $validator->after(static function (ValidatorInstance $v) use ($body, $lang): void {
            if (PostBody::length($body) > PostBody::MAX_TEXT) {
                $v->errors()->add('body', __('Der Beitrag ist zu lang (höchstens :max Zeichen).', ['max' => PostBody::MAX_TEXT], $lang));
            }
        });

        return $validator;
    }

    /**
     * Zensur und Flood-Control (TB 39, FO-17): ein Wort der Liste blockiert das Absenden mit Hinweis; zwischen zwei
     * Beiträgen liegt der Mindestabstand (beim Bearbeiten nicht).
     *
     * @param  array<string, mixed>|null  $body
     */
    private function guard(ValidatorInstance $validator, Site $site, ?Customer $customer, string $lang, ?array $body, string ...$texts): void
    {
        $censor = app(Censor::class)->error($site, $lang, $body === null ? '' : RichText::toText($body), ...$texts);
        $flood = $customer === null ? null : app(Flood::class)->error($site, $customer, $lang);
        $validator->after(static function (ValidatorInstance $v) use ($censor, $flood): void {
            if ($censor !== null) {
                $v->errors()->add('body', $censor);
            }
            if ($flood !== null) {
                $v->errors()->add('body', $flood);
            }
        });
    }

    /**
     * Die Umfrage aus dem Formular «Neues Thema» (FO-12) — nur, wenn eine Frage steht; mindestens zwei, höchstens
     * zwanzig Antworten (eine je Zeile), Ende in der Zukunft.
     *
     * @return array{question: string, options: list<string>, max_choices: int, ends_at: DateTimeInterface|null, results_after_vote: bool}|null
     */
    private function poll(Request $request, string $lang, ValidatorInstance $validator): ?array
    {
        $input = is_array($request->input('umfrage')) ? $request->input('umfrage') : [];
        $question = is_string($input['frage'] ?? null) ? trim(mb_substr($input['frage'], 0, 200)) : '';
        if ($question === '') {
            return null;
        }
        $options = array_values(array_unique(array_filter(array_map(static fn (string $o): string => trim(mb_substr($o, 0, 120)),
            preg_split('/\R/', is_string($input['optionen'] ?? null) ? $input['optionen'] : '') ?: []), static fn (string $o): bool => $o !== '')));
        $max = max(1, min(count($options), (int) ($input['max'] ?? 1)));
        $ends = null;
        if (is_string($input['ende'] ?? null) && $input['ende'] !== '') {
            try {
                $ends = Carbon::parse($input['ende'])->endOfDay();
            } catch (Throwable) {
                $ends = null;
            }
        }
        $validator->after(static function (ValidatorInstance $v) use ($options, $ends, $lang): void {
            if (count($options) < 2 || count($options) > 20) {
                $v->errors()->add('umfrage.optionen', __('Eine Umfrage braucht 2 bis 20 Antworten, eine je Zeile.', [], $lang));
            }
            if ($ends !== null && $ends->isPast()) {
                $v->errors()->add('umfrage.ende', __('Das Ende der Umfrage liegt in der Vergangenheit.', [], $lang));
            }
        });

        return ['question' => $question, 'options' => $options, 'max_choices' => $max, 'ends_at' => $ends, 'results_after_vote' => (bool) ($input['nach_stimme'] ?? false)];
    }

    /**
     * Dateien aus dem Formular ohne Skript (`anhaenge[]`) — gespeichert, Fehler ins Formular.
     *
     * @return list<int>
     */
    private function uploads(Request $request, Site $site, Customer $customer, string $lang, ValidatorInstance $validator): array
    {
        $files = array_filter((array) $request->file('anhaenge', []), static fn (mixed $f): bool => $f instanceof UploadedFile && $f->isValid());
        // TB 52: höchstens so viele Anhänge je Beitrag — aus dem Formular und aus der Insel zusammen
        $max = $this->attachments->maxFiles($site);
        if (count($files) + count($this->attachmentIds($request)) > $max) {
            $validator->after(static fn (ValidatorInstance $v) => $v->errors()->add('anhaenge', __('Höchstens :max Anhänge je Beitrag.', ['max' => $max], $lang)));

            return [];
        }
        if ($files === []) {
            return [];
        }
        if (! app(VisitorAccess::class)->allows($site, $customer, Attachments::PERMISSION)) {
            $validator->after(static fn (ValidatorInstance $v) => $v->errors()->add('anhaenge', __('Sie dürfen keine Dateien anhängen.', [], $lang)));

            return [];
        }
        $ids = [];
        foreach (array_slice($files, 0, 10) as $file) {
            $problem = $this->attachments->problem($site, $customer, $file, $lang);
            if ($problem !== null) {
                $validator->after(static fn (ValidatorInstance $v) => $v->errors()->add('anhaenge', $file->getClientOriginalName().': '.$problem));

                continue;
            }
            try {
                $ids[] = $this->attachments->store($site, $customer, $file)->id;
            } catch (RuntimeException) {
                $validator->after(static fn (ValidatorInstance $v) => $v->errors()->add('anhaenge', __('Das Bild liess sich nicht lesen.', [], $lang)));
            }
        }

        return $ids;
    }

    /** @return list<int> Anhänge, die die Insel schon hochgeladen hat */
    private function attachmentIds(Request $request): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array) $request->input('anhaenge_ids', []), 'is_numeric'))));
    }

    /** @return array{0: Thread, 1: Node} */
    private function threadAndNode(Site $site, int $thread): array
    {
        $model = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->find($thread);
        $node = $model === null ? null : Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->find($model->node_id);
        abort_if($model === null || $node === null, 404);

        return [$model, $node];
    }

    private function postUrl(Request $request, Thread $thread, Post $post): string
    {
        $count = Post::query()->withoutGlobalScope(SiteScope::class)->where('thread_id', $thread->id)->where('is_disabled', false)->where('id', '<=', $post->id)->count();
        $page = (int) ceil($count / ForumMount::POSTS_PER_PAGE);

        return Html::url($this->base($request).'/thema/'.$thread->pathSegment(), ['seite' => $page > 1 ? $page : null], 'beitrag-'.$post->id);
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

    /** Die Einhängeseite, von der das Formular kam — nur ein Pfad dieser Site. */
    private function base(Request $request): string
    {
        $base = Html::pagePath($request->input('_basis'));

        return $base === '/' ? '' : rtrim($base, '/');
    }

    private function ipHash(Request $request): string
    {
        return hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
    }
}
