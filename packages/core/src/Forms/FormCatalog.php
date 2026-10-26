<?php

declare(strict_types=1);

namespace Basalto\Core\Forms;

use Basalto\Core\Models\Form;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteScope;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;

/** Die Formulare einer Site aus ihrem Theme samt Site-Quellen (M-11) und ihre Betriebszeile in `forms`. */
final class FormCatalog
{
    public function __construct(private readonly ThemeLoader $themes) {}

    /** @return list<FormDefinition> */
    public function all(Site $site): array
    {
        try {
            $document = $this->themes->forSite($site)->document;
        } catch (ThemeException) {
            return [];
        }

        return array_map(FormDefinition::fromDefinition(...), $document->all('form'));
    }

    public function find(Site $site, string $handle): ?FormDefinition
    {
        foreach ($this->all($site) as $form) {
            if ($form->handle === $handle) {
                return $form;
            }
        }

        return null;
    }

    /** Die Betriebszeile — entsteht beim ersten Gebrauch. */
    public function record(Site $site, string $handle): Form
    {
        return Form::query()->withoutGlobalScope(SiteScope::class)->firstOrCreate(['site_id' => $site->id, 'handle' => $handle], ['spam_count' => 0]);
    }
}
