import apache from 'highlight.js/lib/languages/apache';
import bash from 'highlight.js/lib/languages/bash';
import c from 'highlight.js/lib/languages/c';
import cpp from 'highlight.js/lib/languages/cpp';
import csharp from 'highlight.js/lib/languages/csharp';
import css from 'highlight.js/lib/languages/css';
import diff from 'highlight.js/lib/languages/diff';
import dockerfile from 'highlight.js/lib/languages/dockerfile';
import go from 'highlight.js/lib/languages/go';
import http from 'highlight.js/lib/languages/http';
import ini from 'highlight.js/lib/languages/ini';
import java from 'highlight.js/lib/languages/java';
import javascript from 'highlight.js/lib/languages/javascript';
import json from 'highlight.js/lib/languages/json';
import kotlin from 'highlight.js/lib/languages/kotlin';
import markdown from 'highlight.js/lib/languages/markdown';
import nginx from 'highlight.js/lib/languages/nginx';
import php from 'highlight.js/lib/languages/php';
import powershell from 'highlight.js/lib/languages/powershell';
import python from 'highlight.js/lib/languages/python';
import ruby from 'highlight.js/lib/languages/ruby';
import rust from 'highlight.js/lib/languages/rust';
import scss from 'highlight.js/lib/languages/scss';
import shell from 'highlight.js/lib/languages/shell';
import sql from 'highlight.js/lib/languages/sql';
import swift from 'highlight.js/lib/languages/swift';
import twig from 'highlight.js/lib/languages/twig';
import typescript from 'highlight.js/lib/languages/typescript';
import xml from 'highlight.js/lib/languages/xml';
import yaml from 'highlight.js/lib/languages/yaml';
import { createLowlight } from 'lowlight';

/**
 * Hervorhebung der Codeblöcke (TB 49) — eigener Teil des Bündels, nur geladen, wo Code steht. Lava sieht aus wie Twig
 * und nimmt dessen Grammatik; ohne Sprache rät lowlight unter den gängigen.
 */
const lowlight = createLowlight({
    apache,
    bash,
    c,
    cpp,
    csharp,
    css,
    diff,
    dockerfile,
    go,
    http,
    ini,
    java,
    javascript,
    json,
    kotlin,
    lava: twig,
    markdown,
    nginx,
    php,
    powershell,
    python,
    ruby,
    rust,
    scss,
    shell,
    sql,
    swift,
    typescript,
    xml,
    yaml,
});

const AUTO = [
    'bash',
    'css',
    'javascript',
    'json',
    'php',
    'python',
    'sql',
    'typescript',
    'xml',
    'yaml',
    'lava',
    'diff',
    'ini',
];

type HastNode = {
    type: string;
    value?: string;
    tagName?: string;
    properties?: { className?: string[] | string };
    children?: HastNode[];
};

function toDom(node: HastNode, parent: Node): void {
    if (node.type === 'text') {
        parent.appendChild(document.createTextNode(node.value ?? ''));

        return;
    }
    let target = parent;
    if (node.type === 'element') {
        const span = document.createElement('span');
        const className = node.properties?.className;
        span.className = Array.isArray(className)
            ? className.join(' ')
            : (className ?? '');
        parent.appendChild(span);
        target = span;
    }
    (node.children ?? []).forEach((child) => toDom(child, target));
}

export function highlightInto(code: HTMLElement): void {
    const language = code.dataset.sprache ?? '';
    if (language === 'plaintext') {
        return;
    }
    const text = code.textContent ?? '';
    if (text.length > 20000) {
        return;
    }
    const tree = (language && lowlight.registered(language)
        ? lowlight.highlight(language, text)
        : lowlight.highlightAuto(text, {
              subset: AUTO,
          })) as unknown as HastNode;
    const fragment = document.createDocumentFragment();
    toDom(tree, fragment);
    code.replaceChildren(fragment);
    code.classList.add('hljs');
}
