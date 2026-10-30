<x-mail::layout>
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            {{ config('app.name') }}
        </x-mail::header>
    </x-slot:header>

    {{ $slot }}

    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    <x-slot:footer>
        <x-mail::footer>
            © {{ date('Y') }} {{ config('app.name') }}. {{ __('emails.common.rights') }}
            {{ __('emails.common.sender', ['company' => config('legal.company.name'), 'address' => config('legal.company.address')]) }}
            {{ __('emails.common.privacy') }}: {{ route('legal', ['doc' => 'privacy']) }}
            {{ __('emails.common.automated') }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
