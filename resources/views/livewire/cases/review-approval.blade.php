<div class="max-w-6xl mx-auto space-y-5">
    <a href="/committee" class="text-primary text-sm">← اللجنة</a>
    <section class="bg-white rounded-3xl p-6 md:p-8 shadow-sm space-y-5">
        <h1 class="text-2xl font-black">مراجعة توصيات اللجنة</h1>
        <p class="text-gray-500">قرارك يشمل كل اللغات المعروضة. النص للقراءة فقط.</p>
        @if($notice)<p role="status" class="bg-red-light text-red rounded-2xl p-4">{{ $notice }}</p>@endif
        @error('notice')<p role="alert" class="bg-red-light text-red rounded-2xl p-4">{{ $message }}</p>@enderror
        <div class="grid lg:grid-cols-3 gap-4">
            @foreach($document->contents as $content)
                <div class="bg-gray-50 rounded-2xl p-5" dir="{{ $content->language_code === 'en' ? 'ltr' : 'rtl' }}">
                    <h2 class="font-black">{{ $content->language_code }}</h2>
                    <h3 class="font-bold mt-4">النتائج</h3><div class="whitespace-pre-wrap">{{ $content->body }}</div>
                    <h3 class="font-bold mt-4">التوصيات</h3><div class="whitespace-pre-wrap">{{ $content->secondary_body }}</div>
                </div>
            @endforeach
        </div>
        <label class="block font-bold">صفة الموافقة <select wire:model="category" class="block rounded-xl border p-3 mt-2"><option value="">اختر الصفة</option>@foreach($categories as $role)<option value="{{ $role->value }}">{{ ['legal'=>'القانونية','hr'=>'الموارد البشرية','labor_union'=>'نقابة العمال'][$role->value] }}</option>@endforeach</select></label>
        <label class="block font-bold">تعليق الرفض <textarea wire:model="comment" rows="3" class="block w-full rounded-xl border p-3 mt-2"></textarea></label>
        @error('comment')<p class="text-red">{{ $message }}</p>@enderror
        <div class="flex gap-3"><button wire:click="approve" class="bg-primary text-white rounded-2xl px-6 py-3 font-bold">موافقة</button><button wire:click="reject" class="border border-red text-red rounded-2xl px-6 py-3">رفض مع تعليق</button></div>
    </section>
</div>
