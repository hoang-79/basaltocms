<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use App\Models\User;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Sites\BelongsToSite;
use Basalto\Core\Support\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Das öffentliche Profil eines Kundenkontos (CR-006 FO-K2): Anzeigename (je Site eindeutig, ohne Rücksicht auf
 * Gross-/Kleinschreibung über `name_key`), Profilbild, Signatur, Beitragszahl, letzte Aktivität. Mit `user_id` ist das
 * Konto mit einem Team-Benutzer verknüpft (FO-K14) und trägt das Abzeichen «Team».
 *
 * @property int $id
 * @property int $site_id
 * @property int $customer_id
 * @property int|null $user_id
 * @property string $name
 * @property string $name_key
 * @property string|null $avatar_path
 * @property string|null $signature
 * @property int $posts
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $muted_at
 * @property Carbon|null $muted_until
 * @property Carbon|null $banned_at
 * @property Carbon|null $banned_until
 * @property string|null $ban_reason
 * @property string|null $cover_path
 * @property string|null $about
 * @property array<string, string>|null $fields
 * @property int $reactions_received
 * @property Carbon|null $created_at
 */
final class CommunityProfile extends Model
{
    use BelongsToSite;

    protected $table = 'community_profiles';

    protected $fillable = ['site_id', 'customer_id', 'user_id', 'name', 'name_key', 'signature'];

    protected function casts(): array
    {
        return ['last_activity_at' => 'datetime', 'posts' => 'integer', 'muted_at' => 'datetime', 'muted_until' => 'datetime', 'banned_at' => 'datetime', 'banned_until' => 'datetime', 'fields' => 'array', 'reactions_received' => 'integer'];
    }

    /** TB 39 (FO-17): gesperrt — ohne Ende, bis die Moderation die Sperre aufhebt */
    public function isBanned(): bool
    {
        return $this->banned_at !== null && ($this->banned_until === null || $this->banned_until->isFuture());
    }

    /** Schreibsperre: lesen ja, schreiben, reagieren und abstimmen nicht (eine Sperre schliesst sie ein). */
    public function isMuted(): bool
    {
        return $this->isBanned() || ($this->muted_at !== null && ($this->muted_until === null || $this->muted_until->isFuture()));
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTeam(): bool
    {
        return $this->user_id !== null;
    }

    /** Die Adresse des Profilbilds oder null (dann Initialen). */
    public function avatarUrl(): ?string
    {
        return is_string($this->avatar_path) && $this->avatar_path !== ''
            ? route('basalto.community.avatar', ['profile' => $this->id, 'file' => basename($this->avatar_path)], false) : null;
    }

    /** TB 40: die Adresse des Cover-Fotos oder null. */
    public function coverUrl(): ?string
    {
        return is_string($this->cover_path) && $this->cover_path !== ''
            ? route('basalto.community.cover', ['profile' => $this->id, 'file' => basename($this->cover_path)], false) : null;
    }

    /** Das Stück der Profiladresse: `12-anna-beispiel`. */
    public function pathSegment(): string
    {
        return $this->customer_id.'-'.(Str::slug($this->name, '-', 'de') ?: 'mitglied');
    }

    /** Zwei Buchstaben für das Bild ohne Foto. */
    public function initials(): string
    {
        return Text::initials($this->name);
    }
}
