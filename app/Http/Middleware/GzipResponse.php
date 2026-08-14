<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GzipResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Do not compress binary files or streamed responses
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return $response;
        }

        // Check if PHP gzencode function exists and client supports gzip
        if (!function_exists('gzencode') || !$request->headers->has('Accept-Encoding')) {
            return $response;
        }

        $acceptEncoding = $request->header('Accept-Encoding');
        if (!str_contains(strtolower($acceptEncoding), 'gzip')) {
            return $response;
        }

        // Avoid double compression
        if ($response->headers->has('Content-Encoding')) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false || strlen($content) < 256) {
            return $response;
        }

        $compressed = gzencode($content, 6);
        if ($compressed !== false) {
            $response->setContent($compressed);
            $response->headers->set('Content-Encoding', 'gzip');
            $response->headers->set('Content-Length', (string) strlen($compressed));
        }

        return $response;
    }
}
