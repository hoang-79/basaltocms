<script setup lang="ts">
import { computed } from 'vue';
import type { SchemaBlock } from '../types';
import { blockSketch, type SketchPart } from './blockSketch';

/**
 * TB 28: Das Schema eines Blocks als kleine Zeichnung (160 × 100) — aus den Feldern, siehe `blockSketch`.
 * Basalt für Überschriften, Asche für Text und Flächen, Lava für Knöpfe und Akzente. Rein dekorativ: die Beschriftung
 * steht daneben, darum `aria-hidden`.
 */
const props = withDefaults(
    defineProps<{
        block?: SchemaBlock | null;
        /** Ohne Block: das Schema für «Baustein» oder «Lava-Teil» */
        special?: 'snippet' | 'part' | null;
    }>(),
    { block: null, special: null },
);

type Shape =
    | {
          el: 'rect';
          x: number;
          y: number;
          w: number;
          h: number;
          r?: number;
          cls: string;
          dash?: boolean;
      }
    | { el: 'circle'; x: number; y: number; r: number; cls: string }
    | { el: 'path'; d: string; cls: string };

const W = 160;
const H = 100;
const P = 9;

/** Zeichnen und senkrecht mittig setzen — kurze Schemas kleben sonst oben in der Fläche. */
function draw(parts: SketchPart[], x0: number, width: number): Shape[] {
    const { shapes, bottom } = drawFrom(parts, x0, width, 0);
    const shift = Math.max(6, (H - bottom) / 2);

    return parts.some((p) => p.kind === 'data')
        ? drawFrom(parts, x0, width, 11).shapes
        : shapes.map((s) =>
              s.el === 'path'
                  ? { ...s, d: shiftPath(s.d, shift) }
                  : { ...s, y: s.y + shift },
          );
}

/** Absolute Koordinaten einer Pfadangabe verschieben (nur M und absolute Punkte — die Schemas nutzen sonst relative). */
function shiftPath(d: string, dy: number): string {
    return d.replace(
        /M(-?[\d.]+) (-?[\d.]+)/g,
        (_m, x: string, y: string) => `M${x} ${Number(y) + dy}`,
    );
}

function drawFrom(
    parts: SketchPart[],
    x0: number,
    width: number,
    start: number,
): { shapes: Shape[]; bottom: number } {
    const out: Shape[] = [];
    let y = start;
    for (const part of parts) {
        switch (part.kind) {
            case 'eyebrow':
                out.push({
                    el: 'rect',
                    x: x0,
                    y,
                    w: Math.min(30, width * 0.4),
                    h: 3,
                    r: 1.5,
                    cls: 'fill-lava/70',
                });
                y += 7;
                break;
            case 'heading':
                out.push({
                    el: 'rect',
                    x: x0,
                    y,
                    w: width * 0.78,
                    h: 7,
                    r: 1.5,
                    cls: 'fill-basalt',
                });
                y += 12;
                break;
            case 'lines':
                for (let i = 0; i < part.count; i++) {
                    const ratio =
                        i === part.count - 1 && part.count > 1
                            ? 0.62
                            : i % 2
                              ? 0.9
                              : 1;
                    out.push({
                        el: 'rect',
                        x: x0,
                        y,
                        w: width * ratio,
                        h: 3,
                        r: 1.5,
                        cls: 'fill-ash',
                    });
                    y += 6;
                }
                y += 3;
                break;
            case 'quote':
                out.push({
                    el: 'path',
                    d: `M${x0} ${y + 9}q0-9 7-9M${x0 + 9} ${y + 9}q0-9 7-9`,
                    cls: 'stroke-lava fill-none',
                });
                out.push({
                    el: 'rect',
                    x: x0 + 20,
                    y: y + 1,
                    w: width - 20,
                    h: 3,
                    r: 1.5,
                    cls: 'fill-basalt-light',
                });
                out.push({
                    el: 'rect',
                    x: x0 + 20,
                    y: y + 7,
                    w: (width - 20) * 0.7,
                    h: 3,
                    r: 1.5,
                    cls: 'fill-basalt-light',
                });
                y += 16;
                break;
            case 'buttons':
                out.push({
                    el: 'rect',
                    x: x0,
                    y,
                    w: 30,
                    h: 9,
                    r: 4.5,
                    cls: 'fill-lava',
                });
                if (part.count > 1) {
                    out.push({
                        el: 'rect',
                        x: x0 + 34,
                        y,
                        w: 30,
                        h: 9,
                        r: 4.5,
                        cls: 'fill-white stroke-ash',
                    });
                }
                y += 13;
                break;
            case 'image': {
                const h = Math.max(24, Math.min(40, H - y - 8));
                out.push({
                    el: 'rect',
                    x: x0,
                    y,
                    w: width,
                    h,
                    r: 3,
                    cls: 'fill-ash-light',
                });
                out.push({
                    el: 'path',
                    d: `M${x0 + 6} ${y + h - 6}l${width * 0.22} -${h * 0.38}l${width * 0.16} ${h * 0.22}l${width * 0.12} -${h * 0.14}l${width * 0.32} ${h * 0.3}z`,
                    cls: 'fill-ash',
                });
                out.push({
                    el: 'circle',
                    x: x0 + width - 12,
                    y: y + 9,
                    r: 3.5,
                    cls: 'fill-white',
                });
                y += h + 5;
                break;
            }
            case 'gallery': {
                const s = (width - 8) / 3;
                for (let i = 0; i < 3; i++) {
                    out.push({
                        el: 'rect',
                        x: x0 + i * (s + 4),
                        y,
                        w: s,
                        h: s * 0.75,
                        r: 2,
                        cls: 'fill-ash-light',
                    });
                }
                y += s * 0.75 + 5;
                break;
            }
            case 'cards':
            case 'stats':
            case 'steps': {
                const gap = 5;
                const n = part.count;
                const cw = (width - gap * (n - 1)) / n;
                for (let i = 0; i < n; i++) {
                    const cx = x0 + i * (cw + gap);
                    if (part.kind === 'cards') {
                        out.push({
                            el: 'rect',
                            x: cx,
                            y,
                            w: cw,
                            h: 30,
                            r: 3,
                            cls: 'fill-white stroke-ash',
                        });
                        if (part.image) {
                            out.push({
                                el: 'rect',
                                x: cx,
                                y,
                                w: cw,
                                h: 11,
                                r: 3,
                                cls: 'fill-ash-light',
                            });
                        }
                        const ty = y + (part.image ? 15 : 6);
                        out.push({
                            el: 'rect',
                            x: cx + 4,
                            y: ty,
                            w: cw * 0.6,
                            h: 4,
                            r: 1.5,
                            cls: 'fill-basalt',
                        });
                        out.push({
                            el: 'rect',
                            x: cx + 4,
                            y: ty + 7,
                            w: cw - 8,
                            h: 2.5,
                            r: 1,
                            cls: 'fill-ash',
                        });
                    } else if (part.kind === 'stats') {
                        out.push({
                            el: 'rect',
                            x: cx + cw * 0.15,
                            y,
                            w: cw * 0.7,
                            h: 10,
                            r: 2,
                            cls: 'fill-lava/80',
                        });
                        out.push({
                            el: 'rect',
                            x: cx + cw * 0.1,
                            y: y + 14,
                            w: cw * 0.8,
                            h: 3,
                            r: 1.5,
                            cls: 'fill-ash',
                        });
                    } else {
                        out.push({
                            el: 'circle',
                            x: cx + 6,
                            y: y + 6,
                            r: 5.5,
                            cls: 'fill-lava/80',
                        });
                        out.push({
                            el: 'rect',
                            x: cx,
                            y: y + 15,
                            w: cw * 0.85,
                            h: 3.5,
                            r: 1.5,
                            cls: 'fill-basalt-light',
                        });
                        out.push({
                            el: 'rect',
                            x: cx,
                            y: y + 21,
                            w: cw * 0.7,
                            h: 2.5,
                            r: 1,
                            cls: 'fill-ash',
                        });
                    }
                }
                y +=
                    part.kind === 'cards'
                        ? 34
                        : part.kind === 'stats'
                          ? 21
                          : 27;
                break;
            }
            case 'accordion':
                for (let i = 0; i < part.count; i++) {
                    out.push({
                        el: 'rect',
                        x: x0,
                        y,
                        w: width,
                        h: 9,
                        r: 2,
                        cls: 'fill-white stroke-ash',
                    });
                    out.push({
                        el: 'rect',
                        x: x0 + 4,
                        y: y + 3,
                        w: width * 0.55,
                        h: 3,
                        r: 1.5,
                        cls: 'fill-basalt-light',
                    });
                    out.push({
                        el: 'path',
                        d: `M${x0 + width - 9} ${y + 3.5}l2.5 2.5l2.5-2.5`,
                        cls: 'stroke-lava fill-none',
                    });
                    y += 12;
                }
                break;
            case 'form':
                out.push({
                    el: 'rect',
                    x: x0,
                    y,
                    w: width,
                    h: 8,
                    r: 2,
                    cls: 'fill-white stroke-ash',
                });
                out.push({
                    el: 'rect',
                    x: x0,
                    y: y + 11,
                    w: width,
                    h: 14,
                    r: 2,
                    cls: 'fill-white stroke-ash',
                });
                out.push({
                    el: 'rect',
                    x: x0,
                    y: y + 29,
                    w: 28,
                    h: 8,
                    r: 4,
                    cls: 'fill-lava',
                });
                y += 41;
                break;
            case 'list':
                for (let i = 0; i < part.count; i++) {
                    out.push({
                        el: 'rect',
                        x: x0,
                        y,
                        w: 12,
                        h: 9,
                        r: 1.5,
                        cls: 'fill-ash-light',
                    });
                    out.push({
                        el: 'rect',
                        x: x0 + 16,
                        y: y + 1,
                        w: width * 0.55,
                        h: 3,
                        r: 1.5,
                        cls: 'fill-basalt-light',
                    });
                    out.push({
                        el: 'rect',
                        x: x0 + 16,
                        y: y + 6,
                        w: width * 0.35,
                        h: 2.5,
                        r: 1,
                        cls: 'fill-ash',
                    });
                    y += 13;
                }
                break;
            case 'nested':
                out.push({
                    el: 'rect',
                    x: x0,
                    y,
                    w: width,
                    h: 26,
                    r: 3,
                    cls: 'fill-none stroke-lava',
                    dash: true,
                });
                out.push({
                    el: 'rect',
                    x: x0 + 5,
                    y: y + 5,
                    w: width - 10,
                    h: 6,
                    r: 1.5,
                    cls: 'fill-ash-light',
                });
                out.push({
                    el: 'rect',
                    x: x0 + 5,
                    y: y + 14,
                    w: width - 10,
                    h: 6,
                    r: 1.5,
                    cls: 'fill-ash-light',
                });
                y += 30;
                break;
            case 'data':
                out.push({
                    el: 'rect',
                    x: x0,
                    y: y - 2,
                    w: width,
                    h: H - y - 7,
                    r: 4,
                    cls: 'fill-none stroke-ash',
                    dash: true,
                });
                out.push({
                    el: 'path',
                    d: `M${x0 + width / 2 - 9} ${H / 2 + 2}a9 9 0 1 1 3 6.5M${x0 + width / 2 - 10} ${H / 2 + 9}l1 -6 5.5 2`,
                    cls: 'stroke-lava fill-none',
                });
                y = H;
                break;
        }
    }

    return { shapes: out, bottom: y };
}

const shapes = computed<Shape[]>(() => {
    if (props.special === 'snippet') {
        // Baustein: eine Gruppe von Blöcken, gerahmt in Lava
        return [
            {
                el: 'rect',
                x: P,
                y: 10,
                w: W - 2 * P,
                h: H - 20,
                r: 5,
                cls: 'fill-none stroke-lava',
                dash: true,
            },
            {
                el: 'rect',
                x: P + 8,
                y: 20,
                w: W - 2 * P - 16,
                h: 16,
                r: 3,
                cls: 'fill-ash-light',
            },
            {
                el: 'rect',
                x: P + 8,
                y: 41,
                w: W - 2 * P - 16,
                h: 16,
                r: 3,
                cls: 'fill-ash-light',
            },
            {
                el: 'rect',
                x: P + 8,
                y: 62,
                w: W - 2 * P - 16,
                h: 16,
                r: 3,
                cls: 'fill-ash-light',
            },
        ];
    }
    if (props.special === 'part') {
        // Lava-Teil: Code in spitzen Klammern
        return [
            {
                el: 'path',
                d: 'M58 34l-16 16 16 16M102 34l16 16-16 16',
                cls: 'stroke-lava fill-none',
            },
            {
                el: 'path',
                d: 'M88 30l-16 40',
                cls: 'stroke-basalt-light fill-none',
            },
        ];
    }
    if (!props.block) {
        return [];
    }
    const sketch = blockSketch(props.block);
    if (sketch.layout === 'split') {
        const split = 84;

        return [
            ...draw(sketch.main, P, split - P - 6),
            ...draw(sketch.side, split, W - split - P),
        ];
    }

    return draw(sketch.main, P, W - 2 * P);
});
</script>

<template>
    <svg
        :viewBox="`0 0 ${W} ${H}`"
        class="block h-auto w-full overflow-hidden"
        aria-hidden="true"
        stroke-width="1.2"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <template v-for="(s, i) in shapes" :key="i">
            <rect
                v-if="s.el === 'rect'"
                :x="s.x"
                :y="s.y"
                :width="Math.max(0, s.w)"
                :height="Math.max(0, s.h)"
                :rx="s.r ?? 0"
                :class="s.cls"
                :stroke-dasharray="s.dash ? '3 3' : undefined"
            />
            <circle
                v-else-if="s.el === 'circle'"
                :cx="s.x"
                :cy="s.y"
                :r="s.r"
                :class="s.cls"
            />
            <path v-else :d="s.d" :class="s.cls" stroke-width="2.2" />
        </template>
    </svg>
</template>
