<div class="max-w-5xl mx-auto space-y-5">
    <h1 class="text-2xl font-black">اللجنة</h1>
    <p class="text-gray-500">توصيات بانتظار مراجعتك. تعرض المراجعة كل اللغات المفعلة.</p>
    <section class="bg-white rounded-3xl p-6 shadow-sm">
        @forelse($documents as $document)
            <a href="/committee/approvals/{{ $document->id }}" class="block border-b py-4 text-primary font-bold">الحالة {{ \App\Support\Format\ArabicDigits::convert($document->disciplinaryCase->case_number) }} — توصيات اللجنة</a>
        @empty
            <p class="text-gray-500">لا توجد موافقات بانتظارك.</p>
        @endforelse
    </section>
</div>
