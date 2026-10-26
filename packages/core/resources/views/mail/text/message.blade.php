@php($brand = app(\Basalto\Core\Mail\MailBrand::class)->current())
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="$brand['url']">
            {{ $brand['name'] }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            {{ implode(' · ', $brand['lines']) }}
            @foreach ($brand['links'] as $link)
                {{ $link['label'] }}: {{ $link['url'] }}
            @endforeach
            {{ $brand['name'] }} · {{ $brand['url'] }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
