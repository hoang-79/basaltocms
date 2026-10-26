<?php

declare(strict_types=1);

namespace Basalto\Core\Community\Http;

use Basalto\Core\Community\Reactions;
use Basalto\Core\Community\Sanctions;
use Basalto\Core\Community\Subjects;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reagieren (CR-006 FO-K8): ohne Skript ein Formular je Reaktion (zurück zur Seite mit Sprungmarke), mit der Insel als
 * JSON mit der neuen Zählung. Nur auf Inhalte, die die Person lesen darf, nie auf eigene.
 */
final class ReactionController
{
    public function __construct(private readonly Reactions $reactions, private readonly Subjects $subjects) {}

    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        $customer = CustomerAuth::current();
        abort_if($customer === null, 403);
        // TB 39 (FO-17): wer eine Schreibsperre hat, reagiert auch nicht
        abort_if(app(Sanctions::class)->isMuted($customer), 403);
        $data = $request->validate(['subject' => ['required', 'string', 'max:40'], 'id' => ['required', 'integer', 'min:1'], 'reaction' => ['required', 'string', 'in:'.implode(',', array_keys(Reactions::enabled(app(CurrentSite::class)->get())))]]);
        $type = (string) $data['subject'];
        $id = (int) $data['id'];
        abort_unless($this->subjects->known($type) && $this->subjects->readable($type, $id, $customer), 404);
        abort_if($this->subjects->owner($type, $id) === $customer->id, 403);
        $mine = $this->reactions->toggle($customer, $type, $id, (string) $data['reaction']);
        if ($request->expectsJson()) {
            return response()->json(['mine' => $mine, 'counts' => $this->reactions->summary($type, [$id])[$id] ?? []]);
        }

        return redirect(Html::pagePath($request->input('_seite')).(is_string($request->input('_anker')) && preg_match('/^[a-z0-9-]{1,40}$/', $request->input('_anker')) === 1 ? '#'.$request->input('_anker') : ''));
    }
}
