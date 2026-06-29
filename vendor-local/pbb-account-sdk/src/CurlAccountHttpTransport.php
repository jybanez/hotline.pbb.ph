<?php

namespace Pbb\AccountSdk;

class CurlAccountHttpTransport implements AccountHttpTransportInterface
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): array
    {
        if (! function_exists('curl_init')) {
            throw new AccountUnavailableException('The PHP cURL extension is required by the PBB Account SDK.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new AccountUnavailableException('Unable to initialize Account HTTP request.');
        }

        $responseHeaders = [];
        $timeoutSeconds = (int) ($options['timeout_seconds'] ?? 10);

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        if (! empty($options['ca_bundle'])) {
            curl_setopt($handle, CURLOPT_CAINFO, (string) $options['ca_bundle']);
        }

        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($responseBody === false) {
            $message = curl_error($handle) ?: 'Account HTTP request failed.';
            curl_close($handle);

            throw new AccountUnavailableException($message);
        }

        curl_close($handle);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => (string) $responseBody,
        ];
    }

    private function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = $name.': '.$value;
        }

        return $formatted;
    }
}
