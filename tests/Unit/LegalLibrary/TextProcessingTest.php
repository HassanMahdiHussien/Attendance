<?php

use App\LegalLibrary\ArticleSplitter;
use App\LegalLibrary\ReadingComparator;
use App\LegalLibrary\TextLayerProbe;
use App\LegalLibrary\TextNormalizer;
use App\LegalLibrary\WordDiff;

test('normalization preserves word boundaries and real punctuation differences', function (): void {
    $normalizer = new TextNormalizer;
    expect($normalizer->normalize("\u{200E}ﻣﺎدة\u{0640} ١5\u{200F}،  نصّ"))->toBe('مادة 15, نص');
    expect($normalizer->normalize('نص »'))->not->toBe($normalizer->normalize('نص ،'));
    expect($normalizer->normalize('في البيت'))->not->toBe($normalizer->normalize('فيالبيت'));
});

test('splitter accepts digit and ordinal articles, two clause levels and page continuations', function (): void {
    $units = (new ArticleSplitter(new TextNormalizer))->split([
        1 => "الوقائع العراقية — العدد 0000\nالمادة -1- بداية\nالمادة (2) حكم\nأولاً- نص\nثانياً- تفصيل\nأ- فقرة\nب- فقرة\nالمادة الثالثة مستمرة",
        2 => "الوقائع العراقية — العدد 0000\nبقية المادة\nالمادة -4- خاتمة\n2",
    ]);
    expect(array_column($units, 'article_number'))->toContain('1', '2', '3', '4');
    expect(collect($units)->firstWhere('article_number', '3')['tags']['continues_on'])->toBe([2]);
    expect(collect($units)->pluck('clause_path')->filter()->count())->toBeGreaterThanOrEqual(4);
});

test('comparison requires both matching text and matching structure', function (): void {
    $splitter = new ArticleSplitter(new TextNormalizer);
    $comparator = new ReadingComparator(new TextNormalizer);
    $a = $splitter->split([1 => "المادة 1 حكم\nأولاً- نص"]);
    $b = $splitter->split([1 => 'المادة 1 حكم']);
    expect(collect($comparator->compare($a, $b))->first()['tags']['mismatch_reason'])->toBe('structure_differs');
    expect(collect($comparator->compare($a, $a, true))->first()['tags']['mismatch_reason'])->toBe('illegible');
    expect(collect($comparator->compare($a, $a))->first()['status'])->toBe('verified_auto');
    expect(collect($comparator->compare($a, []))->first()['tags']['mismatch_reason'])->toBe('one_reading_only');
});

test('masculine compound ordinal and duplicated article headings need review', function (): void {
    $splitter = new ArticleSplitter(new TextNormalizer);
    $units = $splitter->split([1 => "المادة حادي وعشرون حكم\nثامن عشر- بند\nالمادة حادي وعشرون تكرار"]);
    expect($units[0]['article_number'])->toBe('21');
    expect($units[1]['clause_path'])->toContain('ثامن عشر');
    expect($units[0]['tags']['mismatch_reason'])->toBe('duplicate_heading');
    expect($units[2]['tags']['mismatch_reason'])->toBe('duplicate_heading');
});

test('word diff marks changed words', function (): void {
    expect((new WordDiff)->diff('نص قديم', 'نص جديد'))->toBe([
        ['same', 'نص'], ['removed', 'قديم'], ['added', 'جديد'],
    ]);
});

test('spacing around separators, detached conjunctions and mirrored brackets are normalised', function (): void {
    $normalizer = new TextNormalizer;
    expect($normalizer->normalize('أولا -تحفظ'))->toBe($normalizer->normalize('أولاً- تحفظ'));
    expect($normalizer->normalize('و يعمل به'))->toBe($normalizer->normalize('ويعمل به'));
    expect($normalizer->normalize('المادة ()2'))->toBe('المادة (2)');
    expect($normalizer->normalize('المادة )2('))->toBe('المادة (2)');
    expect($normalizer->normalize('الأولى .وتحفظ'))->toBe($normalizer->normalize('الأولى. وتحفظ'));
    // Letters still differ: these are real reading differences a person must see.
    expect($normalizer->normalize('الآتية'))->not->toBe($normalizer->normalize('الآنية'));
});

test('a text layer with spaces inside words is not usable', function (): void {
    $probe = new TextLayerProbe;
    $clean = str_repeat('تسري أحكام هذا القانون على المؤسسة وتحفظ السجلات في موضع آمن. ', 6);
    $spaced = str_repeat('ت سري أحك ام هذ ا الق انون على المؤ سس ة وت حف ظ الس ج لات في موض ع آمن. ', 6);
    expect($probe->usable($clean))->toBeTrue();
    expect($probe->usable($spaced))->toBeFalse();
});

test('definite ordinals and page numbers are not clauses and clause keys are canonical', function (): void {
    $units = (new ArticleSplitter(new TextNormalizer))->split([
        1 => "المادة 5\nالثالثة من الحالات تعالج بقرار.\nاولا - نص بلا همزة\nثانيا ‪-‬ نص\nب ‪-‬ فقرة\n12",
    ]);
    $paths = collect($units)->pluck('clause_path')->filter()->values()->all();
    expect($paths)->toBe(['5/أولاً', '5/ثانياً', '5/ثانياً/ب']);
    expect(collect($units)->firstWhere('clause_path', null)['text'])->not->toContain('12');
});
