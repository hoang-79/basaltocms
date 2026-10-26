/**
 * Aus der Adresse eines Videos die Adresse für die Einbettung: YouTube (über youtube-nocookie.com) und Vimeo — sonst
 * null. Der Server prüft die Hosts noch einmal.
 */
export function embedFrom(
    input: string,
): { provider: 'youtube' | 'vimeo'; src: string } | null {
    let url: URL;
    try {
        url = new URL(input.trim());
    } catch {
        return null;
    }
    if (url.protocol !== 'https:' && url.protocol !== 'http:') {
        return null;
    }
    const host = url.hostname.replace(/^www\./, '');
    let id: string | null = null;
    if (host === 'youtu.be') {
        id = url.pathname.slice(1);
    } else if (host === 'youtube.com' || host === 'm.youtube.com') {
        id =
            url.searchParams.get('v') ??
            /^\/(?:embed|shorts)\/([^/?#]+)/.exec(url.pathname)?.[1] ??
            null;
    }
    if (id !== null && /^[A-Za-z0-9_-]{6,20}$/.test(id)) {
        return {
            provider: 'youtube',
            src: `https://www.youtube-nocookie.com/embed/${id}`,
        };
    }
    if (host === 'vimeo.com' || host === 'player.vimeo.com') {
        const match = /\/(?:video\/)?(\d{5,12})/.exec(url.pathname);
        if (match) {
            return {
                provider: 'vimeo',
                src: `https://player.vimeo.com/video/${match[1]}`,
            };
        }
    }

    return null;
}

/** Die Emojis der Auswahl — häufige, ohne Hauttöne, damit die Liste kurz bleibt. */
export const EMOJIS = [
    '😀',
    '😃',
    '😄',
    '😁',
    '😅',
    '😂',
    '🙂',
    '😉',
    '😊',
    '😍',
    '🤔',
    '😎',
    '😐',
    '😕',
    '😢',
    '😮',
    '😴',
    '🙄',
    '😬',
    '🤯',
    '🥳',
    '😇',
    '🤗',
    '🙈',
    '👍',
    '👎',
    '👏',
    '🙏',
    '💪',
    '👀',
    '🎉',
    '🔥',
    '✅',
    '❌',
    '⚠️',
    '💡',
    '❤️',
    '⭐',
    '🚀',
    '🐛',
    '🔧',
    '📌',
    '📎',
    '📝',
    '💬',
    '❓',
    '❗',
    '☕',
];
