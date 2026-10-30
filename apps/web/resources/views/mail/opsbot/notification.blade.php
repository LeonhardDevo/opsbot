<x-mail::message>
# {{ $heading }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if (! empty($quote))
<x-mail::panel>
{{ $quote }}
</x-mail::panel>
@endif

@if (! empty($actionUrl))
<x-mail::button :url="$actionUrl">
{{ $actionLabel }}
</x-mail::button>
@endif

{{ __('emails.common.regards') }}<br>
{{ __('emails.common.team', ['app' => config('app.name')]) }}

@if (! empty($actionUrl))
<x-slot:subcopy>
{{ __('emails.common.trouble_clicking', ['action' => $actionLabel]) }} <span class="break-all">[{{ $actionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endif
</x-mail::message>
