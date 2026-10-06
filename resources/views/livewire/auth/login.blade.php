<main class="min-h-screen bg-bg font-tajawal flex items-center justify-center p-4" dir="rtl">
    <form wire:submit="authenticate" class="w-full max-w-md bg-white rounded-3xl p-8 shadow-lg">
        <h1 class="text-2xl font-black text-primary mb-2">نظام الانضباط</h1>
        <p class="text-sm text-gray-500 mb-7">تسجيل الدخول إلى مساحة العمل</p>
        <label for="email" class="block text-sm font-bold mb-2">البريد الإلكتروني</label>
        <input id="email" type="email" wire:model="email" autocomplete="username" class="w-full rounded-xl border border-gray-200 p-3 mb-2" required>
        @error('email') <p class="text-red text-sm mb-3">{{ $message }}</p> @enderror
        <label for="password" class="block text-sm font-bold mb-2 mt-3">كلمة المرور</label>
        <input id="password" type="password" wire:model="password" autocomplete="current-password" class="w-full rounded-xl border border-gray-200 p-3 mb-2" required>
        @error('password') <p class="text-red text-sm mb-3">{{ $message }}</p> @enderror
        <button class="w-full bg-primary text-white font-bold py-4 rounded-2xl shadow-lg shadow-indigo-200 mt-5">دخول</button>
    </form>
</main>
