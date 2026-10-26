<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Models\Site;
use Illuminate\Support\Collection;

/**
 * Hinweise in der Community (CR-006 FO-17), Vorbild WoltLab: Banner oben im Bereich mit Bedingungen — Gruppen (leer =
 * alle), Bereich (`*` überall, `forum` die Übersicht, `forum:<id>` ein Forum samt Unterforen und Themen), angemeldet
 * oder nicht, Zeitraum. Angemeldete blenden einen Hinweis für sich aus; Gäste sehen ihn (ihre Seiten sind gecacht).
 */
final class Notices
{
    public const PREFERENCE = 'community.dismissed_notices';

    public function __construct(private readonly VisitorGroups $groups) {}

    /**
     * @param  list<string>  $areas  die Bereiche der Seite, z. B. ['*', 'forum', 'forum:7', 'forum:2']
     * @return Collection<int, CommunityNotice>
     */
    public function for(Site $site, ?Customer $customer, array $areas): Collection
    {
        $groupIds = $this->groups->of($site, $customer)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $dismissed = $customer === null ? [] : array_map('intval', (array) data_get($customer->preferences, self::PREFERENCE, []));

        return CommunityNotice::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('is_active', true)
            ->where(static fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(static fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('position')->orderBy('id')->get()
            ->filter(static function (CommunityNotice $n) use ($customer, $areas, $groupIds, $dismissed): bool {
                $c = $n->conditions ?? [];
                $audience = $c['audience'] ?? 'all';
                $groups = array_map('intval', $c['groups'] ?? []);

                return in_array($c['area'] ?? '*', [...$areas, '*'], true)
                    && ($audience === 'all' || ($audience === 'guests') === ($customer === null))
                    && ($groups === [] || array_intersect($groups, $groupIds) !== [])
                    && ! ($n->dismissible && in_array($n->id, $dismissed, true));
            })->values();
    }

    /** @param  list<string>  $areas */
    public function render(Site $site, ?Customer $customer, array $areas, string $lang, string $pagePath): string
    {
        $html = '';
        $guestClose = false;
        foreach ($this->for($site, $customer, $areas) as $notice) {
            $label = Html::e(__('Hinweis ausblenden', [], $lang));
            if ($notice->dismissible && $customer !== null) {
                $close = sprintf('<form method="post" action="%s" class="community-hinweis__aus">%s<button type="submit" aria-label="%s" title="%3$s">×</button></form>',
                    Html::e(route('basalto.community.notices.dismiss', ['notice' => $notice->id], false)), Html::hidden($pagePath, $lang, true), $label);
            } elseif ($notice->dismissible) {
                // TB 51: Gäste blenden im Browser aus (die Seite kommt aus dem Cache, darum ohne Sitzung)
                $close = sprintf('<span class="community-hinweis__aus" hidden><button type="button" data-hinweis-aus aria-label="%s" title="%1$s">×</button></span>', $label);
                $guestClose = true;
            } else {
                $close = '';
            }
            $html .= sprintf('<div class="community-hinweis community-hinweis--%s" role="note" data-hinweis="%d"><p>%s</p>%s</div>', Html::e($notice->tone), $notice->id,
                nl2br(Html::e($notice->textIn($lang, $site->default_locale)), false), $close);
        }
        if ($guestClose) {
            $html .= '<script>(function(){var key="basalto-hinweise-aus-'.$site->id.'",ids=[];try{ids=JSON.parse(localStorage.getItem(key)||"[]")}catch(e){}'
                .'document.querySelectorAll("[data-hinweis]").forEach(function(n){var id=n.getAttribute("data-hinweis"),b=n.querySelector("[data-hinweis-aus]");if(!b){return}if(ids.indexOf(id)>=0){n.hidden=true;return}'
                .'b.parentNode.hidden=false;b.addEventListener("click",function(){n.hidden=true;ids.push(id);try{localStorage.setItem(key,JSON.stringify(ids))}catch(e){}})})})();</script>';
        }

        return $html;
    }

    public function dismiss(Customer $customer, CommunityNotice $notice): void
    {
        $preferences = is_array($customer->preferences) ? $customer->preferences : [];
        $list = array_map('intval', (array) data_get($preferences, self::PREFERENCE, []));
        $list[] = $notice->id;
        data_set($preferences, self::PREFERENCE, array_values(array_unique(array_slice($list, -100))));
        $customer->forceFill(['preferences' => $preferences])->save();
    }
}
