<h1>{{ $b['title'] }}</h1>
<div class="grid">
    <div class="grid-row"><strong>{{ $b['reference_label'] ?? ($page['language'] === 'en' ? 'Reference:' : 'العدد:') }}</strong><span>{{ $f['reference_number'] }}</span></div>
    <div class="grid-row"><strong>{{ $page['language'] === 'en' ? 'Date:' : 'التاريخ:' }}</strong><span>{{ $f['issue_date'] }}</span></div>
</div>
<p class="block">{{ $b['mandate_sentence'] }}</p>
@if(!empty($b['lead_in']))<p class="block">{{ $b['lead_in'] }}</p>@endif
<p class="block"><strong>{{ $b['findings_intro'] }}</strong></p>
<div class="body">{!! $page['body'] !!}</div>
@if($extra['prior_cases'])
    <p class="block"><strong>{{ $b['prior_record'] ?? '' }}</strong></p>
    <ul>@foreach($extra['prior_cases'] as $prior)<li>{{ $prior }}</li>@endforeach</ul>
@endif
<p class="block"><strong>{{ $b['recommendations_intro'] }}</strong></p>
<p class="block">{{ $b['outcome_sentence'] }}</p>
<div class="body">{!! $page['secondary'] !!}</div>
<div class="block">
@foreach($extra['attendees'] as $attendee)
    <p>– {{ $attendee['name'] }} – {{ $b['role_'.$attendee['role']] }}: {{ $attendee['is_chair'] ? ($attendee['is_female'] ? $b['chair_label_female'] : $b['chair_label']) : ($attendee['is_female'] ? $b['member_label_female'] : $b['member_label']) }} &nbsp; {{ $b['signature_line'] ?? '' }}</p>
@endforeach
</div>
@if(!empty($extra['approvers']))
    <p class="block">{{ $page['language'] === 'en' ? 'Approved in the system by:' : 'اعتُمدت في النظام من:' }} {{ implode($page['language'] === 'en' ? ', ' : '، ', array_values($extra['approvers'])) }}</p>
@endif
