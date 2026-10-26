<?php

declare(strict_types=1);

namespace Basalto\Modules\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/** «Alt-Texte» (M-24): je Sprache der Site ein Alt-Text aus dem Bild (Vision), knapp und sachlich. */
#[MaxTokens(800)]
#[Temperature(0.2)]
#[Timeout(60)]
final class AltTextWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    /** @param  list<string>  $locales */
    public function __construct(public readonly array $locales = ['de']) {}

    public function instructions(): string
    {
        return 'You write alternative texts (alt attributes) for images on a website, following WCAG: describe what matters in the image '
            .'in one short sentence (at most 125 characters), no «image of» or «photo of», no file names, no guessing of names of people. '
            .'Write one alt text for each of these languages: '.implode(', ', $this->locales).'. For German use Swiss spelling (ss instead of ß).';
    }

    public function schema(JsonSchema $schema): array
    {
        $out = [];
        foreach ($this->locales as $locale) {
            $out[$locale] = $schema->string()->required();
        }

        return $out;
    }
}
