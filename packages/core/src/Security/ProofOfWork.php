<?php

declare(strict_types=1);

namespace Basalto\Core\Security;

use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Spamschutz ohne Rätsel und ohne Drittanbieter (TB 50, CR-009 Nr. 11): Der Browser löst beim Ausfüllen eine kleine
 * Rechenaufgabe («Proof of Work», Bauart wie ALTCHA). Der Server wählt eine Zahl bis `max`, gibt nur den Hash von
 * Salz und Zahl heraus (unterschrieben); der Browser probiert die Zahlen durch, bis der Hash stimmt — eine knappe
 * Sekunde für einen Menschen, teuer für Tausende Bot-Anfragen. Jede Aufgabe gilt zehn Minuten und genau einmal.
 *
 * Ohne Skript stellt das Formular eine Rechenfrage («4 + 7»); ihre Unterschrift steht im (zwischengespeicherten)
 * Markup. Das ist schwächer, hält aber einfache Bots ab und schliesst niemanden aus.
 */
final class ProofOfWork
{
    public const FIELD = '_pow';

    public const ANSWER = '_rechnung';

    public const QUESTION = '_rechnung_t';

    /** Bereiche mit eigenem Schalter in den Einstellungen. */
    public const AREAS = ['formular' => 'spam.pow_forms', 'kommentar' => 'spam.pow_forms', 'konto' => 'spam.pow_accounts', 'registrierung' => 'spam.pow_accounts'];

    private const MINUTES = 10;

    public function __construct(private readonly Settings $settings) {}

    public function enabled(?Site $site, string $area): bool
    {
        $key = self::AREAS[$area] ?? null;

        return $key !== null && (bool) config('basalto.spam.pow', true) && (bool) $this->settings->get($key, true, $site);
    }

    /**
     * Eine neue Aufgabe.
     *
     * @return array{algorithm: string, challenge: string, salt: string, signature: string, maxnumber: int}
     */
    public function challenge(string $area): array
    {
        $max = max(1000, (int) config('basalto.spam.pow_max', 50000));
        $salt = bin2hex(random_bytes(12)).'?bereich='.rawurlencode($area).'&bis='.(now()->getTimestamp() + self::MINUTES * 60);
        $challenge = hash('sha256', $salt.random_int(0, $max));

        return ['algorithm' => 'SHA-256', 'challenge' => $challenge, 'salt' => $salt, 'signature' => self::sign($challenge), 'maxnumber' => $max];
    }

    /** Null, wenn die Lösung (oder die Rechenfrage ohne Skript) stimmt — sonst der Grund. */
    public function reason(Request $request, string $area): ?string
    {
        $payload = (string) $request->input(self::FIELD, '');
        if ($payload === '') {
            return $this->arithmetic($request, $area) ? null : 'pruefung-fehlt';
        }
        $data = json_decode((string) base64_decode($payload, true), true);
        if (! is_array($data) || ! is_string($data['salt'] ?? null) || ! is_string($data['challenge'] ?? null) || ! is_string($data['signature'] ?? null) || ! is_numeric($data['number'] ?? null)) {
            return 'pruefung-ungueltig';
        }
        parse_str((string) parse_url('x://x/'.$data['salt'], PHP_URL_QUERY), $query);
        $until = is_numeric($query['bis'] ?? null) ? (int) $query['bis'] : 0;
        if (! hash_equals(self::sign($data['challenge']), $data['signature']) || ($query['bereich'] ?? null) !== $area) {
            return 'pruefung-ungueltig';
        }
        if ($until < now()->getTimestamp()) {
            return 'pruefung-abgelaufen';
        }
        if (! hash_equals($data['challenge'], hash('sha256', $data['salt'].(int) $data['number']))) {
            return 'pruefung-falsch';
        }
        // Genau einmal: dieselbe Lösung ein zweites Mal ist ein Wiederholungsversuch
        if (! Cache::add('basalto.pow.'.$data['challenge'], true, $until - now()->getTimestamp() + 60)) {
            return 'pruefung-wiederholt';
        }

        return null;
    }

    /**
     * Die Stelle im Formular: verborgenes Feld, Statuszeile, Rechenfrage ohne Skript — und einmal je Seite Stil und Skript.
     */
    public function field(string $area, string $lang): string
    {
        $a = random_int(2, 9);
        $b = random_int(1, 9);
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $t = static fn (string $text, array $replace = []): string => (string) __($text, $replace, $lang);

        $question = $t('Spamschutz — bitte rechnen: :a + :b =', ['a' => $a, 'b' => $b]);
        $signed = $area.'.'.$a.'.'.$b.'.'.self::sign('rechnung|'.$area.'|'.$a.'|'.$b);

        return sprintf('<div class="basalto-pruefung" data-basalto-pruefung data-url="%s" data-bereich="%s" data-laeuft="%s" data-fertig="%s" data-fehler="%s" data-frage="%s" data-frage-t="%s">'
            .'<input type="hidden" name="%s" value=""><p class="basalto-pruefung__status" role="status"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg> <span data-pruefung-text>%s</span></p>'
            .'<noscript><label class="basalto-pruefung__frage">%s <input type="text" name="%s" inputmode="numeric" autocomplete="off" required size="3"></label><input type="hidden" name="%s" value="%s"></noscript></div>%s',
            $e(route('basalto.pow', absolute: false)), $e($area), $e($t('Spamschutz prüft im Hintergrund …')), $e($t('Spamschutz: geprüft — ohne Rätsel')), $e($t('Spamschutz: Prüfung fehlgeschlagen — bitte Seite neu laden')),
            $e($question), $e($signed), self::FIELD, $e($t('Spamschutz: läuft beim Ausfüllen, ohne Rätsel')), $e($question), self::ANSWER, self::QUESTION, $e($signed), self::assets());
    }

    private function arithmetic(Request $request, string $area): bool
    {
        $parts = explode('.', (string) $request->input(self::QUESTION, ''));
        if (count($parts) !== 4 || $parts[0] !== $area || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2])) {
            return false;
        }

        return hash_equals(self::sign('rechnung|'.$area.'|'.$parts[1].'|'.$parts[2]), $parts[3]) && trim((string) $request->input(self::ANSWER, '')) === (string) ((int) $parts[1] + (int) $parts[2]);
    }

    private static function sign(string $value): string
    {
        return hash_hmac('sha256', 'pow|'.$value, (string) config('app.key'));
    }

    /** Stil und Löser einmal je Anfrage — die Stelle steht in zwischengespeicherten Seiten, darum ohne Sitzung. */
    private static function assets(): string
    {
        $request = request();
        if ($request->attributes->get('basalto.pow.assets') === true) {
            return '';
        }
        $request->attributes->set('basalto.pow.assets', true);

        return '<style>.basalto-pruefung{margin:.4em 0}.basalto-pruefung__status{display:inline-flex;align-items:center;gap:6px;margin:0;font-size:.85em;opacity:.85}'
            .'.basalto-pruefung.ist-geprueft .basalto-pruefung__status{opacity:1;color:#1f7a45}.basalto-pruefung__frage{display:flex;flex-wrap:wrap;align-items:center;gap:8px}</style>'
            .'<script>'.self::SOLVER.'</script>';
    }

    /** Der Löser: holt die Aufgabe, probiert in Päckchen zu 500 Zahlen (Web Crypto), füllt das Feld, sendet erst dann. */
    private const SOLVER = <<<'JS'
(function(){function hex(b){return Array.prototype.map.call(new Uint8Array(b),function(x){return('0'+x.toString(16)).slice(-2)}).join('')}
async function solve(c){var enc=new TextEncoder();for(var n=0;n<=c.maxnumber;n+=500){var batch=[];for(var i=n;i<Math.min(n+500,c.maxnumber+1);i++){batch.push(crypto.subtle.digest('SHA-256',enc.encode(c.salt+i)))}var res=await Promise.all(batch);for(var j=0;j<res.length;j++){if(hex(res[j])===c.challenge){return n+j}}}return null}
document.querySelectorAll('[data-basalto-pruefung]').forEach(function(box){if(box.dataset.bereit){return}box.dataset.bereit='1';var form=box.closest('form');var input=box.querySelector('input[name="_pow"]');var text=box.querySelector('[data-pruefung-text]');if(!form||!input){return}if(!window.crypto||!crypto.subtle){var l=document.createElement('label');l.className='basalto-pruefung__frage';l.textContent=box.dataset.frage+' ';var a=document.createElement('input');a.name='_rechnung';a.required=true;a.size=3;a.inputMode='numeric';a.autocomplete='off';var q=document.createElement('input');q.type='hidden';q.name='_rechnung_t';q.value=box.dataset.frageT;l.append(a);box.append(l,q);text.parentNode.hidden=true;return}var job=null;
function start(){if(job){return job}text.textContent=box.dataset.laeuft;job=fetch(box.dataset.url+'?bereich='+encodeURIComponent(box.dataset.bereich),{credentials:'omit',headers:{Accept:'application/json'}}).then(function(r){return r.json()}).then(function(c){return solve(c).then(function(n){if(n===null){throw new Error('pow')}input.value=btoa(JSON.stringify({algorithm:c.algorithm,challenge:c.challenge,salt:c.salt,signature:c.signature,number:n}));box.classList.add('ist-geprueft');text.textContent=box.dataset.fertig})}).catch(function(){text.textContent=box.dataset.fehler;job=null});return job}
form.addEventListener('focusin',start,{once:true});form.addEventListener('pointerdown',start,{once:true});
form.addEventListener('submit',function(e){if(input.value){return}e.preventDefault();var p=start();if(p){p.then(function(){if(input.value){form.requestSubmit?form.requestSubmit(e.submitter||undefined):form.submit()}})}})})})();
JS;
}
