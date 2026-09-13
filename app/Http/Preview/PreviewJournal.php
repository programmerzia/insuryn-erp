<?php

declare(strict_types=1);

namespace App\Http\Preview;

use App\Http\Pages\PageSupport;
use App\Modules\Accounting\Application\Contracts\PostingDispatcher;
use App\Modules\Accounting\Application\PostingEngine;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * UX brief §1.6 / §4 "Confirmation dialogs for money movements show a mini journal preview" (slice U5). A request to a `moves-money` route
 * carrying `X-Journal-Preview` runs the real action inside a transaction with a recording dispatcher, posts the submitted events through the
 * real PostingEngine (same rules, periods, dimensions and validation as the real post), reads the journal lines, then rolls everything back
 * and restores the session so no flash survives. Nothing is written; the kernel's write path is not bypassed or duplicated.
 */
final class PreviewJournal
{
    public const HEADER = 'X-Journal-Preview';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has(self::HEADER)) {
            return $next($request);
        }
        $middleware = $request->route()?->gatherMiddleware() ?? [];
        if (! in_array('moves-money', $middleware, true) && ! in_array(MovesMoney::class, $middleware, true)) {
            return new JsonResponse(['message' => 'This action does not post a journal.'], 400);
        }

        $session = $request->hasSession() ? $request->session() : null;
        $sessionBefore = $session?->all();
        $recorder = new RecordingPostingDispatcher();
        app()->instance(PostingDispatcher::class, $recorder);

        DB::beginTransaction();
        try {
            $response = $next($request);
            $refusal = $this->refusal($response, $session?->get('errors'));
            $journals = [];
            $failures = [];
            if ($refusal === null) {
                foreach ($recorder->eventIds as $eventId) {
                    [$journal, $failure] = $this->post($eventId);
                    if ($journal !== null) {
                        $journals[] = $journal;
                    }
                    if ($failure !== null) {
                        $failures[] = $failure;
                    }
                }
            }
        } finally {
            DB::rollBack();
            app()->forgetInstance(PostingDispatcher::class);
            if ($session !== null && $sessionBefore !== null) {
                $session->flush();
                $session->put($sessionBefore);
            }
        }

        if ($refusal !== null) {
            return new JsonResponse(['journals' => [], ...$refusal['body']], $refusal['status']);
        }

        return new JsonResponse(['journals' => $journals, 'failures' => $failures, 'posts' => $journals !== []]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}|null the action's refusal (validation, business rule, permission), or null when it went through
     */
    private function refusal(Response $response, mixed $flashedErrors): ?array
    {
        if ($response instanceof JsonResponse && $response->getStatusCode() >= 400) {
            /** @var array<string, mixed> $body */
            $body = (array) $response->getData(true);
            if (! isset($body['errors']) && isset($body['message'])) {
                $body['errors'] = ['form' => \App\Http\Feedback\ReasonMessages::forPeople((string) ($body['reason'] ?? ''), (string) $body['message']), 'reason' => $body['reason'] ?? null];
            }

            return ['status' => $response->getStatusCode(), 'body' => $body];
        }
        if ($flashedErrors instanceof \Illuminate\Support\ViewErrorBag && $flashedErrors->any()) {
            return ['status' => 422, 'body' => ['errors' => array_map(fn (array $messages): string => (string) ($messages[0] ?? ''), $flashedErrors->getBag('default')->toArray())]];
        }

        return null;
    }

    /**
     * @return array{0: array{event: string, date: string, lines: list<array{account: string, name: string, debit: string|null, credit: string|null, role: string|null}>, totals: array{debit: string, credit: string}}|null, 1: array{event: string, reason: string}|null}
     */
    private function post(string $eventId): array
    {
        $journals = app(PostingEngine::class)->post($eventId);
        $event = DB::table('accounting_events')->where('id', $eventId)->first(['event_type', 'status', 'failure_reason', 'currency']);
        if ($journals === []) {
            return [null, ['event' => (string) ($event->event_type ?? ''), 'reason' => (string) ($event->failure_reason ?? 'Nothing to post.')]];
        }
        $journal = $journals[0];
        $lines = [];
        $debit = 0;
        $credit = 0;
        $currency = (string) ($event->currency ?? '');
        foreach (DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_id', $journal->id)->orderBy('l.line_no')->get(['a.code', 'a.name', 'l.side', 'l.amount_minor', 'l.role_code', 'l.account_id']) as $line) {
            $amount = (int) $line->amount_minor;
            $isDebit = $line->side === 'debit';
            $isDebit ? $debit += $amount : $credit += $amount;
            $lines[] = ['account' => (string) $line->code, 'name' => (string) $line->name,
                'debit' => $isDebit ? PageSupport::money($amount, $currency) : null, 'credit' => $isDebit ? null : PageSupport::money($amount, $currency),
                'role' => $line->role_code === null ? (PageSupport::accountRoles([(string) $line->account_id])[(string) $line->account_id] ?? null) : (string) $line->role_code];
        }

        return [['event' => (string) ($event->event_type ?? ''), 'date' => $journal->posting_date->toDateString(), 'lines' => $lines,
            'totals' => ['debit' => PageSupport::money($debit, $currency), 'credit' => PageSupport::money($credit, $currency)]], null];
    }
}
