<x-mail::message>
@if (! empty($greeting))
# {{ $greeting }}
@elseif ($level === 'error')
# {{ __('emails.common.greeting_error') }}
@else
# {{ __('emails.common.greeting') }}
@endif

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (! empty($salutation))
{{ $salutation }}
@else
{{ __('emails.common.regards') }}<br>
{{ __('emails.common.team', ['app' => config('app.name')]) }}
@endif

@isset($actionText)
<x-slot:subcopy>
{{ __('emails.common.trouble_clicking', ['action' => $actionText]) }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
