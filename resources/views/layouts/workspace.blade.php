<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>نظام الانضباط</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&family=Amiri:wght@400;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-bg font-tajawal text-ink">
    @php($role = auth()->user()->primary_role)
    <header class="bg-white border-b border-gray-100 px-4 sm:px-8 py-4 flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-4 sm:gap-8">
            <a href="{{ \App\Support\Access\Landing::for(auth()->user()) }}" class="font-black text-lg text-primary">نظام الانضباط</a>
            <nav class="flex flex-wrap gap-1 text-sm text-gray-500" aria-label="التنقل الرئيسي">
                @if(in_array($role, [\App\Enums\UserRole::CompanyAdmin, \App\Enums\UserRole::HrStaff], true))
                    <a href="/cases" class="px-3 py-2 rounded-lg {{ request()->is('cases*') ? 'bg-primary-light text-primary font-bold' : 'hover:bg-gray-50' }}">الحالات</a>
                    <a href="/reports/inbox" class="px-3 py-2 rounded-lg {{ request()->is('reports/inbox') ? 'bg-primary-light text-primary font-bold' : 'hover:bg-gray-50' }}">البلاغات</a>
                @elseif(in_array($role, [\App\Enums\UserRole::Reporter, \App\Enums\UserRole::DeptManager], true))
                    <a href="/reports" class="px-3 py-2 rounded-lg bg-primary-light text-primary font-bold">بلاغاتي</a>
                @elseif(in_array($role, [\App\Enums\UserRole::CommitteeMember, \App\Enums\UserRole::ExternalLegalReviewer], true))
                    <a href="/my-cases" class="px-3 py-2 rounded-lg bg-primary-light text-primary font-bold">حالاتي</a>
                @endif
                @if(\App\Support\Access\CommitteeNav::hasPending(auth()->user()))<a href="/committee" class="px-3 py-2 rounded-lg {{ request()->is('committee*') ? 'bg-primary-light text-primary font-bold' : 'hover:bg-gray-50' }}">اللجنة</a>@endif
                @if($regulationUrl = \App\Support\Access\InternalRegulationNav::url(auth()->user()))
                    <a href="{{ $regulationUrl }}" class="px-3 py-2 rounded-lg {{ request()->is('admin/*/internal-regulation') ? 'bg-primary-light text-primary font-bold' : 'hover:bg-gray-50' }}">النظام الداخلي</a>
                @endif
                @if($role === \App\Enums\UserRole::CompanyAdmin)
                    <a href="/admin/{{ auth()->user()->company_id }}" class="px-3 py-2 rounded-lg hover:bg-gray-50">الإعدادات</a>
                @endif
            </nav>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-sm text-gray-400">{{ auth()->user()->company?->name ?? auth()->user()->name }}</span>
            <span class="w-8 h-8 rounded-full bg-primary text-white text-xs flex items-center justify-center font-bold" aria-hidden="true">{{ \App\Support\Format\Initials::for(auth()->user()->name) }}</span>
            <form method="POST" action="/logout">@csrf<button class="text-sm text-gray-500 hover:text-primary">خروج</button></form>
        </div>
    </header>
    <main class="max-w-6xl mx-auto p-4 sm:p-8">{{ $slot }}</main>
</body>
</html>
