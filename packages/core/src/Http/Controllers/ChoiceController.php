<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Level;
use Basalto\Core\Choices\ChoiceLists;
use Basalto\Core\Choices\Choices;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CR-022: «+ … hinzufügen» in einem Auswahlfeld — ein neuer Wert für die Auswahlliste der Site. Darf, wer einen der
 * Bereiche der Liste bearbeiten darf; die Liste muss für die Site gelten.
 */
final class ChoiceController
{
    public function __construct(private readonly ChoiceLists $lists, private readonly Choices $choices, private readonly CurrentSite $current, private readonly Access $access) {}

    public function store(Request $request, string $list): JsonResponse
    {
        $site = $this->current->require();
        $definition = $this->lists->get($list);
        abort_if($definition === null || ! in_array($definition, $this->lists->forSite($site), true), 404);
        $user = $request->user();
        abort_unless($user instanceof Authenticatable && self::mayAdd($this->access, $user, $site, $definition->areas()), 403);
        $data = $request->validate(['label' => ['required', 'string', 'max:160'], 'group' => ['nullable', 'string', 'max:120']], [], ['label' => __('Bezeichnung')]);
        $option = $this->choices->add($site, $list, (string) $data['label'], is_string($data['group'] ?? null) ? $data['group'] : null, (int) $user->getAuthIdentifier());

        return response()->json(['option' => $option]);
    }

    /** @param  list<string>  $areas */
    public static function mayAdd(Access $access, Authenticatable $user, Site $site, array $areas): bool
    {
        foreach ($areas as $area) {
            if ($access->allows($user, $site, $area, Level::Edit)) {
                return true;
            }
        }

        return false;
    }
}
