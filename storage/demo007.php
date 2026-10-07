<?php
use App\Enums\ImportMode; use App\Jobs\{RenderLegalPages,ReadLegalPageText,ReadLegalPageWithAi,BuildLegalUnits};
use App\LegalLibrary\{LawImportService,PdfPages,TextLayerProbe,ImportProgress,DocxText,ArticleSplitter,UnitBuilder};
use App\Models\{Law,User}; use Illuminate\Support\Facades\Bus; use Tests\Fakes\FakePageReader;
require base_path('tests/Fakes/FakePageReader.php');
Bus::fake();
$fake = new FakePageReader;
$fake->pages = [
 1 => "الوقائع العراقية — العدد 0000\nالمادة 1\nتسري أحكام هذا القانون على المؤسسة.\nالمادة (2)\nأولاً- تحفظ السجلات في موضع آمن.\nثانياً- تتبع الإدارة الخطوات الآتية:\nأ- تدقق الطلبات.\nب- تحفظ النسخ.\nالمادة الثالثة\nتبدأ هذه المادة في الصفحة الأولى. ".str_repeat('وتحفظ نسخة من السجل في موضع آمن. ', 5)."\n1",
 2 => "الوقائع العراقية — العدد 0000\nوتستمر في الصفحة الثانية مع بيان موجز. ".str_repeat('وتراجع الإدارة النسخة عند الحاجة. ', 6)."\nالمادة -4-\nينشر هذا القانون ويعمل به من تاريخ نشره.\n2",
];
app(App\Support\Tenancy\TenantContext::class)->runAcrossAllCompanies(function () use ($fake) {
  $actor = User::where('primary_role','platform_admin')->first();
  $law = Law::create(['kind'=>'statute','category'=>'labor','name'=>'قانون تجريبي للعرض (بيانات اختبار)','number'=>'0','year'=>2026,'source_file_path'=>'tests/fixtures/legal/image-law.pdf','approval_status'=>'approved','import_mode'=>'ocr']);
  $law->jurisdictions()->create(['jurisdiction'=>'federal_iraq']);
  $run = app(LawImportService::class)->start($law, $actor, base_path('tests/fixtures/legal/image-law.pdf'), 1, 2, ImportMode::Ocr);
  (new RenderLegalPages($run->id,'pdf'))->handle(app(PdfPages::class), app(DocxText::class), app(ArticleSplitter::class), app(App\Services\AuditLogger::class));
  foreach ($run->fresh()->pages as $p) { (new ReadLegalPageText($p->id,null))->handle(app(PdfPages::class), app(TextLayerProbe::class), app(ImportProgress::class)); (new ReadLegalPageWithAi($p->id,null))->handle($fake, app(ImportProgress::class)); }
  (new BuildLegalUnits($run->id))->handle(app(UnitBuilder::class));
  echo "law={$law->id} run={$run->id} state=".$run->fresh()->state." units=".App\Models\LegalUnit::where('law_id',$law->id)->pluck('status')->map->value->countBy()->toJson()."\n";
});
