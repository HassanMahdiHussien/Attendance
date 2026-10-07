<?php

use App\Ai\AiUnavailable;
use App\Ai\ClaudeClient;
use App\Models\AiGenerationLog;
use App\Models\Company;
use App\Support\Tenancy\TenantContext;

test('missing API key reports unavailability and logs without a network call', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    config()->set('services.anthropic.key', null);
    expect(fn () => app(ClaudeClient::class)->structured('draft_defense_letter',
        ['type' => 'object', 'properties' => [], 'required' => []], [], ['company_id' => $company->id],
        'claude-opus-5-5', 'high'))->toThrow(AiUnavailable::class);
    expect(AiGenerationLog::where('company_id', $company->id)->where('touchpoint', 'draft_defense_letter')->count())->toBe(1);
});
