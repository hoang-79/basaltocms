<?php

declare(strict_types=1);

namespace Basalto\Modules\Comments;

/** Der Weg eines Kommentars (M-27): wartet auf Freigabe, freigegeben, von Hand als Spam markiert oder gelöscht. */
enum CommentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Spam = 'spam';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('wartet'),
            self::Approved => __('freigegeben'),
            self::Spam => __('Spam'),
            self::Deleted => __('gelöscht'),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
