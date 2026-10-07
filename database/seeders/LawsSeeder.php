<?php

namespace Database\Seeders;

use App\Enums\Jurisdiction;
use App\Enums\LawApprovalStatus;
use App\Enums\LawKind;
use App\Models\Law;
use App\Models\LawJurisdiction;
use Illuminate\Database\Seeder;

class LawsSeeder extends Seeder
{
    public function run(): void
    {
        $laws = [
            ['قانون العمل رقم (37) لسنة 2015', '37', 2015, 'labor', 'docs/laws/labor-law-37-2015-gazette-4386.pdf', [Jurisdiction::FederalIraq]],
            ['قانون العمل رقم (71) لسنة 1987', '71', 1987, 'labor', 'docs/laws/labor-law-71-1987.pdf', [Jurisdiction::KurdistanRegion]],
            ['قانون التقاعد والضمان الاجتماعي للعمال رقم (18) لسنة 2023', '18', 2023, 'social_security', 'docs/laws/gazette-4734-incl-social-security-law-18-2023.pdf', [Jurisdiction::FederalIraq, Jurisdiction::KurdistanRegion]],
        ];

        foreach ($laws as [$name, $number, $year, $category, $source, $jurisdictions]) {
            $law = Law::create([
                'company_id' => null,
                'kind' => LawKind::Statute,
                'category' => $category,
                'name' => $name,
                'number' => $number,
                'year' => $year,
                'source_file_path' => $source,
                // Law 37's text layer is garbage and Law 71's maps ك to آ; OCR reads both correctly (research R14).
                'import_mode' => in_array($number, ['37', '71'], true) ? 'ocr' : 'auto',
                'requires_page_range' => $number === '18' && $year === 2023,
                'approval_status' => LawApprovalStatus::Approved,
            ]);

            foreach ($jurisdictions as $jurisdiction) {
                LawJurisdiction::create(['law_id' => $law->id, 'jurisdiction' => $jurisdiction]);
            }
        }
    }
}
