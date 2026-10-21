@php
    $title = $f['penalty'] === ($page['language'] === 'en' ? 'termination' : 'إنهاء خدمة')
        ? $b['title_termination'] : $b['title_warning'];
    $outcomeKey = match ($extra['defense_acceptance']) {
        'accepted_fully' => 'defense_outcome_accepted',
        'accepted_partially' => 'defense_outcome_partial',
        default => 'defense_outcome_rejected',
    };
@endphp
<h1>{{ $title }}</h1>
<div class="grid">
    <div class="grid-row"><strong>{{ $b['from_label'] ?? ($page['language'] === 'en' ? 'From:' : 'من:') }}</strong><span>{{ $b['from_value'] ?? ($page['language'] === 'en' ? 'Disciplinary Committee' : 'اللجنة الانضباطية') }}</span></div>
    <div class="grid-row"><strong>{{ $b['to_label'] ?? ($page['language'] === 'en' ? 'To:' : 'إلى:') }}</strong><span>{{ $f['employee_name'] }}</span></div>
    @if(!empty($b['cc_value']))<div class="grid-row"><strong>{{ $b['cc_label'] ?? '' }}</strong><span>{{ $b['cc_value'] }}</span></div>@endif
    <div class="grid-row"><strong>{{ $b['subject_label'] ?? ($page['language'] === 'en' ? 'Subject:' : 'الموضوع:') }}</strong><span>{{ $title }}</span></div>
    <div class="grid-row"><strong>{{ $b['date_label'] ?? ($page['language'] === 'en' ? 'Date:' : 'التاريخ:') }}</strong><span>{{ $f['issue_date'] }}</span></div>
    <div class="grid-row"><strong>{{ $b['reference_label'] ?? ($page['language'] === 'en' ? 'Reference:' : 'العدد:') }}</strong><span>{{ $f['reference_number'] }}</span></div>
    @if(! empty($page['showCaseNumber']))<div class="grid-row"><strong>{{ $page['language'] === 'en' ? 'Case number:' : 'رقم الحالة:' }}</strong><span>{{ $f['case_number'] }}</span></div>@endif
</div>
<p class="block">{{ $b['salutation'] }}</p>
<p class="block">{{ $b['opening'] }}</p>
<p class="block">{{ $b[$outcomeKey] }}</p>
<div class="body">{!! $page['body'] !!}</div>
<p class="block">{{ $b['decision_sentence'] }}</p>
<div class="signatures"><div>{{ $b['gm_title'] }}</div><div>{{ $b['union_title'] }}</div></div>
<div class="acknowledgment block">{{ $b['acknowledgment'] ?? '' }}</div>
