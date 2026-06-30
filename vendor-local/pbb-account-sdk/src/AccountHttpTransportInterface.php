<?php

namespace Pbb\AccountSdk;

interface AccountHttpTransportInterface
{
    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): array;
}
