{{-- Basalto (CR-011, TB 58): Kopf mit dem Namen der Site, Fuss mit dem Anbieter und den Rechtsseiten der Site --}}
@php($brand = app(\Basalto\Core\Mail\MailBrand::class)->current())
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$brand['url']">
{{ $brand['name'] }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
@if ($brand['lines'] !== [])
{{ implode(' · ', $brand['lines']) }}

@endif
@if ($brand['links'] !== [])
{!! implode(' · ', array_map(static fn (array $l): string => '['.e($l['label']).']('.e($l['url']).')', $brand['links'])) !!}

@endif
{{ $brand['name'] }} · [{{ preg_replace('#^https?://#', '', rtrim($brand['url'], '/')) }}]({{ $brand['url'] }})
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
