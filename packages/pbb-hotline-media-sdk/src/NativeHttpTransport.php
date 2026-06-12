<?php

namespace Pbb\Hotline\Media;

final class NativeHttpTransport implements HttpTransportInterface
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'ignore_errors' => true,
            ],
        ]);

        $contents = file_get_contents($url, false, $context);
        $status = 0;
        $responseHeaders = [];

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $matches) === 1) {
                $status = (int) $matches[1];

                continue;
            }

            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => is_string($contents) ? $contents : '',
        ];
    }
}
