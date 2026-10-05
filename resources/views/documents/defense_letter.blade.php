<h1>{{ $b['title'] }}</h1>
<div class="grid">
    <div class="grid-row"><strong>{{ $b['from_label'] }}</strong><span>{{ $b['from_value'] }}</span></div>
    <div class="grid-row"><strong>{{ $b['to_label'] }}</strong><span>{{ $f['employee_name'] }}</span></div>
    @if(!empty($b['cc_value']))<div class="grid-row"><strong>{{ $b['cc_label'] ?? '' }}</strong><span>{{ $b['cc_value'] }}</span></div>@endif
    <div class="grid-row"><strong>{{ $b['subject_label'] }}</strong><span>{{ $b['subject_value'] }}</span></div>
    <div class="grid-row"><strong>{{ $b['date_label'] }}</strong><span>{{ $f['issue_date'] }}</span></div>
    <div class="grid-row"><strong>{{ $b['reference_label'] ?? ($page['language'] === 'en' ? 'Reference:' : 'العدد:') }}</strong><span>{{ $f['reference_number'] }}</span></div>
</div>
<p class="block">{{ $b['salutation'] }}</p>
<p class="block">{{ $b['opening'] }}</p>
<div class="body">{!! $page['body'] !!}</div>
@if($extra['prior_cases'])
    <p class="block"><strong>{{ $b['prior_record'] ?? '' }}</strong></p>
    <ul>@foreach($extra['prior_cases'] as $prior)<li>{{ $prior }}</li>@endforeach</ul>
@endif
<p class="block">{{ $b['deadline_sentence'] }}</p>
<div class="acknowledgment block">{{ $b['employee_block'] ?? '' }}</div>
