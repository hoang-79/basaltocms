<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteRole;
use Basalto\Core\Transfer\SiteExporter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «Export herunterladen» in der Site-Verwaltung (M-18, «Ihre Daten bleiben Ihre»): Vollexport als ZIP, ohne
 * Formulareingänge — die gibt es nur über `basalto:export --with-submissions` (Personendaten, N-05). Nur für
 * Betreiber der Site und Besitzer/Verwaltung ihres Kontos.
 */
final class SiteExportController
{
    public function __invoke(Request $request, Site $site, SiteExporter $exporter, Recorder $recorder): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user !== null && self::allows($user, $site), 403);
        $path = sys_get_temp_dir().'/basalto-export-'.Str::random(16).'.zip';
        $result = $exporter->export($site, $path);
        $recorder->record('site.exported', $site, null, ['file' => 'download', 'size' => $result['size'], 'with_submissions' => false], $site, $user);

        return response()->download($path, sprintf('basalto-site-%s-%s.zip', $site->handle, now()->format('Ymd-His')), ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** Exportieren darf, wer die Site betreibt oder ihr Konto führt. */
    public static function allows(Authenticatable $user, Site $site): bool
    {
        $role = $site->account?->roleOf($user);

        return $site->roleOf($user) === SiteRole::Owner || $role === AccountRole::Owner || $role === AccountRole::Admin;
    }
}
