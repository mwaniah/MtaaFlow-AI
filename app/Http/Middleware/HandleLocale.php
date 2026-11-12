<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class HandleLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale ?? $request->cookie('locale') ?? $request->getPreferredLanguage(['en', 'fr', 'de', 'es']) ?? 'en';
        app()->setLocale(in_array($locale, ['en', 'fr', 'de', 'es'], true) ? $locale : 'en');
        Carbon::setLocale(app()->getLocale());

        $response = $next($request);
        $user = $request->user();
        if ($user !== null && $user->locale === null && ($response->isSuccessful() || $response->isRedirection())) {
            $user->update(['locale' => app()->getLocale()]);
        }

        return $response;
    }
}
