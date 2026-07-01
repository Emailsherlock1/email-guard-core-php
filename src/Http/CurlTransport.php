<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard\Http;

/**
 * Default transport. Requires ext-curl; pass your own TransportInterface
 * (for example Psr18Transport) if curl is not available.
 */
final class CurlTransport implements TransportInterface
{
    public function post(string $url, array $headers, string $body, int $timeoutMs): TransportResponse
    {
        if (!function_exists('curl_init')) {
            throw new TransportException('ext-curl is not available; inject a custom transport');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            curl_close($handle);
            // curl error 28 (CURLE_OPERATION_TIMEOUTED) covers both the total
            // and the connect timeout budget; everything else (DNS, TLS,
            // refused connection) is an unreachable endpoint.
            $kind = $errno === CURLE_OPERATION_TIMEOUTED
                ? TransportException::KIND_TIMEOUT
                : TransportException::KIND_CONNECTION;

            throw new TransportException('Verify API request failed: ' . $error, $kind);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new TransportResponse($status, (string) $responseBody);
    }
}
