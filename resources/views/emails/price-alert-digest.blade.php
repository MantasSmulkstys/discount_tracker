<x-mail::message>
# Sveiki, {{ $userName }}

Štai naujausios akcijos jūsų sekamoms prekėms:

@foreach ($triggers as $trigger)
- **{{ $trigger['productName'] }}** — {{ $trigger['storeName'] }}: {{ number_format($trigger['price'], 2, ',', '') }} €
@if (! empty($trigger['oldPrice']) && $trigger['oldPrice'] > $trigger['price'])
  (buvo {{ number_format($trigger['oldPrice'], 2, ',', '') }} €)
@endif
@if (($trigger['triggerType'] ?? null) === 'price_drop')
  — atpigo
@elseif (($trigger['triggerType'] ?? null) === 'reappearance')
  — sugrįžo į akcijas
@else
  — nauja akcija
@endif

@endforeach

<x-mail::button :url="url('/')">
Peržiūrėti pasiūlymus
</x-mail::button>

Norėdami atsisakyti pranešimų, eikite į „Mano sekimai“ savo paskyroje.

Pagarbiai,<br>
{{ config('app.name') }}
</x-mail::message>
