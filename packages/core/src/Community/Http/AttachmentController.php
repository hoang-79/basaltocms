<?php

declare(strict_types=1);

namespace Basalto\Core\Community\Http;

use Basalto\Core\Community\Attachments;
use Basalto\Core\Community\CommunityAttachment;
use Basalto\Core\Community\VisitorAccess;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anhänge (CR-006 FO-07): Hochladen aus der Editor-Insel (JSON), Entfernen vor dem Absenden, Ausliefern mit Leserecht.
 */
final class AttachmentController
{
    public function __construct(private readonly Attachments $attachments) {}

    public function store(Request $request): JsonResponse
    {
        $site = app(CurrentSite::class)->get();
        $customer = CustomerAuth::current();
        abort_if($site === null || $customer === null, 403);
        $lang = is_string($request->input('_sprache')) ? (string) $request->input('_sprache') : $site->default_locale;
        if (! app(VisitorAccess::class)->allows($site, $customer, Attachments::PERMISSION)) {
            return response()->json(['message' => __('Sie dürfen keine Dateien anhängen.', [], $lang)], 403);
        }
        $file = $request->file('datei');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return response()->json(['message' => __('Keine Datei erhalten.', [], $lang)], 422);
        }
        $problem = $this->attachments->problem($site, $customer, $file, $lang);
        if ($problem !== null) {
            return response()->json(['message' => $problem], 422);
        }
        try {
            $attachment = $this->attachments->store($site, $customer, $file);
        } catch (RuntimeException) {
            return response()->json(['message' => __('Das Bild liess sich nicht lesen.', [], $lang)], 422);
        }

        return response()->json($attachment->toClient(), 201);
    }

    /** Ein noch nicht verwendeter Anhang der Person verschwindet wieder. */
    public function destroy(int $attachment): JsonResponse
    {
        $customer = CustomerAuth::current();
        $model = $customer === null ? null : CommunityAttachment::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->where('subject_type', '')->find($attachment);
        abort_if($model === null, 404);
        $this->attachments->delete($model);

        return response()->json(['ok' => true]);
    }

    /** Ausliefern — die Site aus dem Host, das Recht vom Modul des Inhalts. */
    public function show(int $attachment, string $file): Response
    {
        $site = app(CurrentSite::class)->id();
        $model = $site === null ? null : CommunityAttachment::query()->withoutGlobalScopes()->where('site_id', $site)->find($attachment);
        abort_if($model === null || basename($model->path) !== $file || ! Storage::disk()->exists($model->path), 404);
        abort_unless($this->attachments->mayRead($model, CustomerAuth::current()), 404);
        $disposition = $model->isImage() ? 'inline' : 'attachment';

        return response((string) Storage::disk()->get($model->path), 200, [
            'Content-Type' => $model->mime,
            'Content-Disposition' => $disposition.'; filename="'.addcslashes($model->name, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
