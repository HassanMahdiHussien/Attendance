<?php

namespace Database\Seeders;

use App\Enums\Jurisdiction;
use App\Enums\LawApprovalStatus;
use App\Enums\LawKind;
use App\LegalLibrary\LegalLibraryArchive;
use App\Models\Law;
use App\Models\LawJurisdiction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class LawsSeeder extends Seeder
{
    public function run(): void
    {
        app(TenantContext::class)->runAcrossAllCompanies(function (): void {
            $laws = [
                ['قانون العمل رقم (37) لسنة 2015', '37', 2015, 'labor', 'docs/laws/labor-law-37-2015-gazette-4386.pdf', [Jurisdiction::FederalIraq]],
                ['قانون العمل رقم (71) لسنة 1987', '71', 1987, 'labor', 'docs/laws/labor-law-71-1987.pdf', [Jurisdiction::KurdistanRegion]],
                ['قانون التقاعد والضمان الاجتماعي للعمال رقم (18) لسنة 2023', '18', 2023, 'social_security', 'docs/laws/gazette-4734-incl-social-security-law-18-2023.pdf', [Jurisdiction::FederalIraq, Jurisdiction::KurdistanRegion]],
            ];

            $englishNames = ['37' => 'Labour Law No. 37 of 2015', '71' => 'Labour Law No. 71 of 1987',
                '18' => 'Retirement and Social Security Law for Workers No. 18 of 2023'];
            foreach ($laws as [$name, $number, $year, $category, $source, $jurisdictions]) {
                $law = Law::firstOrCreate(['kind' => LawKind::Statute, 'number' => $number, 'year' => $year, 'category' => $category], [
                    'company_id' => null,
                    'kind' => LawKind::Statute,
                    'category' => $category,
                    'name' => $name,
                    'name_en' => $englishNames[$number],
                    'number' => $number,
                    'year' => $year,
                    'source_file_path' => $source,
                    // Law 37's text layer is garbage and Law 71's maps ك to آ; OCR reads both correctly (research R14).
                    'import_mode' => in_array($number, ['37', '71'], true) ? 'ocr' : 'auto',
                    'requires_page_range' => $number === '18' && $year === 2023,
                    'approval_status' => LawApprovalStatus::Approved,
                ]);

                foreach ($jurisdictions as $jurisdiction) {
                    LawJurisdiction::firstOrCreate(['law_id' => $law->id, 'jurisdiction' => $jurisdiction]);
                }
            }
            if (is_file(database_path('legal-library/manifest.json'))) {
                app(LegalLibraryArchive::class)->import();
            }
        });
    }
}
