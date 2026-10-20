<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * The web app calls this API from its own server (its BFF), passing the visitor's IP in
 * X-Forwarded-For. That header is honoured only when the connection comes from this machine
 * (loopback or the server's own address) or a proxy listed in TRUSTED_PROXIES; from anyone else
 * it is ignored, so visitors cannot pick their own rate-limit bucket. Without this every request
 * through the web app looked like one IP, and ten failed sign-ins locked everyone out.
 */
class TrustProxies extends Middleware
{
    /** Client IP and scheme only; Host / Port headers are never taken from a proxy. */
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

    protected function proxies()
    {
        return array_values(array_unique(array_filter([
            '127.0.0.1',
            '::1',
            request()->server('SERVER_ADDR'),
            ...config('provoke.trusted_proxies', []),
        ])));
    }
}
