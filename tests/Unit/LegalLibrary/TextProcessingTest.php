<?php

use App\LegalLibrary\ArticleSplitter;
use App\LegalLibrary\PageFurniture;
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

test('gazette dashes and the zero full stop compare equal but real wording still differs', function (): void {
    $normalizer = new TextNormalizer;
    // Tatweel drawn as a dash, an en dash and a hyphen are one separator; "٠" after a word is the gazette full stop.
    expect($normalizer->comparable('حادي عشر ــ العمل العرضي'))->toBe($normalizer->comparable('حادي عشر- العمل العرضي'))
        ->and($normalizer->comparable('أـ اسم صاحب العمل'))->toBe($normalizer->comparable('أ- اسم صاحب العمل'))
        ->and($normalizer->comparable('المادة –١٢٠– أولاً'))->toBe($normalizer->comparable('المادة -١٢٠- أولاً'))
        ->and($normalizer->comparable('وعنوانه ٠'))->toBe($normalizer->comparable('وعنوانه .'))
        ->and($normalizer->comparable('عشر‍ــ العمل'))->toBe($normalizer->comparable('عشر- العمل'));
    // Numbers keep their zeros, and different words or amounts never become equal.
    expect($normalizer->comparable('(١،٠٠٠،٠٠٠) مليون'))->not->toBe($normalizer->comparable('(١٠،٠٠٠،٠٠٠) مليون'))
        ->and($normalizer->comparable('ما لم يكن'))->not->toBe($normalizer->comparable('ما لم يكون'))
        ->and($normalizer->normalize('مادةـ ١٥'))->toBe('مادة 15');
});

test('the splitter still sees clause dashes after tatweel and en dash normalisation', function (): void {
    $splitter = new ArticleSplitter(new TextNormalizer);
    $paths = fn (string $text) => array_column($splitter->split([1 => $text]), 'clause_path');
    // The en-dash heading is still an article, and tatweel clause markers split exactly like hyphen markers.
    expect(array_column($splitter->split([1 => 'المادة –٣٧– نص']), 'article_number'))->toBe(['37'])
        ->and($paths('المادة –٣٧– نص
أولاً: يبرم
أـ اسم صاحب العمل
ب ــ اسم العامل'))
        ->toBe($paths('المادة -٣٧- نص
أولاً: يبرم
أ- اسم صاحب العمل
ب- اسم العامل'))
        ->toContain('37/أولاً/أ', '37/أولاً/ب');
});

test('running headers and footers repeated across pages are removed from both readings', function (): void {
    $pages = [];
    $bodies = [1 => 'العمل حق لكل مواطن', 2 => 'حرية العمل مصونة', 3 => 'يحظر العمل الجبري',
        4 => 'تحتسب المدد بالتقويم الميلادي', 5 => 'اللغة العربية هي المعتمدة', 6 => 'يلتزم صاحب العمل بالتشغيل'];
    foreach ($bodies as $page => $body) {
        $pages[$page] = "قوانين\nالمادة -{$page}- {$body}\nالوقائع العراقية – العدد ٤٣٨٦ ({$page}) ٢٠١٥/١١/٩";
    }
    $pages[7] = "المادة -7- قوانين العمل نافذة\nسطر أخير";
    $stripped = (new PageFurniture)->strip($pages);
    expect($stripped[3])->toBe('المادة -3- يحظر العمل الجبري')
        ->and($stripped[7])->toBe("المادة -7- قوانين العمل نافذة\nسطر أخير");
});

test('a wrapped line starting with a bracketed amount does not open a numbered clause', function (): void {
    $units = (new ArticleSplitter(new TextNormalizer))->split([1 => "المادة -21- نص\nأولاً: ابلاغ قسم التشغيل خلال مدة لاتزيد على\n(10) عشرة ايام من حدوث الشاغر\nثانياً: لصاحب العمل تشغيل العمال"]);
    expect(array_values(array_filter(array_column($units, 'clause_path'))))->toBe(['21/أولاً', '21/ثانياً']);

    $numbered = (new ArticleSplitter(new TextNormalizer))->split([1 => "المادة -5- نص\n(1) مواجهة ضغط عمل غير اعتيادي\n(2) إصلاح او صيانة الاجهزة"]);
    expect(array_values(array_filter(array_column($numbered, 'clause_path'))))->toBe(['5/أولاً', '5/ثانياً']);
});

test('cross-references, wrapped amounts and numbered items inside a letter do not change the structure', function (): void {
    $splitter = new ArticleSplitter(new TextNormalizer);
    $units = $splitter->split([1 => "المادة -44- عند توافر احدى الحالات المنصوص عليها في البند (ثانيا) من\nالمادة (43) من هذا القانون على صاحب العمل انذار العامل\nالمادة -45- يستحق العامل مكافأة"]);
    expect(array_column($units, 'article_number'))->toBe(['44', '45']);

    $units = $splitter->split([1 => "المادة -138- نص\nثانياً: تطبق احدى العقوبات الآتية\nجـ - حجب الزيادة السنوية لمدة لاتزيد على\n(180) مئة وثمانين يوماً .\nد - تنزيل الدرجة ويستتبعه تنزيل اجره"]);
    expect(array_values(array_filter(array_column($units, 'clause_path'))))->toBe(['138/ثانياً', '138/ثانياً/ج', '138/ثانياً/د']);

    $units = $splitter->split([1 => "المادة -129- أولاً: تخول لجان التفتيش\nب - أجراء اي فحص او استفسار\n(1) التحقيق مع صاحب العمل\n(2) الاطلاع على اي كتب او سجلات\nثانياً: اتخاذ الاجراءات العاجلة في حالات الخطر"]);
    expect(array_values(array_filter(array_column($units, 'clause_path'))))->toContain('129/ثانياً')
        ->and(collect($units)->pluck('tags')->pluck('mismatch_reason')->filter()->all())->toBe([]);
});

test('an article heading standing alone at the top of every page is structure, not a running header', function (): void {
    $pages = [];
    foreach ([32 => 'لا يجوز تحديد مدة للعقد', 33 => 'اذا حضر العامل مقر العمل', 34 => 'يلتزم صاحب العمل بما يأتي', 35 => 'للعامل الحق في الاجازة'] as $article => $body) {
        $pages[$article] = "المادة {$article}\n{$body}";
    }
    expect((new PageFurniture)->strip($pages))->toBe($pages);
});
