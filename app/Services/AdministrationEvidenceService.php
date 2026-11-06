<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;
use Throwable;

class AdministrationEvidenceService
{
    public function canReceiveCustomerReturn(User $actor): bool
    {
        return $actor->administrationRoleCode() === 'staff' && $actor->can('orders.view') && $actor->can('returns.request');
    }

    /** @return array<string, list<string>> */
    public static function productPhotoRules(): array
    {
        return ['files' => ['required', 'array', 'min:1', 'max:5'],
            'files.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120']];
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return list<array{path: string, original_name: string, mime_type: string}>
     */
    public function prepareProductPhotos(array $files): array
    {
        Validator::make(['files' => $files], self::productPhotoRules())->validate();
        $images = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages(['files' => 'Upload product photos.']);
            }
            $images[] = $file;
        }

        return $this->prepareFiles($images, 'returns/product-photos', $this->returnDiskName());
    }

    /** @param list<array{path: string, original_name: string, mime_type: string}> $photos */
    public function attachProductPhotos(User $actor, int $returnId, array $photos): void
    {
        $this->authorize($actor, 'customer_return', $returnId, true);
        $subject = DB::table('customer_returns')->where('id', $returnId)->lockForUpdate()->firstOrFail();
        if (! $this->canUpload($actor, 'customer_return', $subject)) {
            throw ValidationException::withMessages(['files' => 'Product photos can only be added before the return decision.']);
        }
        $this->persist($actor, 'customer_return', $returnId, $photos);
    }

    /** @param list<array{path: string, original_name: string, mime_type: string}> $photos */
    public function attachCustomerPaymentProof(User $actor, int $paymentId, array $photos): void
    {
        $this->authorize($actor, 'refund_payment', $paymentId, true);
        $payment = DB::table('customer_refund_payments')->where('id', $paymentId)->lockForUpdate()->firstOrFail();
        abort_unless($actor->can('returns.approve') && $payment->submission_key !== null, 403);
        $decision = DB::table('customer_refund_payment_reviews')->where('customer_refund_payment_id', $paymentId)->orderByDesc('id')->value('decision');
        if (! $this->canUpload($actor, 'refund_payment', $payment, $decision)) {
            throw ValidationException::withMessages(['proof' => 'This payment proof is finalized.']);
        }
        $this->persist($actor, 'refund_payment', $paymentId, $photos, true);
    }

    public static function diskNameForPath(string $path): string
    {
        return str_starts_with($path, 'returns/') ? self::returnDiskName() : 'local';
    }

    /** @param list<array{path: string, original_name: string, mime_type: string}> $files */
    public function discardPreparedFiles(array $files): void
    {
        foreach (collect($files)->groupBy(fn (array $file): string => self::diskNameForPath($file['path'])) as $diskName => $diskFiles) {
            Storage::disk($diskName)->delete(array_column($diskFiles->all(), 'path'));
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function store(User $actor, string $subjectType, int $subjectId, array $files): void
    {
        $this->authorize($actor, $subjectType, $subjectId, true);
        $prepared = match ($subjectType) {
            'customer_return' => $this->prepareProductPhotos($files),
            'refund_payment' => $this->prepareFiles($files, 'returns/payment-proofs', $this->returnDiskName()),
            default => $this->prepareFiles($files),
        };

        try {
            DB::transaction(function () use ($actor, $subjectType, $subjectId, $prepared): void {
                $subject = DB::table($this->table($subjectType))->where('id', $subjectId)->lockForUpdate()->firstOrFail();
                $this->authorize($actor, $subjectType, $subjectId, true);
                $decision = $subjectType === 'refund_payment'
                    ? DB::table('customer_refund_payment_reviews')->where('customer_refund_payment_id', $subjectId)->orderByDesc('id')->value('decision') : null;
                if (! $this->canUpload($actor, $subjectType, $subject, $decision)) {
                    throw ValidationException::withMessages(['files' => 'Evidence uploads are closed for this finalized return or payment review.']);
                }
                $this->persist($actor, $subjectType, $subjectId, $prepared);
            });
        } catch (Throwable $exception) {
            $this->discardPreparedFiles($prepared);
            throw $exception;
        }
    }

    public function canUpload(User $actor, string $subjectType, stdClass $subject, ?string $paymentDecision = null): bool
    {
        return match ($subjectType) {
            'customer_return' => $this->canReceiveCustomerReturn($actor) && $subject->status === 'pending',
            'refund_payment' => $actor->can('returns.record_payment') && (int) $subject->recorded_by === $actor->id
                && ! in_array($paymentDecision, ['verified', 'voided'], true),
            'damage_report' => $actor->can('returns.request'),
            default => false,
        };
    }

    public function authorize(User $actor, string $subjectType, int $subjectId, bool $upload): void
    {
        $permission = match ($subjectType) {
            'damage_report' => $upload ? 'returns.request' : 'inventory.view',
            'customer_return' => $upload ? 'returns.request' : 'orders.view',
            'refund_payment' => $upload ? 'returns.record_payment' : 'orders.view',
            default => throw new AuthorizationException,
        };
        if (! $actor->hasAdministrationPermission($permission)) {
            throw new AuthorizationException;
        }
        if ($upload && $subjectType === 'customer_return' && ! $this->canReceiveCustomerReturn($actor)) {
            throw new AuthorizationException;
        }
        if ($upload && $subjectType === 'refund_payment'
            && ! DB::table('customer_refund_payments')->where('id', $subjectId)->where('recorded_by', $actor->id)->exists()) {
            throw new AuthorizationException;
        }
        if (! DB::table($this->table($subjectType))->where('id', $subjectId)->exists()) {
            abort(404);
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array{path: string, original_name: string, mime_type: string}>
     */
    private function prepareFiles(array $files, string $directory = 'administration/evidence', string $diskName = 'local'): array
    {
        $prepared = [];
        try {
            foreach ($files as $file) {
                $path = $file->storeAs($directory, Str::uuid().'.'.$file->extension(), $diskName);
                if (! is_string($path)) {
                    throw new RuntimeException('Evidence storage failed.');
                }
                $prepared[] = ['path' => $path, 'original_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream'];
            }
        } catch (Throwable $exception) {
            $this->discardPreparedFiles($prepared);
            throw $exception;
        }

        return $prepared;
    }

    /** @param list<array{path: string, original_name: string, mime_type: string}> $prepared */
    private function persist(User $actor, string $subjectType, int $subjectId, array $prepared, bool $customerVisible = false): void
    {
        $count = DB::table('administration_evidence')->where('subject_type', $subjectType)->where('subject_id', $subjectId)->count();
        if ($count + count($prepared) > 5) {
            throw ValidationException::withMessages(['files' => 'A case can have at most five evidence files.']);
        }
        foreach ($prepared as $file) {
            if (! Storage::disk(self::diskNameForPath($file['path']))->exists($file['path'])) {
                throw ValidationException::withMessages(['files' => 'An evidence file could not be stored. Upload it again.']);
            }
            DB::table('administration_evidence')->insert([...$file, 'subject_type' => $subjectType,
                'subject_id' => $subjectId, 'uploaded_by' => $actor->id, 'customer_visible' => $customerVisible, 'created_at' => now()]);
        }
        DB::table('audit_logs')->insert(['actor_id' => $actor->id, 'action' => 'administration.evidence.added',
            'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'changes' => json_encode(['count' => count($prepared)], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function table(string $subjectType): string
    {
        return match ($subjectType) {
            'damage_report' => 'damage_reports',
            'customer_return' => 'customer_returns',
            'refund_payment' => 'customer_refund_payments',
            default => throw new AuthorizationException,
        };
    }

    private static function returnDiskName(): string
    {
        return config('filesystems.disks.battlefront_returns.driver') === 'local' ? 'local' : 'battlefront_returns';
    }
}
