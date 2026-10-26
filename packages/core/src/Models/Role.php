<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Access\RoleDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine eigene oder angepasste Rolle (CR-005, R-03) eines Kontos. Zugewiesen über den Schlüssel in `site_user.role`.
 *
 * @property int $id
 * @property int|null $account_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property array<string, int> $levels
 * @property array{mode: string, codes?: list<string>}|null $languages
 */
final class Role extends Model
{
    protected $fillable = ['account_id', 'key', 'name', 'description', 'levels', 'languages'];

    protected function casts(): array
    {
        return ['levels' => 'array', 'languages' => 'array'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function definition(): RoleDefinition
    {
        return new RoleDefinition($this->key, $this->name, array_map('intval', $this->levels), $this->languages);
    }
}
