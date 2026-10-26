import type { CommentStatus } from './types';

/** Zustandsfarbe eines Kommentars: wartet Lava, freigegeben grün, Spam karmin, gelöscht grau. */
export function commentTone(
    status: CommentStatus,
): 'grey' | 'lava' | 'success' | 'danger' {
    switch (status) {
        case 'pending':
            return 'lava';
        case 'approved':
            return 'success';
        case 'spam':
            return 'danger';
        default:
            return 'grey';
    }
}
