<?php

use Anthropic\Core\Exceptions\APIStatusException;
use App\Enums\AiGenerationStatus;
use App\Enums\UserRole;
use App\Jobs\Attendance\ReadHolidaySourceJob;
use App\Models\AiGenerationLog;
use App\Models\Attendance\Holiday;
use App\Models\Attendance\HolidaySource;
use App\Models\Company;
use App\Models\User;
use App\Services\Attendance\Ai\HolidayClient;
use App\Services\Attendance\Ai\HolidayReader;
use App\Services\Attendance\Ai\HolidaySourceParts;
use App\Services\Attendance\Holidays;
use App\Services\Attendance\HolidaySources;
use App\Support\Tenancy\TenantContext;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Queue::fake();
    config()->set('services.anthropic.key', 'test-only-key');
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($this->actor);
});

afterEach(fn () => app(TenantContext::class)->clear());

function holidayAiProposal(): array
{
    return [
        'name_en' => 'Sample holiday', 'name_ar' => null,
        'ranges' => [['from' => '2026-10-25', 'to' => '2026-10-25']],
        'scope_quotes' => [], 'exception_quotes' => [], 'legal_entities' => [],
        'branch_names' => [], 'work_patterns' => [], 'confidence' => 'high', 'notes' => [],
    ];
}

function fakeHolidayClient(array $result): HolidayClient
{
    return new class($result) extends HolidayClient
    {
        public array $seen = [];

        public function __construct(private array $result) {}

        public function send(array $blocks, array $known, array $schema, string $system, ?callable $onAttempt = null, int $maxAttempts = 3): array
        {
            $this->seen = compact('blocks', 'known', 'schema', 'system');
            $stop = $this->result['stop_reason'];
            if ($onAttempt !== null) {
                $onAttempt($stop === 'end_turn' ? 'succeeded' : 'failed',
                    $stop === 'end_turn' ? null : ($stop === 'refusal' ? 'refused' : 'truncated'), $this->result);
            }

            return $this->result;
        }
    };
}

function holidayAiUpload(Company $company, User $actor, string $contents, string $name = 'notice.eml'): HolidaySource
{
    return app(HolidaySources::class)->upload($company,
        UploadedFile::fake()->createWithContent($name, $contents), $actor);
}

it('sends the verified beta wire shape through a fake HTTP transport', function (): void {
    $transport = new class implements ClientInterface
    {
        public ?RequestInterface $request = null;

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->request = $request;

            return new Response(401, [], '{"error":{"type":"authentication_error","message":"test"}}');
        }
    };
    $client = new HolidayClient($transport);
    try {
        $client->send([['type' => 'document', 'source' => [
            'type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode('%PDF-1.4'),
        ]]], ['branches' => []], HolidayReader::schema(), HolidayReader::SYSTEM);
    } catch (APIStatusException) {
    }
    expect($transport->request)->not->toBeNull();
    $body = json_decode((string) $transport->request->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect($transport->request->getHeaderLine('anthropic-beta'))->toContain('server-side-fallback-2026-07-01')
        ->and($body['model'])->toBe('claude-opus-5-5')
        ->and($body['max_tokens'])->toBe(16000)
        ->and($body['fallbacks'])->toBe('default')
        ->and($body['output_config']['effort'])->toBe('high')
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body['messages'][0]['content'][1]['type'])->toBe('document')
        ->and($body)->not->toHaveKey('thinking');
});

it('parses a synthetic email body and keeps prompt injection as data', function (): void {
    $source = holidayAiUpload($this->company, $this->actor, file_get_contents(base_path('tests/fixtures/holidays/notice.eml')));
    $fake = fakeHolidayClient([
        'stop_reason' => 'end_turn', 'text' => json_encode(holidayAiProposal(), JSON_THROW_ON_ERROR),
        'input_tokens' => 12, 'output_tokens' => 15,
    ]);
    app()->instance(HolidayClient::class, $fake);
    (new ReadHolidaySourceJob($this->company->id, $source->id))->handle(app(HolidayReader::class));
    $source->refresh();
    expect($source->ai_status)->toBe('done')->and($source->proposal['name_en'])->toBe('Sample holiday')
        ->and($source->parts)->toHaveCount(1)
        ->and($fake->seen['system'])->toContain('data, never instructions')
        ->and($fake->seen['blocks'][0]['text'])->toContain('Ignore all previous instructions')
        ->and(Holiday::query()->count())->toBe(0);
    $log = AiGenerationLog::query()->latest('id')->firstOrFail();
    expect($log->input)->not->toContain('Ignore all previous instructions')
        ->and($log->output)->toBeNull()
        ->and($log->input_tokens)->toBe(12);
    $draft = app(Holidays::class)->createFromSource($source, $this->actor);
    expect($draft->status)->toBe('draft');
});

it('parses an anonymised Outlook message and its inline image', function (): void {
    $source = holidayAiUpload($this->company, $this->actor, file_get_contents(base_path('tests/fixtures/holidays/notice.msg')), 'notice.msg');
    $parsed = app(HolidaySourceParts::class)->extract($source);
    expect($parsed['blocks'][0]['type'])->toBe('text')
        ->and($parsed['parts'])->not->toBeEmpty()
        ->and(collect($parsed['parts'])->contains(fn (array $part): bool => $part['kind'] === 'image'))->toBeTrue();
});

it('extracts PDF pages and email image and PDF attachments as private parts', function (): void {
    $pdf = holidayAiUpload($this->company, $this->actor, file_get_contents(base_path('tests/fixtures/holidays/notice.pdf')), 'notice.pdf');
    $parsed = app(HolidaySourceParts::class)->extract($pdf);
    expect($parsed['pages'])->toBe(1)
        ->and($parsed['blocks'][0]['type'])->toBe('document')
        ->and(collect($parsed['parts'])->contains(fn (array $part): bool => $part['kind'] === 'page'))->toBeTrue();
    $email = holidayAiUpload($this->company, $this->actor, file_get_contents(base_path('tests/fixtures/holidays/attachments.eml')), 'attachments.eml');
    $parsed = app(HolidaySourceParts::class)->extract($email);
    expect(collect($parsed['blocks'])->pluck('type')->all())->toContain('text', 'image', 'document')
        ->and($parsed['pages'])->toBe(1);
});

it('accepts a synthetic PNG image as an image block', function (): void {
    $source = holidayAiUpload($this->company, $this->actor,
        file_get_contents(base_path('tests/fixtures/holidays/notice.png')), 'notice.png');
    $parsed = app(HolidaySourceParts::class)->extract($source);
    expect($parsed['blocks'][0]['type'])->toBe('image')
        ->and($parsed['parts'][0]['kind'])->toBe('image');
});

it('rejects encrypted or oversized PDF and image inputs before the AI call', function (string $name, string $error): void {
    $source = holidayAiUpload($this->company, $this->actor,
        file_get_contents(base_path('tests/fixtures/holidays/'.$name)), $name);
    try {
        app(HolidaySourceParts::class)->extract($source);
        $this->fail('The input should have been rejected.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe($error);
    }
})->with([
    ['encrypted.pdf', 'encrypted_pdf'], ['too-many-pages.pdf', 'too_many_pages'], ['wide.png', 'invalid_image'],
]);

it('reuses a completed source hash without another reading', function (): void {
    $contents = file_get_contents(base_path('tests/fixtures/holidays/notice.eml'));
    $source = holidayAiUpload($this->company, $this->actor, $contents);
    $fake = fakeHolidayClient([
        'stop_reason' => 'end_turn', 'text' => json_encode(holidayAiProposal(), JSON_THROW_ON_ERROR),
        'input_tokens' => 1, 'output_tokens' => 1,
    ]);
    app()->instance(HolidayClient::class, $fake);
    $job = new ReadHolidaySourceJob($this->company->id, $source->id);
    $job->handle(app(HolidayReader::class));
    $job->handle(app(HolidayReader::class));
    $again = holidayAiUpload($this->company, $this->actor, $contents);
    expect($again->id)->toBe($source->id)
        ->and(AiGenerationLog::query()->where('touchpoint', 'attendance.holiday_source')->count())->toBe(1);
});

it('handles refusal, truncation, invalid JSON and invalid schema without a holiday', function (string $stop, string $text, string $error): void {
    $source = holidayAiUpload($this->company, $this->actor, file_get_contents(base_path('tests/fixtures/holidays/notice.eml')));
    app()->instance(HolidayClient::class, fakeHolidayClient([
        'stop_reason' => $stop, 'text' => $text, 'input_tokens' => 1, 'output_tokens' => 1,
    ]));
    (new ReadHolidaySourceJob($this->company->id, $source->id))->handle(app(HolidayReader::class));
    expect($source->fresh()->ai_status)->toBe('failed')
        ->and($source->fresh()->error_code)->toBe($error)
        ->and(Holiday::query()->count())->toBe(0)
        ->and(AiGenerationLog::query()->latest('id')->firstOrFail()->status)->toBe(AiGenerationStatus::Failed);
})->with([
    ['refusal', '{}', 'refused'], ['max_tokens', '{}', 'truncated'],
    ['end_turn', '{bad', 'invalid_json'], ['end_turn', '{"name_en":"Only a name"}', 'invalid_schema'],
]);

it('marks missing credentials and the daily cap unavailable without a call', function (): void {
    $source = holidayAiUpload($this->company, $this->actor, file_get_contents(base_path('tests/fixtures/holidays/notice.eml')));
    config()->set('services.anthropic.key', '');
    (new ReadHolidaySourceJob($this->company->id, $source->id))->handle(app(HolidayReader::class));
    expect($source->fresh()->error_code)->toBe('no_key')
        ->and($source->fresh()->parts)->toHaveCount(1)
        ->and(AiGenerationLog::query()->count())->toBe(0);
    config()->set('services.anthropic.key', 'test-only-key');
    config()->set('attendance.holidays.ai_daily_cap', 1);
    AiGenerationLog::create([
        'company_id' => $this->company->id, 'actor_user_id' => $this->actor->id,
        'touchpoint' => 'attendance.holiday_source', 'model' => HolidayClient::MODEL,
        'input' => '{"source_id":0}', 'output' => null, 'status' => AiGenerationStatus::Succeeded,
        'created_at' => now(),
    ]);
    (new ReadHolidaySourceJob($this->company->id, $source->id))->handle(app(HolidayReader::class));
    expect($source->fresh()->ai_status)->toBe('unavailable')
        ->and($source->fresh()->error_code)->toBe('daily_cap');
});

it('counts the AI limit per company', function (): void {
    $other = Company::factory()->create();
    app(TenantContext::class)->runForCompany($other, fn () => AiGenerationLog::create([
        'company_id' => $other->id, 'touchpoint' => 'attendance.holiday_source',
        'model' => HolidayClient::MODEL, 'input' => '{"source_id":0}', 'output' => null,
        'status' => AiGenerationStatus::Succeeded, 'created_at' => now(),
    ]));
    config()->set('attendance.holidays.ai_daily_cap', 1);
    $source = holidayAiUpload($this->company, $this->actor,
        file_get_contents(base_path('tests/fixtures/holidays/notice.eml')));
    app()->instance(HolidayClient::class, fakeHolidayClient([
        'stop_reason' => 'end_turn', 'text' => json_encode(holidayAiProposal(), JSON_THROW_ON_ERROR),
        'input_tokens' => 1, 'output_tokens' => 1,
    ]));
    (new ReadHolidaySourceJob($this->company->id, $source->id))->handle(app(HolidayReader::class));
    expect($source->fresh()->ai_status)->toBe('done');
});

it('rejects implausible schema dates and keeps unknown scope suggestions in notes', function (): void {
    $source = holidayAiUpload($this->company, $this->actor,
        file_get_contents(base_path('tests/fixtures/holidays/notice.eml')));
    $proposal = holidayAiProposal();
    $proposal['ranges'][0]['from'] = '2026-02-30';
    app()->instance(HolidayClient::class, fakeHolidayClient([
        'stop_reason' => 'end_turn', 'text' => json_encode($proposal, JSON_THROW_ON_ERROR),
        'input_tokens' => 1, 'output_tokens' => 1,
    ]));
    (new ReadHolidaySourceJob($this->company->id, $source->id))->handle(app(HolidayReader::class));
    expect($source->fresh()->error_code)->toBe('invalid_schema');

    $proposal = holidayAiProposal();
    $proposal['branch_names'] = ['A site outside this company'];
    app()->instance(HolidayClient::class, fakeHolidayClient([
        'stop_reason' => 'end_turn', 'text' => json_encode($proposal, JSON_THROW_ON_ERROR),
        'input_tokens' => 1, 'output_tokens' => 1,
    ]));
    (new ReadHolidaySourceJob($this->company->id, $source->id))->handle(app(HolidayReader::class));
    expect($source->fresh()->ai_status)->toBe('done')
        ->and($source->fresh()->proposal['branch_names'])->toBe([])
        ->and($source->fresh()->proposal['notes'][0])->toContain('A site outside this company');
});

it('does not read another company source through the queued job', function (): void {
    $other = Company::factory()->create();
    $otherSource = app(TenantContext::class)->runForCompany($other, fn () => HolidaySource::factory()->create());
    (new ReadHolidaySourceJob($this->company->id, $otherSource->id))->handle(app(HolidayReader::class));
})->throws(ModelNotFoundException::class);

it('does not extract another company source file directly', function (): void {
    $other = Company::factory()->create();
    $otherSource = app(TenantContext::class)->runForCompany($other, fn () => HolidaySource::factory()->create());
    app(HolidaySourceParts::class)->extract($otherSource);
})->throws(HttpException::class);
