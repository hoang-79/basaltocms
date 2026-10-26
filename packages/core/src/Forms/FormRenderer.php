<?php

declare(strict_types=1);

namespace Basalto\Core\Forms;

use Basalto\Core\Security\ProofOfWork;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag as Messages;
use Illuminate\Support\ViewErrorBag;

/**
 * Ein Formular im Frontend (M-11): barrierearm (Beschriftung je Feld, Fehler mit `aria-describedby`, Zusammenfassung
 * als `role="alert"`, Radio als `fieldset`), mit alten Eingaben nach einem Fehler und dem Danke-Text nach dem Senden.
 * Fehler und Danke kommen über eine Adresse mit Abfrage (`?formular=…`, `?gesendet=…`) — die geht nie in den
 * Seiten-Cache. Das Zustimmungsfeld steht immer am Ende (N-05). Mit Skript sendet es ohne Seitenwechsel ({@see inject()}).
 */
final class FormRenderer
{
    /** Feldmarken für die Live-Vorschau des Baukastens (TB 31): `data-basalto-field` am Rahmen jedes Feldes */
    private bool $marks = false;

    public function __construct(private readonly SpamGuard $guard, private readonly ProofOfWork $pow) {}

    /** Dieselbe Ausgabe mit Feldmarken — nur für die Vorschau im Backend, nie für Gäste. */
    public function withMarks(): self
    {
        $copy = clone $this;
        $copy->marks = true;

        return $copy;
    }

    public function render(FormDefinition $form, string $lang, string $pagePath, ?Request $request = null): string
    {
        $request ??= request();
        $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $handle = $form->handle;
        $id = 'formular-'.$handle;
        if ($request->query('gesendet') === $handle) {
            $thanks = self::thanks($form, $lang);

            return sprintf('<div class="form form-%1$s form-sent" id="%2$s"><p class="form-thanks" role="status">%3$s</p></div>', $e($handle), $id, $e($thanks));
        }
        $errors = $this->errors($request, $handle);
        $old = $request->query('formular') === $handle && $request->hasSession() ? (array) $request->session()->getOldInput() : [];
        $hasFile = array_filter($form->inputs(), static fn (FormField $f): bool => $f->type === 'file') !== [];
        $html = sprintf('<form class="form form-%1$s" id="%2$s" method="post" action="/forms/%1$s"%3$s>', $e($handle), $id, $hasFile ? ' enctype="multipart/form-data"' : '');
        if ($errors->isNotEmpty()) {
            $html .= sprintf('<div class="form-errors" role="alert"><p>%s</p><ul>%s</ul></div>', $e(self::errorSummary($lang)),
                implode('', array_map(static fn (string $m): string => '<li>'.$e($m).'</li>', $errors->all())));
        }
        $html .= sprintf('<input type="hidden" name="%s" value="%s"><input type="hidden" name="_seite" value="%s"><input type="hidden" name="_sprache" value="%s">',
            SpamGuard::TOKEN, $e($this->guard->token($handle)), $e($pagePath), $e($lang));
        $html .= sprintf('<div class="form-hp" aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden"><label>Website<input type="text" name="%s" tabindex="-1" autocomplete="off"></label></div>', SpamGuard::HONEYPOT);
        foreach ($form->fields as $field) {
            $html .= $this->field($form, $field, $lang, $old, $errors);
        }
        $consent = $form->text('consent', $lang) ?? ($lang === 'en' ? 'I agree to my details being stored to answer my request.' : 'Ich bin einverstanden, dass meine Angaben zur Beantwortung gespeichert werden.');
        $consentError = $errors->first('_consent');
        $html .= sprintf('<div class="form-field form-consent%5$s"><label><input type="checkbox" name="_consent" value="1" required%1$s%2$s> %3$s</label>%4$s</div>',
            ($old['_consent'] ?? null) ? ' checked' : '', $consentError !== '' ? ' aria-invalid="true" aria-describedby="'.$id.'-_consent-fehler"' : '', $e($consent),
            $consentError !== '' ? sprintf('<p class="form-error" id="%s-_consent-fehler">%s</p>', $id, $e($consentError)) : '', $consentError !== '' ? ' has-error' : '');
        $submit = $form->text('submit', $lang) ?? ($lang === 'en' ? 'Send' : 'Senden');
        // TB 50: Spamschutz im Browser, wenn er für die Site an ist (nicht in der Vorschau des Baukastens)
        if (! $this->marks && $this->pow->enabled(app(CurrentSite::class)->get(), 'formular')) {
            $html .= $this->pow->field('formular', $lang);
        }

        return $html.sprintf('<button type="submit">%s</button></form>', $e($submit));
    }

    /** Der Danke-Text nach dem Senden — aus dem Formular (`thanks`) oder der Vorgabe der Sprache. */
    public static function thanks(FormDefinition $form, string $lang): string
    {
        return $form->text('thanks', $lang) ?? ($lang === 'en' ? 'Thank you — your message has been sent.' : 'Danke — Ihre Nachricht ist angekommen.');
    }

    /** Der Kopf der Fehlerliste — im HTML und in der JSON-Antwort. */
    public static function errorSummary(string $lang): string
    {
        return $lang === 'en' ? 'Please check the marked fields.' : 'Bitte die markierten Felder prüfen.';
    }

    /**
     * Senden ohne Seitenwechsel (M-17): ein kleines Skript vor `</body>`, nur auf Seiten mit Formular, inline wie die
     * Zustimmung (kein Skript von fremdem Server). Es schickt das Formular mit `fetch` und `FormData`, zeigt Fehler am
     * Feld (`aria-invalid`, `aria-describedby`, Fokus aufs erste) und den Danke-Text in einer `role="status"`-Fläche.
     * Ohne Skript sendet das Formular wie bisher. Ein Theme schaltet es ab mit `data-basalto-ajax="false"` an einem
     * Element, das das Formular umschliesst (die Sektion um `{% form %}` oder `<body>`).
     */
    public static function inject(string $html, string $lang): string
    {
        if (! str_contains($html, ' action="/forms/')) {
            return $html;
        }
        $out = '<script>'.self::script($lang).'</script>';

        return str_contains($html, '</body>') ? (string) preg_replace('#</body>#i', $out.'</body>', $html, 1) : $html.$out;
    }

    private static function script(string $lang): string
    {
        $failed = json_encode($lang === 'en' ? 'Sending did not work — please try again later.' : 'Senden ging nicht — bitte später nochmals versuchen.', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

        return <<<JS
(function(){
  if(window.basaltoForms||!window.fetch||!window.FormData){return;}window.basaltoForms=true;
  var d=document;
  function off(f){var o=f.closest('[data-basalto-ajax]');return !!o&&o.getAttribute('data-basalto-ajax')==='false';}
  function described(el,id,add){var ids=(el.getAttribute('aria-describedby')||'').split(' ').filter(function(x){return x&&x!==id;});
    if(add){ids.push(id);}if(ids.length){el.setAttribute('aria-describedby',ids.join(' '));}else{el.removeAttribute('aria-describedby');}}
  function clear(f){f.querySelectorAll('.form-errors').forEach(function(n){n.remove();});
    f.querySelectorAll('.form-error').forEach(function(n){f.querySelectorAll('[aria-describedby~="'+n.id+'"]').forEach(function(el){described(el,n.id,false);});n.remove();});
    f.querySelectorAll('[aria-invalid]').forEach(function(el){el.removeAttribute('aria-invalid');});
    f.querySelectorAll('.has-error').forEach(function(el){el.classList.remove('has-error');});}
  function show(f,message,errors){var first=null;var box=d.createElement('div');box.className='form-errors';box.setAttribute('role','alert');
    box.tabIndex=-1;var p=d.createElement('p');p.textContent=message;box.appendChild(p);var ul=d.createElement('ul');if(Object.keys(errors).length){box.appendChild(ul);}
    Object.keys(errors).forEach(function(name){var text=[].concat(errors[name])[0];var li=d.createElement('li');li.textContent=text;ul.appendChild(li);
      var el=f.elements.namedItem(name);if(el&&!el.nodeType&&el.length){el=el[0];}if(!el||!el.closest){return;}
      var wrap=el.closest('.form-field')||el.parentNode;var target=wrap.tagName==='FIELDSET'?wrap:el;var id=(el.id||f.id+'-'+name)+'-fehler';
      var e=d.createElement('p');e.className='form-error';e.id=id;e.textContent=text;wrap.appendChild(e);wrap.classList.add('has-error');
      target.setAttribute('aria-invalid','true');described(target,id,true);if(!first){first=el;}});
    f.insertBefore(box,f.firstChild);(first||box).focus();}
  function done(f,message){var box=d.createElement('div');box.className=f.className+' form-sent';box.id=f.id;
    var p=d.createElement('p');p.className='form-thanks';p.setAttribute('role','status');p.tabIndex=-1;p.textContent=message;box.appendChild(p);f.replaceWith(box);p.focus();}
  d.addEventListener('submit',function(ev){var f=ev.target;
    if(!f||!f.matches||!f.matches('form[action^="/forms/"]')||off(f)){return;}
    ev.preventDefault();if(f.getAttribute('aria-busy')==='true'){return;}
    var b=f.querySelector('[type="submit"]');f.setAttribute('aria-busy','true');if(b){b.disabled=true;}
    fetch(f.action,{method:'POST',body:new FormData(f),headers:{'Accept':'application/json'},credentials:'same-origin'})
      .then(function(r){return r.json().then(function(j){return {status:r.status,body:j};});})
      .then(function(res){clear(f);
        if(res.status>=200&&res.status<300){if(res.body.redirect){location.assign(res.body.redirect);}else{done(f,res.body.message);}return;}
        if(res.status===422&&res.body.errors){show(f,res.body.message,res.body.errors);return;}
        show(f,{$failed},{});})
      .catch(function(){clear(f);show(f,{$failed},{});})
      .then(function(){f.removeAttribute('aria-busy');if(b){b.disabled=false;}});
  });
})();
JS;
    }

    /** @param  array<string, mixed>  $old */
    private function field(FormDefinition $form, FormField $field, string $lang, array $old, MessageBag $errors): string
    {
        $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $mark = $this->marks ? sprintf(' data-basalto-field="%s"', $e($field->name)) : '';
        if ($field->type === 'explanation') {
            return sprintf('<p class="form-explanation"%s>%s</p>', $mark, $e($field->text('text', $lang) ?? $field->label($lang)));
        }
        if ($field->type === 'group') {
            return sprintf('<fieldset class="form-group"%s><legend>%s</legend>%s</fieldset>', $mark, $e($field->label($lang)),
                implode('', array_map(fn (FormField $c): string => $this->field($form, $c, $lang, $old, $errors), $field->children)));
        }
        $id = sprintf('f-%s-%s', $form->handle, $field->name);
        $name = $e($field->name);
        $value = $old[$field->name] ?? $field->text('default', $lang);
        $error = $errors->first($field->name);
        $help = $field->text('help', $lang);
        $describedBy = array_filter([$help !== null ? $id.'-hilfe' : null, $error !== '' ? $id.'-fehler' : null]);
        $aria = ($describedBy !== [] ? ' aria-describedby="'.implode(' ', $describedBy).'"' : '').($error !== '' ? ' aria-invalid="true"' : '');
        $required = $field->required ? ' required' : '';
        $attrs = $required.$aria.($field->text('placeholder', $lang) !== null ? ' placeholder="'.$e($field->text('placeholder', $lang)).'"' : '');
        foreach (['min', 'max', 'step'] as $key) {
            if ($field->number($key) !== null && in_array($field->type, ['number', 'range'], true)) {
                $attrs .= sprintf(' %s="%s"', $key, $e((string) $field->number($key)));
            }
        }
        if ($field->number('max') !== null && in_array($field->type, ['text', 'textarea', 'tel', 'url', 'email'], true)) {
            $attrs .= sprintf(' maxlength="%d"', (int) $field->number('max'));
        }
        $label = $e($field->label($lang)).($field->required ? ' <span class="form-required" aria-hidden="true">*</span>' : '');
        $control = match ($field->type) {
            'hidden' => sprintf('<input type="hidden" name="%s" value="%s">', $name, $e(is_scalar($value) ? (string) $value : '')),
            'textarea' => sprintf('<textarea id="%s" name="%s"%s>%s</textarea>', $id, $name, $attrs, $e(is_scalar($value) ? (string) $value : '')),
            'select' => sprintf('<select id="%s" name="%s"%s><option value=""></option>%s</select>', $id, $name, $required.$aria,
                implode('', array_map(static fn (string $o): string => sprintf('<option%s>%s</option>', $value === $o ? ' selected' : '', $e($o)), $field->options($lang)))),
            'radio' => implode('', array_map(static fn (string $o, int $i): string => sprintf('<label><input type="radio" name="%s" value="%s"%s%s> %s</label>', $name, $e($o), $value === $o ? ' checked' : '', $i === 0 ? $required : '', $e($o)), $field->options($lang), array_keys($field->options($lang)))),
            'checkbox' => sprintf('<input type="checkbox" id="%s" name="%s" value="1"%s%s>', $id, $name, $value ? ' checked' : '', $required.$aria),
            'file' => sprintf('<input type="file" id="%s" name="%s" accept="%s"%s>', $id, $name, $e(implode(',', array_map(static fn (string $t): string => '.'.$t, $field->fileTypes()))), $required.$aria),
            default => sprintf('<input type="%s" id="%s" name="%s" value="%s"%s>', in_array($field->type, ['email', 'number', 'tel', 'url', 'date', 'range'], true) ? $field->type : 'text', $id, $name, $e(is_scalar($value) ? (string) $value : ''), $attrs),
        };
        if ($field->type === 'hidden') {
            return $control;
        }
        $extra = ($help !== null ? sprintf('<p class="form-help" id="%s-hilfe">%s</p>', $id, $e($help)) : '').($error !== '' ? sprintf('<p class="form-error" id="%s-fehler">%s</p>', $id, $e($error)) : '');
        $class = 'form-field form-'.$field->type.($error !== '' ? ' has-error' : '');
        if ($field->type === 'radio') {
            return sprintf('<fieldset class="%s"%s%s><legend>%s</legend>%s%s</fieldset>', $class, $aria, $mark, $label, $control, $extra);
        }
        if ($field->type === 'checkbox') {
            return sprintf('<div class="%s"%s><label>%s %s</label>%s</div>', $class, $mark, $control, $label, $extra);
        }

        return sprintf('<div class="%s"%s><label for="%s">%s</label>%s%s</div>', $class, $mark, $id, $label, $control, $extra);
    }

    private function errors(Request $request, string $handle): MessageBag
    {
        $bags = $request->hasSession() ? $request->session()->get('errors') : null;

        return $bags instanceof ViewErrorBag ? $bags->getBag('form_'.$handle) : new Messages;
    }
}
