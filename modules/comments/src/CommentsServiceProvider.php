<?php

declare(strict_types=1);

namespace Basalto\Modules\Comments;

use Basalto\Core\Access\Areas;
use Basalto\Core\Backend\Backend;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\NotificationTypes;
use Basalto\Core\Testdata\Testdata;
use Basalto\Core\Testdata\TestdataRun;
use Basalto\Lava\Runtime\Context;
use Basalto\Lava\Runtime\Limits;
use Basalto\Lava\Runtime\Registry;
use Basalto\Modules\Comments\Frontend\CommentsRenderer;
use Basalto\Modules\Comments\Http\Controllers\CommentSubmissionController;
use Basalto\Modules\Comments\Http\Controllers\ModerationController;
use Basalto\Modules\Comments\Models\Comment;
use Basalto\Modules\Comments\Privacy\CommentPersonData;
use Basalto\Modules\Comments\Testdata\CommentsTestdata;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Kommentare als Modul (M-27), je Site schaltbar: der Lava-Tag `{% comments.list %}` für den Block «Kommentare» im
 * Theme, der Versand aus dem Frontend, die Moderation unter Inhalte › Kommentare mit der Zahl wartender Kommentare am
 * Menü — und der Anschluss an Datenschutz und Löschlauf des Kerns (Auskunft und Löschung je E-Mail, Frist
 * `comments.retention_days`). Ausgeschaltet verschwinden Menü, Moderation, Versand und die Ausgabe des Tags; die
 * Kommentare bleiben gespeichert, bis der Löschlauf greift.
 */
final class CommentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Testdaten (basalto:testdaten)
        app(Testdata::class)->register(CommentsTestdata::KEY, 'Kommentare', 30, static fn (Site $site): bool => app(Backend::class)->moduleEnabled('comments', $site),
            static fn (TestdataRun $run) => app(CommentsTestdata::class)->seed($run), static fn (Site $site) => app(CommentsTestdata::class)->remove($site));
        // CR-005 (R-01): Bereich des Moduls und seine Routen
        app(Areas::class)->register('comments', 'Kommentare', Areas::CONTENT, 'comments')->map('basalto.comments.', 'comments');
        // TB 27: Glocke bei neuen Kommentaren — die Mail stellt man am Kommentar-Block ein (`notify:`)
        app(NotificationTypes::class)->type('comments.posted', 'Neuer Kommentar', 'Kommentare', 'Mehrere Kommentare zu einem Inhalt stehen in einer Zeile. Die Mail stellt man am Kommentar-Block ein.',
            area: 'comments', icon: 'comments', mailAvailable: false);
        $root = dirname(__DIR__);
        $this->loadTranslationsFrom($root.'/lang', 'comments');
        $this->loadJsonTranslationsFrom($root.'/lang');
        $this->loadMigrationsFrom($root.'/database/migrations');

        $backend = $this->app->make(Backend::class);
        $backend->module('comments', 'comments::backend.module', switchable: true, composerJson: $root.'/composer.json');
        $backend->pages('comments', $root.'/resources/js/pages');
        $backend->menu('comments::backend.title', 'basalto.comments.index', 60, Backend::MODE_CONTENT, module: 'comments', icon: 'comments');
        $backend->count('basalto.comments.index', static fn (Site $site): int => Comment::query()->withoutGlobalScopes()->where('site_id', $site->id)
            ->where('status', CommentStatus::Pending->value)->count());
        $backend->routes(static function (): void {
            Route::get('kommentare', [ModerationController::class, 'index'])->name('comments.index');
            Route::post('kommentare/sammelaktion', [ModerationController::class, 'bulk'])->name('comments.bulk');
            Route::post('kommentare/{comment}/status', [ModerationController::class, 'status'])->whereNumber('comment')->name('comments.status');
            Route::post('kommentare/{comment}/antwort', [ModerationController::class, 'reply'])->whereNumber('comment')->name('comments.reply');
            Route::delete('kommentare/{comment}', [ModerationController::class, 'destroy'])->whereNumber('comment')->name('comments.destroy');
        });

        // Versand ohne CSRF-Token wie die Formulare des Kerns: Seiten mit Kommentaren kommen aus dem Seiten-Cache, den
        // Schutz tragen Honeypot, signiertes Zeit-Token und Limit je IP (Forms\SpamGuard).
        Route::post('comments/{entry}', CommentSubmissionController::class)->whereNumber('entry')
            ->middleware(['web', 'basalto.site'])->withoutMiddleware(PreventRequestForgery::class)->name('basalto.comments.submit');

        // Lava: «{% comments.list moderation: … order: … per: … replies: … notify: … closed: … %}» (L-12, nur unter dem Präfix)
        $this->app->extend(Registry::class, static function (Registry $registry): Registry {
            $registry->module('comments')
                ->tag('comments.list', static fn (array $args, array $named, Context $ctx): string => app(CommentsRenderer::class)->render($named, $ctx))
                // TB 10 Scheibe 3 (M-28): derselbe Tag als Lava-Teil — in einer Region oder als Block «@part» einer Seite
                ->part('comments.list', 'Kommentare', ['moderation', 'order', 'per', 'replies', 'notify', 'closed'],
                    static fn (array $settings, array $vars, string $lang): string => app(CommentsRenderer::class)->render($settings, new Context($vars, app(Registry::class), new Limits)));

            return $registry;
        });

        // Datenschutz und Löschlauf des Kerns (N-05): Auskunft und Löschung je E-Mail, Frist für Gelöschtes und Spam
        $this->app->instance('basalto.person-data.comments', (new CommentPersonData)(...));
        $this->app->tag(['basalto.person-data.comments'], 'basalto.person-data');
        $this->app->instance('basalto.cleanup.comments', CommentPersonData::cleanup(...));
        $this->app->tag(['basalto.cleanup.comments'], 'basalto.cleanup');
    }
}
