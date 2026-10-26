{{-- Basalto (CR-011, TB 58): der Gruss nennt die Site, nicht die App --}}
<x-mail::message>
{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# @lang('Whoops!')
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines; TB 72: ein Code (`code` in den Daten) steht nach der ersten Zeile in Ziffernfeldern --}}
@foreach ($introLines as $line)
{{ $line }}

@if ($loop->first && ! empty($code))
<x-mail::code :code="$code" />

@endif
@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level ?? null) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards,')<br>
{{ app(\Basalto\Core\Mail\MailBrand::class)->current()['name'] }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
