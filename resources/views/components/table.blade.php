@props(['headers' => []])
<div class="table-responsive"><table {{ $attributes->class(['table', 'table-hover', 'align-middle', 'mb-0']) }}>
    <thead><tr>@foreach ($headers as $h)<th scope="col">{{ $h }}</th>@endforeach</tr></thead>
    <tbody>{{ $slot }}</tbody>
</table></div>
