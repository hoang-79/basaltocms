import { describe, expect, it } from 'vitest';
import { embedFrom } from './embed';

describe('embedFrom', () => {
    it('erkennt YouTube in allen üblichen Formen und nimmt youtube-nocookie', () => {
        for (const url of [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://youtube.com/shorts/dQw4w9WgXcQ',
        ]) {
            expect(embedFrom(url)).toEqual({
                provider: 'youtube',
                src: 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            });
        }
    });

    it('erkennt Vimeo', () => {
        expect(embedFrom('https://vimeo.com/76979871')).toEqual({
            provider: 'vimeo',
            src: 'https://player.vimeo.com/video/76979871',
        });
    });

    it('lehnt andere Adressen ab', () => {
        expect(embedFrom('https://example.com/watch?v=dQw4w9WgXcQ')).toBeNull();
        expect(embedFrom('javascript:alert(1)')).toBeNull();
        expect(embedFrom('kein link')).toBeNull();
    });
});
