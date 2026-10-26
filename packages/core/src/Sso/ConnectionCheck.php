<?php

declare(strict_types=1);

namespace Basalto\Core\Sso;

use Basalto\Core\Models\SsoConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * TB 13 Scheibe 4 (M-25): «Verbindung testen» — ein Hinweis, keine Garantie. Geprüft wird, was ohne Benutzer geht:
 *
 * - **Entra:** Token für die App selbst (Client Credentials) am Token-Endpunkt des Tenants. Klappt das, passen Tenant,
 *   Client-ID und Secret; die Fehlercodes von Microsoft (AADSTS…) werden in einen Hinweis übersetzt.
 * - **Google:** Ein Code, den es nicht gibt, am Token-Endpunkt. Google prüft zuerst die App: `invalid_client` heisst
 *   Client-ID oder Secret falsch, `invalid_grant` heisst die App ist bekannt (nur der Code nicht — wie erwartet).
 *
 * Ob die Redirect-URL eingetragen ist und Benutzer zustimmen dürfen, zeigt erst eine echte Anmeldung.
 */
final class ConnectionCheck
{
    /** @return array{ok: bool, message: string} */
    public function run(SsoConnection $connection): array
    {
        try {
            return match ($connection->providerEnum()) {
                SsoProvider::Entra => $this->entra($connection),
                SsoProvider::Google => $this->google($connection),
            };
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => __(':provider war nicht erreichbar. Prüfen Sie die Verbindung des Servers nach aussen.', ['provider' => $connection->providerEnum()->shortName()])];
        }
    }

    /** @return array{ok: bool, message: string} */
    private function entra(SsoConnection $connection): array
    {
        $response = Http::asForm()->acceptJson()->timeout(10)->post('https://login.microsoftonline.com/'.rawurlencode((string) $connection->tenant_id).'/oauth2/v2.0/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $connection->client_id,
            'client_secret' => $connection->client_secret,
            'scope' => 'https://graph.microsoft.com/.default',
        ]);
        if ($response->successful() && is_string($response->json('access_token'))) {
            return ['ok' => true, 'message' => __('Microsoft kennt Tenant, Client-ID und Secret. Prüfen Sie zum Schluss die Anmeldung einmal in einem privaten Fenster.')];
        }
        $codes = $this->entraCodes($response);

        return ['ok' => false, 'message' => match (true) {
            in_array(90002, $codes, true), in_array(900023, $codes, true) => __('Microsoft kennt diesen Tenant nicht. Prüfen Sie die Verzeichnis-ID (Tenant-ID).'),
            in_array(700016, $codes, true) => __('Die Client-ID ist in diesem Tenant nicht registriert. Prüfen Sie Client-ID und Tenant-ID.'),
            in_array(7000215, $codes, true) => __('Das Client-Secret ist falsch. Tragen Sie den Wert des Secrets ein, nicht seine ID.'),
            in_array(7000222, $codes, true) => __('Das Client-Secret ist abgelaufen. Erzeugen Sie in Entra ein neues und tragen Sie es hier ein.'),
            default => __('Microsoft hat die Anfrage abgewiesen (:code). Prüfen Sie die Angaben der App-Registrierung.', ['code' => $codes === [] ? (string) $response->status() : 'AADSTS'.$codes[0]]),
        }];
    }

    /** @return list<int> */
    private function entraCodes(Response $response): array
    {
        $codes = $response->json('error_codes');

        return is_array($codes) ? array_values(array_filter(array_map(static fn (mixed $code): int => is_numeric($code) ? (int) $code : 0, $codes))) : [];
    }

    /** @return array{ok: bool, message: string} */
    private function google(SsoConnection $connection): array
    {
        $response = Http::asForm()->acceptJson()->timeout(10)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'authorization_code',
            'code' => 'basalto-verbindungstest',
            'client_id' => $connection->client_id,
            'client_secret' => $connection->client_secret,
            'redirect_uri' => SsoProviders::callbackUrl(SsoProvider::Google),
        ]);
        $error = $response->json('error');

        return match ($error) {
            'invalid_grant' => ['ok' => true, 'message' => __('Google kennt Client-ID und Secret. Prüfen Sie zum Schluss die Anmeldung einmal in einem privaten Fenster.')],
            'invalid_client', 'unauthorized_client' => ['ok' => false, 'message' => __('Google kennt diese Client-ID nicht oder das Secret ist falsch.')],
            default => ['ok' => false, 'message' => __('Google hat unerwartet geantwortet (:code). Prüfen Sie die Angaben der App.', ['code' => is_string($error) ? $error : (string) $response->status()])],
        };
    }
}
