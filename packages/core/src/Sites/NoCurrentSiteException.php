<?php

declare(strict_types=1);

namespace Basalto\Core\Sites;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Für diese Anfrage gibt es keine Site — im Backend, wenn ein Konto noch keiner Site angehört. Statt 500 eine
 * verständliche 403 mit dem Weg, wie man dazukommt.
 */
final class NoCurrentSiteException extends RuntimeException
{
    public function render(Request $request): SymfonyResponse
    {
        return app(ExceptionHandler::class)->render($request, new HttpException(403, __('Ihr Konto gehört noch zu keiner Site — bitten Sie die Verwaltung um eine Einladung.'), $this));
    }
}
