<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

/**
 * Löschlauf der Community (N-05): hochgeladene, aber nie verwendete Anhänge nach einem Tag weg.
 */
final class CommunityCleanup
{
    /** @return array<string, int> nur, wenn etwas wegkam */
    public function __invoke(): array
    {
        $attachments = app(Attachments::class);
        $count = 0;
        foreach (CommunityAttachment::query()->withoutGlobalScopes()->where('subject_type', '')->where('created_at', '<', now()->subDay())->get() as $attachment) {
            $attachments->delete($attachment);
            $count++;
        }

        return $count > 0 ? ['Ungenutzte Anhänge' => $count] : [];
    }
}
