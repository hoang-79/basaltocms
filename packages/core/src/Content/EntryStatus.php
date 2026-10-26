<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

/** Zustände einer Sprachfassung (Statusmodell Inhalt, R-22). */
enum EntryStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::InReview],
            self::InReview => [self::Approved, self::Draft],
            self::Approved => [self::Scheduled, self::Published, self::Draft],
            self::Scheduled => [self::Published, self::Approved],
            self::Published => [self::Archived],
            self::Archived => [],
        };
    }

    public function canBecome(self $status): bool
    {
        return in_array($status, $this->next(), true);
    }

    public function isLive(): bool
    {
        return $this === self::Published;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Entwurf'),
            self::InReview => __('In Prüfung'),
            self::Approved => __('Freigegeben'),
            self::Scheduled => __('Geplant'),
            self::Published => __('Veröffentlicht'),
            self::Archived => __('Archiviert'),
        };
    }
}
