@props(['label' => null])
<td {{ $attributes->class(['ui-td'])->merge(['data-label' => $label]) }}>{{ $slot }}</td>
