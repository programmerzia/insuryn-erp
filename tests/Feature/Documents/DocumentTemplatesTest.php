<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Documents\Templates\DefaultDocumentTemplates;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Documents\Templates\DocumentTemplates;
use App\Modules\Platform\Documents\Templates\DocumentVariables;
use App\Modules\Platform\Documents\Templates\TemplateBodyGuard;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Phase 3 slice R8 (design §3): tenant-editable document templates — versions per code, class and locale, one active, active versions never
 * edited, unsafe Blade refused on save with a clear message, and a preview with demo data for every template in English and Bangla.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->admin = userWithPermissions($this->ctx['tenant_id'], ['document.manage_templates']);
    $this->templates = fn (): DocumentTemplates => app(DocumentTemplates::class);
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    seedDocumentTemplates($this->ctx['tenant_id']);
});

it('seeds an active version 1 of every template in English and Bangla, and seeding again adds nothing', function (): void {
    ($this->in)(function (): void {
        $rows = DB::table('document_templates')->orderBy('code')->orderBy('locale')->get();
        expect($rows)->toHaveCount(count(DocumentTemplateCode::cases()) * 2)
            ->and($rows->pluck('status')->unique()->values()->all())->toBe(['active'])
            ->and($rows->pluck('version')->unique()->values()->all())->toBe([1])
            ->and($rows->whereNotNull('product_class')->count())->toBe(0)
            ->and(DB::table('document_templates')->where('code', 'policy_schedule')->where('locale', 'bn')->value('body'))->toContain('বিশেষ শর্তাবলি');
        expect(($this->templates)()->seedCurrentTenant())->toBe(0)
            ->and(DB::table('document_templates')->count())->toBe(count(DocumentTemplateCode::cases()) * 2);
    });
});

it('versions a template: editing the active version makes a draft, activating it retires the old one, and only one is ever active', function (): void {
    ($this->in)(function (): void {
        $templates = ($this->templates)();
        $v1 = $templates->active(DocumentTemplateCode::Receipt, null, 'en');
        expect($v1)->not->toBeNull();

        $v2 = $templates->saveDraft($v1->id, $v1->body.'<p>Thank you for your payment.</p>', null, $this->admin);
        expect($v2->id)->not->toBe($v1->id)->and($v2->version)->toBe(2)->and($v2->status->value)->toBe('draft')
            ->and($templates->find($v1->id)->body)->toBe($v1->body)->and($templates->find($v1->id)->status->value)->toBe('active');

        // A draft is edited in place; a second draft beside it is refused.
        $again = $templates->saveDraft($v2->id, $v2->body.'<p>Keep this receipt.</p>', '<div class="entity">{{ $company[\'name\'] }}</div>', $this->admin);
        expect($again->id)->toBe($v2->id)->and($again->letterhead)->toContain('company');
        expect(thrownBy(fn () => $templates->saveDraft($v1->id, $v1->body, null, $this->admin), BusinessRuleViolation::class)->reasonCode)->toBe('DOCUMENT_TEMPLATE_DRAFT_EXISTS');

        $active = $templates->activate($v2->id, $this->admin);
        expect($active->status->value)->toBe('active')->and($active->activatedBy)->toBe($this->admin)
            ->and($templates->find($v1->id)->status->value)->toBe('retired')
            ->and($templates->active(DocumentTemplateCode::Receipt, null, 'en')?->id)->toBe($v2->id)
            ->and(DB::table('document_templates')->where('code', 'receipt')->where('locale', 'en')->where('status', 'active')->count())->toBe(1);
        expect(thrownBy(fn () => $templates->activate($v2->id, $this->admin), BusinessRuleViolation::class)->reasonCode)->toBe('DOCUMENT_TEMPLATE_NOT_DRAFT');

        // A class template wins over the every-class template for that class only.
        $motor = $templates->create('receipt', 'motor', 'en', null, null, $this->admin);
        expect($motor->version)->toBe(1)->and($motor->body)->toBe($active->body);
        $templates->activate($motor->id, $this->admin);
        expect($templates->active(DocumentTemplateCode::Receipt, 'motor', 'en')?->id)->toBe($motor->id)
            ->and($templates->active(DocumentTemplateCode::Receipt, 'fire', 'en')?->id)->toBe($v2->id);
        expect(thrownBy(fn () => $templates->create('receipt', 'spaceships', 'en', null, null, $this->admin), BusinessRuleViolation::class)->reasonCode)->toBe('DOCUMENT_TEMPLATE_CLASS_UNKNOWN');

        $actions = DB::table('audit_events')->where('object_type', 'document_template')->orderBy('occurred_at')->orderBy('id')->pluck('action')->all();
        expect($actions)->toBe(['document_template.created', 'document_template.draft_saved', 'document_template.retired', 'document_template.activated',
            'document_template.created', 'document_template.activated']);
    });
});

it('never edits, deletes or reactivates an active or retired version in the database', function (): void {
    ($this->in)(function (): void {
        $templates = ($this->templates)();
        $v1 = $templates->active(DocumentTemplateCode::PolicySchedule, null, 'en');
        $v2 = $templates->saveDraft($v1->id, $v1->body.'<p>v2</p>', null, $this->admin);
        $templates->activate($v2->id, $this->admin);

        expect(fn () => DB::table('document_templates')->where('id', $v2->id)->update(['body' => 'changed']))->toThrow(QueryException::class, 'DOCUMENT_TEMPLATE_IMMUTABLE');
        expect(fn () => DB::table('document_templates')->where('id', $v1->id)->update(['letterhead' => 'x']))->toThrow(QueryException::class, 'DOCUMENT_TEMPLATE_IMMUTABLE');
        expect(fn () => DB::table('document_templates')->where('id', $v1->id)->update(['status' => 'active', 'retired_at' => null]))->toThrow(QueryException::class);
        expect(fn () => DB::table('document_templates')->where('id', $v2->id)->delete())->toThrow(QueryException::class, 'DOCUMENT_TEMPLATE_IMMUTABLE');
        expect(fn () => DB::table('document_templates')->where('id', $v2->id)->update(['version' => 9]))->toThrow(QueryException::class, 'DOCUMENT_TEMPLATE_IMMUTABLE');
        // The partial unique index keeps one active version even if the service is bypassed.
        $v3 = $templates->saveDraft($v2->id, $v2->body.'<p>v3</p>', null, $this->admin);
        expect(fn () => DB::table('document_templates')->where('id', $v3->id)->update(['status' => 'active', 'activated_at' => now()]))->toThrow(QueryException::class, 'document_templates_one_active');
    });
});

dataset('unsafe bodies', [
    'php block' => ['<p>@php echo 1; @endphp</p>', '@php is not allowed'],
    'php tag' => ['<p><?php system("id"); ?></p>', 'PHP tags'],
    'short echo tag' => ['<?= 1 ?>', 'PHP tags'],
    'raw output' => ['{!! $company[\'name\'] !!}', 'Unescaped output'],
    'include' => ["@include('welcome')", '@include is not allowed'],
    'inject' => ["@inject('db', 'db')", '@inject is not allowed'],
    'extends' => ["@extends('layouts.app')", '@extends is not allowed'],
    'component tag' => ['<x-alert />', 'Blade components'],
    'function call in output' => ["{{ system('id') }}", 'Only variables can be printed'],
    'method call in output' => ['{{ $company->name }}', 'Only variables can be printed'],
    'function in condition' => ["@if(file_exists('/etc/passwd')) yes @endif", 'is not allowed. Conditions'],
    'php hidden in a quoted fallback' => ["{{ \$company['name'] ?? '@php(system(1))' }}", 'Only variables can be printed'],
    'directive glued to an echo' => ["a{{ \$currency }}@php(system('id'))", '@php is not allowed'],
    'unknown variable' => ['{{ $app }}', '$app is not a variable of this template'],
    'environment variable' => ['{{ $__env }}', 'Only variables can be printed'],
    'script' => ['<script>alert(1)</script>', 'Scripts, frames'],
    'iframe' => ['<iframe src="data:text/html,x"></iframe>', 'Scripts, frames'],
    'event handler' => ['<img src="data:image/png;base64,AA" onerror="alert(1)">', 'Event handler attributes'],
    'local file' => ['<img src="file:///etc/passwd">', 'References to files'],
    'remote image' => ['<img src="https://example.com/logo.png">', 'Links to other addresses'],
    'css import' => ['<style>@import url(data:text/css,x);</style>', 'CSS @import'],
    'unclosed if' => ['@if($parties) open', '@if is not closed'],
]);

it('refuses unsafe bodies on save with a message that says what is not allowed', function (string $body, string $message): void {
    ($this->in)(function () use ($body, $message): void {
        $templates = ($this->templates)();
        $v1 = $templates->active(DocumentTemplateCode::Quotation, null, 'en');
        $refusal = thrownBy(fn () => $templates->saveDraft($v1->id, $body, null, $this->admin), BusinessRuleViolation::class);
        expect($refusal->reasonCode)->toBe('DOCUMENT_TEMPLATE_UNSAFE')->and($refusal->getMessage())->toContain($message);
        // Also as a letterhead, and on create.
        expect(thrownBy(fn () => $templates->saveDraft($v1->id, $v1->body, $body, $this->admin), BusinessRuleViolation::class)->getMessage())->toStartWith('Letterhead: ');
        expect(DB::table('document_templates')->where('code', 'quotation')->count())->toBe(2);
    });
})->with('unsafe bodies');

it('accepts the safe subset: output, fallbacks, conditions, loops, comments, CSS at-rules and a data-URI logo', function (): void {
    $body = <<<'BLADE'
{{-- a comment with @php and {!! inside is dropped --}}
<style>@page { size: A4; } @media print { h1 { color: black; } }</style>
<img alt="logo" src="data:image/png;base64,iVBORw0KGgo=">
<h1>{{ $document['title'] }}</h1><p>Write to @@claims or info@padma.example</p>
@if($parties && $document['number'] != '') <p>{{ $parties[0]['name'] ?? 'Customer' }}</p> @elseif(! $details) none @else other @endif
@foreach($money as $row)<p>{{ $row['label'] }} {{ $row['amount'] }}</p>@endforeach
BLADE;
    expect(TemplateBodyGuard::problems($body, DocumentVariables::NAMES))->toBe([]);

    ($this->in)(function () use ($body): void {
        $html = ($this->templates)()->preview(DocumentTemplateCode::Quotation, 'en', $body, null)->html;
        expect($html)->toContain('Quotation')->toContain('Rahima Akter')->toContain('26,885.48')->toContain('Write to @claims or info@padma.example');
        expect(str_contains($html, 'a comment'))->toBeFalse();
    });
});

it('escapes every value and reports a body that cannot render with the variables', function (): void {
    ($this->in)(function (): void {
        $templates = ($this->templates)();
        $html = app(App\Modules\Platform\Documents\Rendering\TemplateRenderer::class)->fragment('<p>{{ $company[\'name\'] }}</p>', ['company' => ['name' => '<script>alert(1)</script> & Co']]);
        expect($html)->toBe('<p>&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co</p>');

        $v1 = $templates->active(DocumentTemplateCode::Receipt, null, 'en');
        $refusal = thrownBy(fn () => $templates->saveDraft($v1->id, '<p>{{ $company[\'missing\'] }}</p>', null, $this->admin), BusinessRuleViolation::class);
        expect($refusal->reasonCode)->toBe('DOCUMENT_TEMPLATE_RENDER_FAILED')->and($refusal->getMessage())->toContain('missing');
        expect(thrownBy(fn () => $templates->saveDraft($v1->id, '<p>{{ $parties }}</p>', null, $this->admin), BusinessRuleViolation::class)->reasonCode)->toBe('DOCUMENT_TEMPLATE_RENDER_FAILED');
    });
});

it('previews every default template in English and Bangla with demo data', function (string $locale): void {
    ($this->in)(function () use ($locale): void {
        foreach (DocumentTemplateCode::cases() as $code) {
            $body = DefaultDocumentTemplates::body($code, $locale);
            expect(TemplateBodyGuard::problems($body, DocumentVariables::NAMES))->toBe([], "{$code->value} {$locale}");
            $demo = DocumentVariables::demo($code, $locale);
            $html = ($this->templates)()->preview($code, $locale, (string) ($this->templates)()->active($code, null, $locale)?->body, null)->html;
            expect($html)->toContain('<html lang="'.$locale.'"')
                ->toContain(e($code->title($locale)))
                ->toContain(e($demo['document']['number']))
                ->toContain(e($demo['company']['name']))
                ->toContain('Noto Sans Bengali')
                ->toContain($locale === 'bn' ? 'রেফারেন্স' : 'Reference');
            if ($demo['total']['amount'] !== '') {
                expect($html)->toContain($demo['total']['amount']);
            }
            foreach ($demo['parties'] as $party) {
                expect($html)->toContain(e($party['name']));
            }
        }
        expect(($this->templates)()->preview(DocumentTemplateCode::PolicySchedule, $locale, DefaultDocumentTemplates::body(DocumentTemplateCode::PolicySchedule, $locale), null)->html)
            ->toContain($locale === 'bn' ? 'বিশেষ শর্তাবলি' : 'Special terms')->toContain('15,459.15');
        expect(DB::table('document_templates')->count())->toBe(16); // previews store nothing
    });
})->with(['en', 'bn']);

it('lets only document.manage_templates change templates, on the service and the screens', function (): void {
    $clerk = userWithPermissions($this->ctx['tenant_id'], ['policy.issue', 'document.generate']);
    ($this->in)(function () use ($clerk): void {
        $v1 = ($this->templates)()->active(DocumentTemplateCode::Receipt, null, 'en');
        expect(fn () => ($this->templates)()->saveDraft($v1->id, $v1->body, null, $clerk))->toThrow(PermissionDenied::class);
        expect(fn () => ($this->templates)()->create('receipt', 'motor', 'bn', null, null, $clerk))->toThrow(PermissionDenied::class);
    });
    $clerkUser = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $clerk));
    $admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->admin));
    actingAs($clerkUser)->get('/documents/templates', $this->headers)->assertForbidden();
    actingAs($clerkUser)->postJson('/documents/templates/preview', ['code' => 'receipt', 'locale' => 'en', 'body' => '<p>x</p>'], $this->headers)->assertForbidden();

    $v1 = ($this->in)(fn () => ($this->templates)()->active(DocumentTemplateCode::Receipt, null, 'bn'));
    actingAs($admin)->get('/documents/templates', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('documents/templates/Index')->has('templates', 16)->has('codes', 8)->where('templates.0.status', 'active'));
    actingAs($admin)->get("/documents/templates/{$v1->id}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('documents/templates/Edit')->where('template.id', $v1->id)->where('template.body', $v1->body)->has('variables', count(DocumentVariables::NAMES))->has('versions', 1));

    actingAs($admin)->postJson('/documents/templates/preview', ['code' => 'receipt', 'locale' => 'bn', 'body' => $v1->body], $this->headers)
        ->assertOk()->assertJsonPath('reason', null)->assertJson(fn ($json) => $json->where('html', fn (string $html): bool => str_contains($html, 'প্রাপ্তি রসিদ'))->etc());
    actingAs($admin)->postJson('/documents/templates/preview', ['code' => 'receipt', 'locale' => 'en', 'body' => '@php echo 1; @endphp'], $this->headers)
        ->assertStatus(422)->assertJsonPath('reason', 'DOCUMENT_TEMPLATE_UNSAFE')->assertJsonPath('html', null);
    actingAs($admin)->get("/documents/templates/{$v1->id}/preview", $this->headers)->assertOk()->assertHeader('Content-Security-Policy');

    actingAs($admin)->put("/documents/templates/{$v1->id}", ['body' => '<p>{{ system(1) }}</p>', 'letterhead' => ''], $this->headers)
        ->assertSessionHasErrors(['reason' => 'DOCUMENT_TEMPLATE_UNSAFE']);
    $response = actingAs($admin)->put("/documents/templates/{$v1->id}", ['body' => $v1->body.'<p>ধন্যবাদ</p>', 'letterhead' => ''], $this->headers)->assertSessionHasNoErrors();
    $draft = ($this->in)(fn () => DB::table('document_templates')->where('code', 'receipt')->where('locale', 'bn')->where('status', 'draft')->sole());
    $response->assertRedirect("/documents/templates/{$draft->id}");
    actingAs($admin)->post("/documents/templates/{$draft->id}/activate", [], $this->headers)->assertRedirect("/documents/templates/{$draft->id}")->assertSessionHas('status', 'Version 2 is now in use.');
    actingAs($admin)->post('/documents/templates', ['code' => 'claim_ack', 'product_class' => 'fire', 'locale' => 'en'], $this->headers)->assertSessionHasNoErrors();
    expect(($this->in)(fn () => DB::table('document_templates')->where('code', 'claim_ack')->where('product_class', 'fire')->value('status')))->toBe('draft');
});
