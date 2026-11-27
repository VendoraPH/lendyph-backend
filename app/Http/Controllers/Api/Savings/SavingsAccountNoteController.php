<?php

namespace App\Http\Controllers\Api\Savings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\Note\StoreSavingsAccountNoteRequest;
use App\Http\Resources\Savings\SavingsUserRef;
use App\Models\SavingsAccount;
use App\Models\SavingsAccountNote;
use App\Services\AuditLogService;
use App\Services\Savings\Api\AccountScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * A savings account's notes timeline (gap round S4, Savings TASK 20 "Add
 * Notes"). Reading needs `savings:view`, adding `savings:post`; both only on
 * an account in the caller's branches (another branch's answers 404). A note
 * is never edited or deleted, and each one added writes an audit row on its
 * account with the note's id and length only: the note itself is the
 * permanent record, so its text (which may hold personal data) is never
 * copied into the audit trail.
 */
class SavingsAccountNoteController extends Controller
{
    public function __construct(private readonly AccountScope $scope) {}

    #[OA\Get(
        path: '/api/savings/accounts/{account}/notes',
        summary: 'An account\'s notes',
        description: 'Newest first, paged (`per_page` up to 100, 25 by default). Each note: `id`, `note`, `created_by` {id, name} and `created_at`.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of notes'),
            new OA\Response(response: 403, description: 'Missing savings:view'),
            new OA\Response(response: 404, description: 'No such account in the caller\'s branches'),
        ],
    )]
    public function index(Request $request, int $account): JsonResponse
    {
        $this->authorize('savings:view');
        $found = $this->scope->account($request->user(), $account);
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));

        $page = SavingsAccountNote::query()->with('creator')
            ->where('account_id', $found->id)
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn (SavingsAccountNote $note) => $this->present($note))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/savings/accounts/{account}/notes',
        summary: 'Add a note',
        description: 'Up to 1,000 characters, on an account in any status. Never edited or deleted. Audited on the account (`savings_account_note_added`, with the note\'s id and length, never its text).',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['note'], properties: [
            new OA\Property(property: 'note', type: 'string', maxLength: 1000),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The note'),
            new OA\Response(response: 403, description: 'Missing savings:post'),
            new OA\Response(response: 404, description: 'No such account in the caller\'s branches'),
            new OA\Response(response: 422, description: 'No note, or longer than 1,000 characters'),
        ],
    )]
    public function store(StoreSavingsAccountNoteRequest $request): JsonResponse
    {
        /** @var SavingsAccount $account */
        $account = $request->record();
        $user = $request->user();
        $text = (string) $request->validated('note');

        $note = DB::transaction(function () use ($account, $user, $text): SavingsAccountNote {
            $note = SavingsAccountNote::query()->create(['account_id' => $account->id, 'note' => $text, 'created_by' => $user->id]);

            $length = mb_strlen($text);

            AuditLogService::log(
                'savings_account_note_added',
                $account,
                null,
                ['note_id' => $note->id, 'length' => $length],
                "Note #{$note->id} ({$length} ".($length === 1 ? 'character' : 'characters').") added to savings account {$account->account_number} by {$user->full_name}.",
            );

            return $note;
        });

        return response()->json(['success' => true, 'data' => $this->present($note->load('creator'))], 201);
    }

    /** @return array{id: int, note: string, created_by: array{id: int, name: string}|null, created_at: string|null} */
    private function present(SavingsAccountNote $note): array
    {
        return [
            'id' => $note->id,
            'note' => $note->note,
            'created_by' => SavingsUserRef::from($note->creator),
            'created_at' => $note->created_at?->toJSON(),
        ];
    }
}
