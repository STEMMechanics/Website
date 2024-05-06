<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')"/>
</x-slot:header>

{{-- Body --}}
{{ Illuminate\Mail\Markdown::parse($slot) }}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
{{ Illuminate\Mail\Markdown::parse($subcopy) }}
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
<p>This email was sent to <a href="mailto:{{ $email }}">{{ $email }}</a><br />
<a href="{{ route('index') }}">{{ config('app.name') }}</a> | 1/4 Jordan Street | Edmonton, QLD 4869 Australia<br />
© {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}<br />
<a href="{{ route('privacy') }}">Privacy Policy</a> | <a href="{{ route('terms-conditions') }}">Terms & Conditions</a> @isset($unsubscribe) | <a href="{{ $unsubscribe }}">Unsubscribe</a>@endisset
</p>
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
