<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eine Konversation (CR-006 FO-K11): private Nachrichten zwischen Kundenkonten einer Site, mit mehreren Teilnehmern.
 * Sie gehört zum Konto, nicht zu einem Modul.
 *
 * @property int $id
 * @property int $site_id
 * @property string $subject
 * @property int|null $customer_id
 * @property int|null $last_customer_id
 * @property Carbon|null $last_message_at
 * @property int $messages
 * @property Carbon|null $created_at
 */
final class Conversation extends Model
{
    use BelongsToSite;

    protected $table = 'community_conversations';

    protected $fillable = ['site_id', 'subject', 'customer_id'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'messages' => 'integer'];
    }

    /** @return HasMany<ConversationMessage, $this> */
    public function messageRows(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->withoutGlobalScopes();
    }
}
