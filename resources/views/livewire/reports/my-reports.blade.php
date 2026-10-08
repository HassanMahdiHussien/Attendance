<div class="space-y-5">
    <x-ui.page-header :title="__('My reports')">
        <x-slot:actions>
            <x-ui.button href="/reports/new">{{ __('+ New report') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-3">
        @foreach($reports as $report)
            <x-ui.card class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-semibold">{{ __('Report') }} <bdi>{{ \App\Support\Format\Ui::number($report->id) }}</bdi></span>
                <x-ui.badge>{{ match($report->status) { \App\Enums\ReportStatus::Received => __('Received'), \App\Enums\ReportStatus::UnderReview => __('Under review'), \App\Enums\ReportStatus::ConvertedToCase => __('Converted to case'), \App\Enums\ReportStatus::Dismissed => __('Dismissed') } }}</x-ui.badge>
            </x-ui.card>
        @endforeach
        @foreach($emails as $email)
            <x-ui.card class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-semibold">{{ __('Forwarded email') }} <bdi>{{ \App\Support\Format\Ui::number($email->id) }}</bdi></span>
                <x-ui.badge>{{ $email->status === \App\Enums\EmailDraftStatus::Dismissed || $email->disciplinaryCase?->closed_at ? __('Closed') : ($email->status === \App\Enums\EmailDraftStatus::Received ? __('Received') : __('Under review')) }}</x-ui.badge>
            </x-ui.card>
        @endforeach
        @if($reports->isEmpty() && $emails->isEmpty())
            <x-ui.card><x-ui.empty :title="__('You have not submitted a report yet.')" /></x-ui.card>
        @endif
    </div>
</div>
