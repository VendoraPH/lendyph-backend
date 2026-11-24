<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipt share links (finish run round 2, decision 6): a link that opens
     * one payment receipt, masked, without signing in.
     *
     * - `token_hash` is the SHA-256 (hex) of a 32-byte random token. The token
     *   itself is shown once, when the link is made, and is never stored.
     * - A link works until `expires_at` (30 days) unless it is revoked first.
     *   At most one link per receipt works at a time (ReceiptShareLinks, under
     *   the receipt's row lock).
     *
     * Listed in TimezoneShift::EXCLUDED_COLUMNS (added after the one-shot
     * shift).
     */
    public function up(): void
    {
        Schema::create('receipt_share_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repayment_id')->constrained('repayments')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_share_links');
    }
};
