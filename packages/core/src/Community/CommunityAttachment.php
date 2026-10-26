<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ein Anhang eines Besuchers (CR-006 FO-K6). `subject_type`/`subject_id` binden ihn an einen Inhalt eines Moduls
 * (`forum_post`, 12); leer heisst «hochgeladen, noch nicht verwendet».
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $customer_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $path
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property Carbon|null $created_at
 */
final class CommunityAttachment extends Model
{
    use BelongsToSite;

    protected $table = 'community_attachments';

    protected $fillable = ['site_id', 'customer_id', 'subject_type', 'subject_id', 'path', 'name', 'mime', 'size', 'width', 'height'];

    protected function casts(): array
    {
        return ['subject_id' => 'integer', 'size' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /** Die Adresse — relativ, damit sie in jedem Host der Site gilt. */
    public function url(): string
    {
        return '/community/anhang/'.$this->id.'/'.basename($this->path);
    }

    public function isAssigned(): bool
    {
        return $this->subject_type !== '';
    }

    /** @return array{id: int, url: string, name: string, size: int, image: bool, width: int|null, height: int|null} */
    public function toClient(): array
    {
        return ['id' => $this->id, 'url' => $this->url(), 'name' => $this->name, 'size' => $this->size, 'image' => $this->isImage(), 'width' => $this->width, 'height' => $this->height];
    }
}
