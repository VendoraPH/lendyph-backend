<?php

namespace Tests\Feature\Portal\Read;

use App\Models\AuditLog;
use App\Models\CoMaker;
use App\Models\PortalUser;
use App\Services\ValidIdService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Documents a member may see (portal design §1): files staff shared, on the
 * member or their own loans, streamed after sign-in; never a co-maker's file
 * or a valid ID, and never a signed or public link.
 */
class PortalDocumentsTest extends TestCase
{
    use PortalReadFixtures, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('private');
    }

    public function test_only_shared_files_of_the_member_and_their_loans_are_listed(): void
    {
        $me = $this->portalMember();
        $loan = $this->releasedLoanFor($me);
        $onMember = $this->memberDocument($me->borrower);
        $onLoan = $this->memberDocument($loan, ['type' => 'contract', 'label' => null, 'original_filename' => 'contract.pdf']);
        $this->memberDocument($me->borrower, ['label' => 'Not shared'], shared: false);
        $validId = $this->memberDocument($me->borrower, ['type' => ValidIdService::DOCUMENT_TYPE, 'label' => 'umid', 'id_number' => '1234']);
        $coMakerFile = $this->memberDocument($this->coMakerOf($me));

        $data = $this->portalJson('GET', '/api/portal/documents', [], $this->portalToken($me))->assertOk()->json('data');

        $ids = array_column($data['files'], 'id');
        sort($ids);
        $this->assertSame([$onMember->id, $onLoan->id], $ids);
        $this->assertNotContains($validId->id, $ids);
        $this->assertNotContains($coMakerFile->id, $ids);

        $file = collect($data['files'])->firstWhere('id', $onMember->id);
        $this->assertSame(['id', 'title', 'category', 'shared_on', 'file_name', 'size'], array_keys($file));
        $this->assertSame('Membership Certificate', $file['title']);
        $this->assertStringNotContainsString('files/documents', (string) json_encode($data), 'No signed link.');

        $kinds = array_column($data['generated'], 'kind');
        $this->assertContains('loan_statement', $kinds);
        $this->assertContains('share_capital_statement', $kinds);
        $this->assertContains('share_capital_certificate', $kinds);
    }

    public function test_a_shared_file_streams_as_an_attachment_and_is_audited(): void
    {
        $me = $this->portalMember();
        $document = $this->memberDocument($me->borrower);
        Storage::disk('private')->put($document->file_path, '%PDF-1.4 test');

        $response = $this->portalJson('GET', "/api/portal/documents/{$document->id}/download", [], $this->portalToken($me));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('%PDF-1.4 test', $response->streamedContent());

        $row = AuditLog::query()->where('action', 'portal_document_downloaded')->latest('id')->firstOrFail();
        $this->assertSame('member', $row->actor_type);
        $this->assertSame($me->id, (int) $row->portal_user_id);
        $this->assertNull($row->user_id);
    }

    public function test_unshared_co_maker_and_valid_id_files_answer_404(): void
    {
        $me = $this->portalMember();
        $unshared = $this->memberDocument($me->borrower, [], shared: false);
        $validId = $this->memberDocument($me->borrower, ['type' => ValidIdService::DOCUMENT_TYPE, 'label' => 'umid', 'id_number' => '1234']);
        $coMakerFile = $this->memberDocument($this->coMakerOf($me));

        foreach ([$unshared, $validId, $coMakerFile] as $document) {
            Storage::disk('private')->put($document->file_path, 'secret');
            $this->portalJson('GET', "/api/portal/documents/{$document->id}/download", [], $this->portalToken($me))->assertNotFound();
        }
    }

    public function test_a_valid_id_spelled_another_way_is_never_listed_or_streamed(): void
    {
        $me = $this->portalMember();

        foreach (['Valid ID', 'valid-id'] as $type) {
            $document = $this->memberDocument($me->borrower, ['type' => $type]);
            Storage::disk('private')->put($document->file_path, 'secret');

            $this->assertNotContains($document->id, array_column($this->portalJson('GET', '/api/portal/documents', [], $this->portalToken($me))->json('data.files'), 'id'), $type);
            $this->portalJson('GET', "/api/portal/documents/{$document->id}/download", [], $this->portalToken($me))->assertNotFound();
        }
    }

    public function test_a_valid_id_typed_with_any_separator_or_inside_a_longer_type_is_never_listed_or_streamed(): void
    {
        $me = $this->portalMember();

        foreach (['Valid.ID', 'valid/id', 'Valid ID (back)'] as $type) {
            $document = $this->memberDocument($me->borrower, ['type' => $type]);
            Storage::disk('private')->put($document->file_path, 'secret');

            $this->assertNotContains($document->id, array_column($this->portalJson('GET', '/api/portal/documents', [], $this->portalToken($me))->json('data.files'), 'id'), $type);
            $this->portalJson('GET', "/api/portal/documents/{$document->id}/download", [], $this->portalToken($me))->assertNotFound();
        }

        $other = $this->memberDocument($me->borrower, ['type' => 'Valid-until certificate']);
        $this->assertContains($other->id, array_column($this->portalJson('GET', '/api/portal/documents', [], $this->portalToken($me))->json('data.files'), 'id'), 'Only a valid ID is left out.');
    }

    public function test_a_missing_file_answers_404(): void
    {
        $me = $this->portalMember();
        $document = $this->memberDocument($me->borrower);

        $this->portalJson('GET', "/api/portal/documents/{$document->id}/download", [], $this->portalToken($me))->assertNotFound();
    }

    /** A co-maker record standing for the member on someone else's loan; its files are the co-maker's. */
    private function coMakerOf(PortalUser $me): CoMaker
    {
        $other = $this->portalMember();

        return CoMaker::create([
            'borrower_id' => $other->borrower_id,
            'member_borrower_id' => $me->borrower_id,
            'first_name' => 'Co',
            'last_name' => 'Maker',
            'relationship_to_borrower' => 'friend',
            'status' => 'active',
        ]);
    }

    public function test_documents_switched_off_answer_403(): void
    {
        $this->enablePortal(['features' => ['documents' => false]]);
        $me = $this->portalMember();

        $this->portalJson('GET', '/api/portal/documents', [], $this->portalToken($me))->assertForbidden();
    }
}
