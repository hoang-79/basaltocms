<?php

declare(strict_types=1);

namespace Basalto\Core\Invoices;

use Dompdf\Dompdf;
use Dompdf\Options;

/** HTML zu PDF (Dompdf, A4) — ohne Netz, ohne Datei auf dem Server; Rechnungen entstehen bei Bedarf aus ihren Daten. */
final class PdfRenderer
{
    public function render(string $html, ?string $chroot = null): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot([$chroot ?? dirname(__DIR__, 2)]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
