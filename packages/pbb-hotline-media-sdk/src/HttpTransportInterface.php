<?php

namespace Pbb\Hotline\Media;

interface HttpTransportInterface
{
    /**
     * @param  array<string, string>  $headers
     * @return array{status:int,headers:array<string, string>,body:string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
