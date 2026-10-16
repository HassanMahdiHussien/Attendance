<div class="space-y-5">
    <a href="{{ route('help.index') }}" class="ui-link inline-flex min-h-10 items-center">{{ __('All guides') }}</a>
    <div class="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_16rem]">
        <article class="ui-card min-w-0 p-5 sm:p-8">
            <header class="mb-7 border-b border-line pb-5">
                <p class="mb-2 text-sm font-semibold text-primary">{{ $sectionName }}</p>
                <h1 class="text-2xl font-bold leading-tight text-ink">{{ $article->title }}</h1>
                <p class="mt-3 text-sm leading-7 text-gray-600">{{ $article->summary }}</p>
            </header>
            <div class="help-prose">{!! $article->html !!}</div>
            @if($previous || $next)
            <nav class="mt-8 grid gap-3 border-t border-line pt-5 sm:grid-cols-2" aria-label="{{ __('Guide navigation') }}">
                @if($previous)<a class="ui-link" href="{{ $previous->url() }}">{{ __('Previous') }}: {{ $previous->title }}</a>@endif
                @if($next)<a class="ui-link" href="{{ $next->url() }}">{{ __('Next') }}: {{ $next->title }}</a>@endif
            </nav>
            @endif
        </article>
        <aside class="ui-card h-fit p-5">
            <h2 class="mb-3 font-bold">{{ $sectionName }}</h2>
            <nav aria-label="{{ __('Section guides') }}" class="space-y-1">
                @foreach($siblings as $item)
                    <a href="{{ $item->url() }}" @if($item->slug === $article->slug) aria-current="page" @endif class="block rounded-lg px-3 py-2 text-sm {{ $item->slug === $article->slug ? 'bg-primary-light font-semibold text-primary' : 'text-gray-700 hover:bg-gray-50' }}">{{ $item->title }}</a>
                @endforeach
            </nav>
        </aside>
    </div>
</div>
