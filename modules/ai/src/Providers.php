<?php

declare(strict_types=1);

namespace Basalto\Modules\Ai;

use Laravel\Ai\Ai;
use Throwable;

/**
 * Die Anbieter, für die eine Site einen eigenen Schlüssel hinterlegen kann — alle über das Laravel AI SDK, das sie
 * gleich anspricht. `vision`: Der Anbieter liest Bilder (Alt-Texte); bei den übrigen ist die Funktion aus.
 */
final class Providers
{
    /** @var array<string, array{label: string, vision: bool, privacy: string}> */
    public const ALL = [
        'openai' => ['label' => 'OpenAI', 'vision' => true, 'privacy' => 'https://openai.com/policies/privacy-policy'],
        'anthropic' => ['label' => 'Anthropic (Claude)', 'vision' => true, 'privacy' => 'https://www.anthropic.com/legal/privacy'],
        'gemini' => ['label' => 'Google Gemini', 'vision' => true, 'privacy' => 'https://policies.google.com/privacy'],
        'mistral' => ['label' => 'Mistral AI', 'vision' => true, 'privacy' => 'https://mistral.ai/terms#privacy-policy'],
        'xai' => ['label' => 'xAI (Grok)', 'vision' => true, 'privacy' => 'https://x.ai/legal/privacy-policy'],
        'deepseek' => ['label' => 'DeepSeek', 'vision' => false, 'privacy' => 'https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html'],
        'groq' => ['label' => 'Groq', 'vision' => false, 'privacy' => 'https://groq.com/privacy-policy/'],
        'openrouter' => ['label' => 'OpenRouter', 'vision' => true, 'privacy' => 'https://openrouter.ai/privacy'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function label(string $provider): string
    {
        return self::ALL[$provider]['label'] ?? $provider;
    }

    public static function vision(string $provider): bool
    {
        return self::ALL[$provider]['vision'] ?? false;
    }

    /** Das Modell, das das SDK ohne eigene Angabe nimmt — als Platzhalter im Formular. */
    public static function defaultModel(string $provider): ?string
    {
        if (! isset(self::ALL[$provider])) {
            return null;
        }
        try {
            $instance = Ai::build(['name' => 'basalto-ai-default-'.$provider, 'driver' => $provider, 'key' => '']);

            return method_exists($instance, 'defaultTextModel') ? (string) $instance->defaultTextModel() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
