@props(['tone' => 'gray'])
<span {{ $attributes->class(['ui-badge', 'ui-badge-red' => $tone === 'red', 'ui-badge-amber' => $tone === 'amber', 'ui-badge-green' => $tone === 'green', 'ui-badge-indigo' => $tone === 'indigo', 'ui-badge-gray' => $tone === 'gray']) }}>{{ $slot }}</span>
