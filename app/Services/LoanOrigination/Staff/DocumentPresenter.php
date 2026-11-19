<?php

namespace App\Services\LoanOrigination\Staff;

use App\Models\OriginationDocument;
use App\Models\OriginationDocumentVersion;
use App\Models\OriginationProductSetting;
use App\Models\OriginationSetting;

/**
 * An application document as staff screens read it: its type in words, the
 * current file, every version, the scan result and the review, all in words.
 * No path or disk ever leaves the server; the file is read through the
 * download route.
 */
final class DocumentPresenter
{
    /** @var array<string, array{label: string, required: bool}>|null */
    private ?array $types = null;

    /**
     * The settings' document types by key: label and whether every
     * application needs it.
     *
     * @return array<string, array{label: string, required: bool}>
     */
    public function types(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $types = [];

        foreach ((array) OriginationSetting::current()->document_types as $type) {
            $types[(string) $type['type']] = ['label' => (string) ($type['label'] ?? $type['type']), 'required' => (bool) ($type['required'] ?? false)];
        }

        return $this->types = $types;
    }

    public function label(string $type): string
    {
        return $this->types()[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type));
    }

    /**
     * The document types an application with $productId must hand in: every
     * required type in the settings, plus the product's own.
     *
     * @return list<string>
     */
    public function requiredTypes(?int $productId): array
    {
        $required = array_keys(array_filter($this->types(), fn (array $type) => $type['required']));
        $product = $productId === null ? [] : (array) (OriginationProductSetting::query()->where('loan_product_id', $productId)->first()?->required_documents ?? []);

        return array_values(array_unique([...$required, ...array_map('strval', $product)]));
    }

    /**
     * File names are shown only with $fileNames (the contacts rule): an
     * applicant's file name can hold their name or address.
     *
     * @param  list<string>  $requiredTypes
     * @return array<string, mixed>
     */
    public function present(OriginationDocument $document, array $requiredTypes = [], bool $fileNames = false): array
    {
        $current = $document->currentVersion;

        return [
            'id' => $document->id,
            'public_id' => $document->public_id,
            'type' => $document->type,
            'label' => $this->label($document->type),
            'required' => in_array($document->type, $requiredTypes, true),
            'status' => $document->status,
            'status_label' => OriginationDocument::STATUS_LABELS[$document->status] ?? $document->status,
            'file_name' => $fileNames ? $current?->file_name : null,
            'mime_type' => $current?->mime_type,
            'size_bytes' => $current?->size_bytes,
            'uploaded_at' => $current?->uploaded_at?->toIso8601String(),
            'scan_status' => $current?->scan_status,
            'scan_label' => $current?->scanLabel(),
            'preview_url' => null,
            'verified_by_name' => $document->verifier?->full_name,
            'verified_at' => $document->verified_at?->toIso8601String(),
            'rejection_reason' => $document->rejection_reason,
            'notes' => $document->notes,
            'versions' => $document->versions->map(fn (OriginationDocumentVersion $version) => [
                'id' => $version->id,
                'public_id' => $version->public_id,
                'version' => $version->version,
                'file_name' => $fileNames ? $version->file_name : null,
                'uploaded_at' => $version->uploaded_at?->toIso8601String(),
                'scan_label' => $version->scanLabel(),
                'current' => $version->id === $document->current_version_id,
            ])->values()->all(),
        ];
    }
}
