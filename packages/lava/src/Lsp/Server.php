<?php

declare(strict_types=1);

namespace Basalto\Lava\Lsp;

use Basalto\Lava\Diagnostics\Diagnostic;
use Basalto\Lava\Theme\FileSource;
use Throwable;

/**
 * Die dünne LSP-Schicht über dem Sprachdienst: JSON-RPC 2.0 mit «Content-Length»-Kopf über zwei beliebige Streams
 * (stdio im Befehl, Speicher-Streams im Test). Der Editor schickt den ganzen Text bei jeder Änderung (volle
 * Synchronisierung); offene Puffer liegen vor den Dateien des Themes. Ein Theme ist der nächste Ordner mit
 * «theme.lava» über der Datei — kompiliert wird immer das ganze Theme, Befunde gehen an jede betroffene Datei.
 */
final class Server
{
    /** @var array<string, int> Art eines Vorschlags → CompletionItemKind des Protokolls */
    private const COMPLETION_KINDS = [
        LanguageService::KEYWORD => 14, LanguageService::PROPERTY => 10, LanguageService::FIELD_TYPE => 25,
        LanguageService::FUNCTION => 3, LanguageService::TAG => 14, LanguageService::REFERENCE => 18, LanguageService::VALUE => 12,
    ];

    private bool $shutdown = false;

    /** @var array<string, string> absoluter Pfad → ungespeicherter Text des Editors */
    private array $buffers = [];

    /** @var array<string, list<string>> Theme (Wurzel und Einstieg) → URIs, an die zuletzt Befunde gingen */
    private array $published = [];

    /**
     * @param  resource  $input
     * @param  resource  $output
     */
    public function __construct(private readonly LanguageService $service, private $input, private $output) {}

    /** Liest Nachrichten bis «exit» oder Ende der Eingabe. Rückgabe: 0 nach «shutdown», sonst 1 (wie im Protokoll). */
    public function run(): int
    {
        while (($body = $this->read()) !== null) {
            $exit = $this->handle($body);
            if ($exit !== null) {
                return $exit;
            }
        }

        return $this->shutdown ? 0 : 1;
    }

    public static function pathFromUri(string $uri): ?string
    {
        if (! str_starts_with($uri, 'file://')) {
            return null;
        }
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        if (preg_match('#^/[A-Za-z]:/#', $path)) {
            $path = substr($path, 1);
        }

        return $path === '' ? null : $path;
    }

    public static function uriFromPath(string $path): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', str_replace('\\', '/', $path))));

        return 'file://'.(str_starts_with($encoded, '/') ? '' : '/').$encoded;
    }

    /** Eine Nachricht verarbeiten; eine Zahl heisst: beenden mit diesem Code. */
    private function handle(string $body): ?int
    {
        $message = json_decode($body, true);
        if (! is_array($message)) {
            $this->send(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Ungültiges JSON']]);

            return null;
        }
        $method = is_string($message['method'] ?? null) ? $message['method'] : null;
        if ($method === null) {
            return null;
        }
        if ($method === 'exit') {
            return $this->shutdown ? 0 : 1;
        }
        $isRequest = array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        if ($this->shutdown && $isRequest) {
            $this->send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32600, 'message' => 'Der Server wird beendet']]);

            return null;
        }
        try {
            $result = match ($method) {
                'initialize' => $this->capabilities(),
                'shutdown' => $this->shutdown = true,
                'textDocument/didOpen', 'textDocument/didChange', 'textDocument/didSave', 'textDocument/didClose' => $this->synchronize($method, $params),
                'textDocument/completion' => $this->completion($params),
                'textDocument/definition' => $this->definition($params),
                'textDocument/hover' => $this->hover($params),
                default => $this->unknown($method, $isRequest, $id),
            };
        } catch (Throwable $e) {
            if ($isRequest) {
                $this->send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => $e->getMessage()]]);
            } else {
                $this->notify('window/logMessage', ['type' => 1, 'message' => 'Lava: '.$e->getMessage()]);
            }

            return null;
        }
        if ($isRequest && $result !== false) {
            $this->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $method === 'shutdown' ? null : $result]);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function capabilities(): array
    {
        return [
            'capabilities' => [
                'textDocumentSync' => ['openClose' => true, 'change' => 1, 'save' => ['includeText' => true]],
                'completionProvider' => ['triggerCharacters' => [' ', ':', '|', '%', '{', ',', '"', '>']],
                'definitionProvider' => true,
                'hoverProvider' => true,
            ],
            'serverInfo' => ['name' => 'basalto-lava', 'version' => '1'],
        ];
    }

    /** Unbekannte Anfragen bekommen «Methode nicht gefunden», unbekannte Mitteilungen ($/…, workspace/…) nichts. */
    private function unknown(string $method, bool $isRequest, mixed $id): false
    {
        if ($isRequest) {
            $this->send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => sprintf('Methode «%s» kennt der Lava-Server nicht', $method)]]);
        }

        return false;
    }

    /** @param  array<mixed>  $params */
    private function synchronize(string $method, array $params): null
    {
        $document = is_array($params['textDocument'] ?? null) ? $params['textDocument'] : [];
        $path = self::pathFromUri(is_string($document['uri'] ?? null) ? $document['uri'] : '');
        if ($path === null) {
            return null;
        }
        if ($method === 'textDocument/didOpen' && is_string($document['text'] ?? null)) {
            $this->buffers[$path] = $document['text'];
        } elseif ($method === 'textDocument/didChange' && is_array($params['contentChanges'] ?? null)) {
            $changes = array_values($params['contentChanges']);
            $last = $changes[count($changes) - 1] ?? null;
            if (is_array($last) && is_string($last['text'] ?? null)) {
                $this->buffers[$path] = $last['text'];
            }
        } elseif ($method === 'textDocument/didSave' && is_string($params['text'] ?? null)) {
            $this->buffers[$path] = $params['text'];
        } elseif ($method === 'textDocument/didClose') {
            unset($this->buffers[$path]);
        }
        $this->publish($path);

        return null;
    }

    /** Das ganze Theme kompilieren und Befunde an jede Datei schicken — leere Liste, wo vorher etwas war. */
    private function publish(string $path): void
    {
        $theme = $this->locate($path);
        if ($theme === null) {
            return;
        }
        $source = $this->source($theme['root']);
        $sent = [];
        foreach ($this->service->diagnostics($source, $theme['entry']) as $file => $diagnostics) {
            $text = $source->has($file) ? $source->read($file) : '';
            $uri = self::uriFromPath($theme['root'].'/'.$file);
            $this->notify('textDocument/publishDiagnostics', ['uri' => $uri, 'diagnostics' => array_map(fn (Diagnostic $d): array => $this->toLsp($d, $text), $diagnostics)]);
            $sent[] = $uri;
        }
        $key = $theme['root']."\0".$theme['entry'];
        $clear = array_diff(array_merge($this->published[$key] ?? [], [self::uriFromPath($path)]), $sent);
        foreach (array_unique($clear) as $uri) {
            $this->notify('textDocument/publishDiagnostics', ['uri' => $uri, 'diagnostics' => []]);
        }
        $this->published[$key] = $sent;
    }

    /**
     * Ein Befund im Format des Protokolls: Zeile und Spalte ab 0 in UTF-16, markiert wird das Wort an der Stelle
     * (oder der Rest der Zeile).
     *
     * @return array<string, mixed>
     */
    private function toLsp(Diagnostic $diagnostic, string $text): array
    {
        $line = max(1, $diagnostic->line);
        $lineText = rtrim(explode("\n", $text)[$line - 1] ?? '', "\r");
        $startByte = max(1, $diagnostic->column);
        $endByte = $startByte - 1;
        while ($endByte < strlen($lineText) && (ctype_alnum($lineText[$endByte]) || in_array($lineText[$endByte], ['_', '-', '.'], true) || ord($lineText[$endByte]) >= 0x80)) {
            $endByte++;
        }
        if ($endByte === $startByte - 1) {
            $endByte = strlen(rtrim($lineText));
        }
        $start = Columns::toUtf16($lineText, $startByte);
        $end = max($start, Columns::toUtf16($lineText, $endByte + 1));

        return [
            'range' => ['start' => ['line' => $line - 1, 'character' => $start], 'end' => ['line' => $line - 1, 'character' => $end]],
            'severity' => $diagnostic->isError() ? 1 : 2,
            'source' => 'lava',
            'message' => LanguageService::message($diagnostic),
        ];
    }

    /**
     * @param  array<mixed>  $params
     * @return array<string, mixed>|null
     */
    private function completion(array $params): ?array
    {
        $at = $this->position($params);
        if ($at === null) {
            return null;
        }
        $items = array_map(static fn (array $c): array => array_filter([
            'label' => $c['label'],
            'kind' => self::COMPLETION_KINDS[$c['kind']] ?? 1,
            'detail' => $c['detail'],
            'documentation' => $c['documentation'] !== null ? ['kind' => 'markdown', 'value' => $c['documentation']] : null,
        ], static fn (mixed $v): bool => $v !== null), $this->service->completions($at['source'], $at['file'], $at['line'], $at['column'], $at['entry']));

        return ['isIncomplete' => false, 'items' => $items];
    }

    /**
     * @param  array<mixed>  $params
     * @return array<string, mixed>|null
     */
    private function definition(array $params): ?array
    {
        $at = $this->position($params);
        $found = $at === null ? null : $this->service->definition($at['source'], $at['file'], $at['line'], $at['column'], $at['entry']);
        if ($at === null || $found === null) {
            return null;
        }
        $text = $at['source']->has($found['file']) ? $at['source']->read($found['file']) : '';
        $character = Columns::toUtf16(explode("\n", $text)[$found['line'] - 1] ?? '', $found['column']);
        $position = ['line' => $found['line'] - 1, 'character' => $character];

        return ['uri' => self::uriFromPath($at['root'].'/'.$found['file']), 'range' => ['start' => $position, 'end' => $position]];
    }

    /**
     * @param  array<mixed>  $params
     * @return array<string, mixed>|null
     */
    private function hover(array $params): ?array
    {
        $at = $this->position($params);
        $text = $at === null ? null : $this->service->hover($at['source'], $at['file'], $at['line'], $at['column'], $at['entry']);

        return $text === null ? null : ['contents' => ['kind' => 'markdown', 'value' => $text]];
    }

    /**
     * Dokument und Stelle einer Anfrage, umgerechnet auf Lava (Zeile und Byte-Spalte ab 1).
     *
     * @param  array<mixed>  $params
     * @return array{source: OverlaySource, root: string, entry: string, file: string, line: int, column: int}|null
     */
    private function position(array $params): ?array
    {
        $document = is_array($params['textDocument'] ?? null) ? $params['textDocument'] : [];
        $position = is_array($params['position'] ?? null) ? $params['position'] : [];
        $path = self::pathFromUri(is_string($document['uri'] ?? null) ? $document['uri'] : '');
        $theme = $path === null ? null : $this->locate($path);
        if ($path === null || $theme === null) {
            return null;
        }
        $source = $this->source($theme['root']);
        $file = substr($path, strlen($theme['root']) + 1);
        if (! $source->has($file)) {
            return null;
        }
        $line = (is_int($position['line'] ?? null) ? $position['line'] : 0) + 1;
        $lineText = rtrim(explode("\n", $source->read($file))[$line - 1] ?? '', "\r");
        $column = Columns::toBytes($lineText, is_int($position['character'] ?? null) ? $position['character'] : 0);

        return ['source' => $source, 'root' => $theme['root'], 'entry' => $theme['entry'], 'file' => $file, 'line' => $line, 'column' => $column];
    }

    /**
     * Das Theme einer Datei: der nächste Ordner darüber mit «theme.lava». Eine einzelne .lava-Datei ohne Theme
     * wird für sich geprüft; Markup-Dateien ohne Theme bleiben ohne Sprachdienst.
     *
     * @return array{root: string, entry: string}|null
     */
    private function locate(string $path): ?array
    {
        $directory = dirname($path);
        for ($depth = 0; $depth < 32; $depth++) {
            if (isset($this->buffers[$directory.'/theme.lava']) || is_file($directory.'/theme.lava')) {
                return ['root' => $directory, 'entry' => 'theme.lava'];
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        return str_ends_with($path, '.lava') ? ['root' => dirname($path), 'entry' => basename($path)] : null;
    }

    /** Die Dateien des Themes mit den offenen Puffern davor. */
    private function source(string $root): OverlaySource
    {
        $buffers = [];
        foreach ($this->buffers as $path => $text) {
            if (str_starts_with($path, $root.'/')) {
                $relative = substr($path, strlen($root) + 1);
                if (FileSource::isInside($relative)) {
                    $buffers[$relative] = $text;
                }
            }
        }

        return new OverlaySource(new FileSource($root), $buffers);
    }

    /** Liest eine Nachricht (Kopfzeilen, Leerzeile, Rumpf mit Content-Length Bytes); null am Ende der Eingabe. */
    private function read(): ?string
    {
        $length = null;
        while (true) {
            $header = fgets($this->input);
            if ($header === false) {
                return null;
            }
            $header = trim($header);
            if ($header === '') {
                if ($length !== null) {
                    break;
                }

                continue;
            }
            if (preg_match('/^Content-Length:\s*(\d+)$/i', $header, $m)) {
                $length = (int) $m[1];
            }
        }
        $body = '';
        while (strlen($body) < $length) {
            $chunk = fread($this->input, max(1, $length - strlen($body)));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $body .= $chunk;
        }

        return $body;
    }

    /** @param  array<string, mixed>  $params */
    private function notify(string $method, array $params): void
    {
        $this->send(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params]);
    }

    /** @param  array<string, mixed>  $message */
    private function send(array $message): void
    {
        $json = (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        fwrite($this->output, sprintf("Content-Length: %d\r\n\r\n%s", strlen($json), $json));
        fflush($this->output);
    }
}
