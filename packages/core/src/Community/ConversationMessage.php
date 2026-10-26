<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eine Nachricht in einer Konversation — Text als TipTap-Dokument, gesäubert wie Beiträge ({@see CommunityRichText}).
 * Nach einer Meldung kann die Moderation sie entfernen; dann bleibt nur die Stelle.
 *
 * @property int $id
 * @property int $site_id
 * @property int $conversation_id
 * @property int|null $customer_id
 * @property array<string, mixed>|null $body
 * @property Carbon|null $removed_at
 * @property Carbon|null $created_at
 */
final class ConversationMessage extends Model
{
    use BelongsToSite;

    protected $table = 'community_conversation_messages';

    protected $fillable = ['site_id', 'conversation_id', 'customer_id', 'body'];

    protected function casts(): array
    {
        return ['body' => 'array', 'removed_at' => 'datetime'];
    }
}
