<x-filament-panels::page>
    <div dir="rtl" class="space-y-6">
        <section class="rounded-xl border p-4 space-y-3 dark:border-gray-700">
            <h2 class="font-bold">ملف الموظفين</h2>
            <p>حمّل القالب ثم املأ صفاً لكل موظف. الأعمدة المطلوبة: الاسم، القسم، المنصب، تاريخ التعيين، الفرع. رقم الموظف والبريد والجنس اختيارية. اكتب التاريخ 2024-01-15 أو 15/01/2024.</p>
            <p>مثال: أحمد علي، EMP-0100، الموارد البشرية، مسؤول، 2024-01-15، بغداد، ahmed@example.com، ذكر. اترك رقم الموظف فارغاً لإنشائه تلقائياً. الخلايا الاختيارية الفارغة لا تمحو القيم الموجودة.</p>
            <a class="underline text-primary-600" href="{{ route('employees.import.template', ['company' => \Filament\Facades\Filament::getTenant()->id]) }}">تنزيل قالب CSV</a>
            <form wire:submit="previewImport" class="space-y-3">
                <input type="file" wire:model="sourceFile" accept=".csv,.txt,text/csv,text/plain" class="block w-full">
                @error('sourceFile') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button type="submit">معاينة الملف</x-filament::button>
            </form>
        </section>

        @if ($summary)
            <section class="rounded-xl border border-green-500 p-4">اكتمل الاستيراد: أضيف {{ $summary['added'] }}، حُدّث {{ $summary['updated'] }}.</section>
        @endif

        @if ($previewRows !== null)
            @php
                $errors = count(array_filter($previewRows, fn ($row) => $row['status'] === 'error'));
                $added = count(array_filter($previewRows, fn ($row) => $row['status'] === 'new'));
                $updated = count($previewRows) - $errors - $added;
            @endphp
            <section class="rounded-xl border p-4 space-y-3 dark:border-gray-700">
                <h2 class="font-bold">المعاينة: {{ count($previewRows) }} صف</h2>
                <p>جديد: {{ $added }} · تحديث: {{ $updated }} · أخطاء: {{ $errors }}</p>
                @if ($notices)
                    <p>أعمدة غير معروفة تم تجاهلها: {{ implode('، ', $notices) }}</p>
                @endif
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse">
                        <thead><tr class="border-b"><th class="p-2">الصف</th><th class="p-2">الحالة</th><th class="p-2">الاسم</th><th class="p-2">الرقم</th><th class="p-2">الفرع</th><th class="p-2">التغييرات / الملاحظات</th></tr></thead>
                        <tbody>
                            @foreach ($previewRows as $row)
                                <tr class="border-b align-top">
                                    <td class="p-2">{{ $row['number'] }}</td>
                                    <td class="p-2"><x-filament::badge :color="$row['status'] === 'error' ? 'danger' : ($row['status'] === 'new' ? 'success' : 'warning')">{{ match ($row['status']) { 'new' => 'جديد', 'update' => 'سيُحدَّث', 'update-inactive' => 'سيُحدَّث (غير نشط)', default => 'خطأ' } }}</x-filament::badge></td>
                                    <td class="p-2">{{ $row['values']['name'] }}</td>
                                    <td class="p-2">{{ $row['values']['employee_code'] ?: 'سيُولَّد' }}</td>
                                    <td class="p-2">{{ $row['branch'] }}</td>
                                    <td class="p-2">
                                        @foreach ($row['errors'] as $error) <p class="text-red-600">{{ $error }}</p> @endforeach
                                        @if ($row['diff']) <p>تغيير: {{ implode('، ', $row['diff']) }}</p> @endif
                                        @foreach ($row['warnings'] as $warning) <p class="text-amber-600">{{ $warning }}</p> @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-filament::button wire:click="confirmImport" :disabled="$errors > 0">تأكيد الاستيراد</x-filament::button>
            </section>
        @endif
    </div>
</x-filament-panels::page>
