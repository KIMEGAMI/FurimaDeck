<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_html_responses_include_baseline_security_headers(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()')
            ->assertHeader('Cross-Origin-Embedder-Policy', 'require-corp')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');

        $cacheDirectives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        $this->assertEqualsCanonicalizing(['no-store', 'private'], $cacheDirectives);
    }

    public function test_html_error_responses_keep_security_headers(): void
    {
        Route::post('/__security-regression/method-not-allowed', static fn () => response()->noContent());
        Route::post('/__security-regression/csrf-error', static fn () => abort(419));

        $responses = [
            $this->get('/__security-regression/not-found')->assertNotFound(),
            $this->get('/__security-regression/method-not-allowed')->assertMethodNotAllowed(),
            $this->post('/__security-regression/csrf-error')->assertStatus(419),
        ];

        foreach ($responses as $response) {
            $response->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()')
                ->assertHeader('Cross-Origin-Embedder-Policy', 'require-corp')
                ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
                ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');

            $this->assertNotEmpty(
                $response->headers->get('Content-Security-Policy'),
                'HTML error responses must carry an enforcing Content-Security-Policy.'
            );

            $cacheDirectives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
            $this->assertEqualsCanonicalizing(['no-store', 'private'], $cacheDirectives);
        }
    }

    public function test_redirect_responses_do_not_return_an_html_body(): void
    {
        Route::middleware('web')->get('/__security-regression/redirect', static fn () => redirect('/login'));

        $response = $this->get('/__security-regression/redirect')
            ->assertRedirect('/login');

        $this->assertSame('', $response->getContent());
    }

    public function test_public_homepage_asset_cache_tokens_do_not_disclose_unix_timestamps(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('images/furimadeck-hero.png?v=', false);

        $this->assertDoesNotMatchRegularExpression(
            '/[?&]v=\\d{10,}\\b/',
            (string) $response->getContent()
        );

        $this->assertMatchesRegularExpression(
            '/images\\/furimadeck-hero\\.png\\?v=[a-f0-9]{64}/',
            (string) $response->getContent()
        );
    }

    public function test_apache_static_responses_have_security_header_rules(): void
    {
        $rules = File::get(public_path('.htaccess'));

        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=()',
            'Cross-Origin-Embedder-Policy' => 'require-corp',
            'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ] as $header => $value) {
            $this->assertMatchesRegularExpression(
                '/Header\\s+always\\s+set\\s+'.preg_quote($header, '/').'\\s+"'.preg_quote($value, '/').'"/i',
                $rules,
                "Apache must set {$header} for static responses."
            );
        }

        $this->assertMatchesRegularExpression('/Header\\s+onsuccess\\s+unset\\s+X-Powered-By/i', $rules);
        $this->assertMatchesRegularExpression('/Header\\s+always\\s+unset\\s+X-Powered-By/i', $rules);
    }

    public function test_hsts_is_added_only_for_requests_that_are_actually_secure(): void
    {
        $middleware = new SecurityHeaders;
        $next = static fn (Request $request): Response => new Response;

        $httpResponse = $middleware->handle(Request::create('http://furimadeck.test/login'), $next);
        $this->assertNull($httpResponse->headers->get('Strict-Transport-Security'));

        $httpsResponse = $middleware->handle(Request::create('https://furimadeck.test/login'), $next);
        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $httpsResponse->headers->get('Strict-Transport-Security')
        );
    }

    public function test_csrf_middleware_rejects_state_changes_without_a_matching_token(): void
    {
        $session = $this->app['session.store'];
        $session->start();
        $middleware = new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
{
            protected function runningUnitTests()
            {
                return false;
            }
        };

        foreach ([null, 'wrong-token'] as $token) {
            $request = Request::create('/profile', 'DELETE');
            $request->setLaravelSession($session);
            if (is_string($token)) {
                $request->headers->set('X-CSRF-TOKEN', $token);
            }

            try {
                $middleware->handle($request, static fn () => new Response('unexpected'));
                $this->fail('A state-changing request without the session token must be rejected.');
            } catch (TokenMismatchException) {
                $this->assertTrue(true);
            }
        }

        $request = Request::create('/profile', 'DELETE');
        $request->setLaravelSession($session);
        $request->headers->set('X-CSRF-TOKEN', $session->token());

        $response = $middleware->handle($request, static fn () => new Response('accepted'));

        $this->assertSame('accepted', $response->getContent());
    }

    public function test_xsrf_cookie_is_http_only(): void
    {
        $response = $this->get(route('login'));
        $xsrfCookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');

        $this->assertNotNull($xsrfCookie, 'The CSRF middleware must issue its XSRF token cookie.');
        $this->assertTrue($xsrfCookie->isHttpOnly(), 'The XSRF token must not be readable by JavaScript.');
    }

    public function test_session_cookie_has_http_only_and_configured_transport_attributes(): void
    {
        $response = $this->get(route('login'));
        $sessionCookieName = (string) config('session.cookie');
        $sessionCookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === $sessionCookieName);

        $this->assertNotNull($sessionCookie, 'The response must set the configured session cookie.');
        $this->assertTrue($sessionCookie->isHttpOnly());
        $this->assertSame((bool) config('session.secure'), $sessionCookie->isSecure());
        $this->assertSame(
            strtolower((string) config('session.same_site')),
            strtolower((string) $sessionCookie->getSameSite())
        );
    }

    public function test_html_responses_have_a_restrictive_enforcing_csp(): void
    {
        $response = $this->get(route('login'));
        $response->assertOk();

        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertNotEmpty($policy, 'An enforcing Content-Security-Policy header is required.');

        $segments = collect(explode(';', (string) $policy))
            ->map(fn (string $directive): array => preg_split('/\s+/', trim($directive)) ?: [])
            ->filter(fn (array $parts): bool => $parts !== [])
            ->values();

        $names = $segments->map(fn (array $parts): string => strtolower($parts[0]));
        $this->assertCount(
            $names->unique()->count(),
            $names,
            'CSP directives must not be duplicated because browsers ignore later duplicates.'
        );

        $directives = $segments->mapWithKeys(
            fn (array $parts): array => [strtolower(array_shift($parts)) => array_map('strtolower', $parts)]
        );

        $this->assertContains("'self'", $directives->get('default-src', []), 'default-src must be restricted to this origin.');
        $this->assertContains("'self'", $directives->get('script-src', []), 'script-src must allow the application origin.');
        $nonceSources = collect($directives->get('script-src', []))
            ->filter(fn (string $source): bool => preg_match("/^'nonce-[A-Za-z0-9+\/=]+'$/", $source) === 1);
        $this->assertCount(1, $nonceSources, 'script-src must allow exactly one request-specific nonce.');
        $this->assertContains("'none'", $directives->get('object-src', []), 'object-src must disable plugin content.');
        $this->assertTrue(
            in_array("'self'", $directives->get('base-uri', []), true)
                || in_array("'none'", $directives->get('base-uri', []), true),
            'base-uri must prevent untrusted base URL injection.'
        );
        $this->assertSame(["'none'"], $directives->get('frame-ancestors', []));
        $this->assertContains("'self'", $directives->get('form-action', []), 'form-action must be restricted to this origin.');
        $this->assertSame(["'none'"], $directives->get('script-src-attr', []));
        $this->assertSame(["'none'"], $directives->get('style-src-attr', []));
        $this->assertContains("'self'", $directives->get('style-src', []));
        $this->assertSame(
            $nonceSources->first(),
            collect($directives->get('style-src', []))->first(fn (string $source): bool => preg_match("/^'nonce-[A-Za-z0-9+\\/=]+'$/", $source) === 1),
            'style-src must use the same request-specific nonce as script-src.'
        );
        $this->assertSame(["'self'"], $directives->get('connect-src', []), 'connect-src must not permit third-party endpoints.');
        $this->assertSame(["'self'", 'data:', 'blob:'], $directives->get('img-src', []), 'Images must not load from unreviewed external origins.');
        $this->assertSame(["'self'", 'data:'], $directives->get('font-src', []), 'Fonts must not load from unreviewed external origins.');
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'), 'A report-only policy does not enforce the security policy.');

        foreach ($directives as $name => $sources) {
            foreach (["'unsafe-inline'", "'unsafe-eval'"] as $unsafeSource) {
                $this->assertNotContains($unsafeSource, $sources, "{$name} must not contain {$unsafeSource}.");
            }
        }
    }

    public function test_each_inline_script_on_public_html_pages_carries_the_matching_nonce(): void
    {
        foreach (['/login', '/'] as $uri) {
            $response = $this->get($uri);
            $response->assertOk();
            $nonce = $this->nonceFromPolicy((string) $response->headers->get('Content-Security-Policy'));

            preg_match_all('/<script\\b([^>]*)>/i', (string) $response->getContent(), $matches);
            foreach ($matches[1] as $attributes) {
                $nonceValues = $this->htmlAttributeValues($attributes, 'nonce');
                $this->assertSame(1, $this->htmlAttributeCount($attributes, 'nonce'), "A script on {$uri} must have exactly one nonce attribute.");
                $this->assertCount(1, $nonceValues, "A script nonce on {$uri} must be quoted.");
                $this->assertSame($nonce, $nonceValues[0], "A script on {$uri} must carry the response CSP nonce.");
            }

            preg_match_all('/<style\\b([^>]*)>/i', (string) $response->getContent(), $styleMatches);
            foreach ($styleMatches[1] as $attributes) {
                $nonceValues = $this->htmlAttributeValues($attributes, 'nonce');
                $this->assertSame(1, $this->htmlAttributeCount($attributes, 'nonce'), "An inline style on {$uri} must have exactly one nonce attribute.");
                $this->assertCount(1, $nonceValues, "An inline style nonce on {$uri} must be quoted.");
                $this->assertSame($nonce, $nonceValues[0], "An inline style block on {$uri} must carry the response CSP nonce.");
            }
        }
    }

    public function test_csp_nonce_changes_between_html_responses(): void
    {
        $first = $this->get('/login');
        $second = $this->get('/login');

        $this->assertNotSame(
            $this->nonceFromPolicy((string) $first->headers->get('Content-Security-Policy')),
            $this->nonceFromPolicy((string) $second->headers->get('Content-Security-Policy'))
        );
    }

    public function test_csp_is_not_added_to_non_html_responses(): void
    {
        $middleware = new SecurityHeaders;
        $next = static fn (Request $request): Response => new Response('{}', 200, ['Content-Type' => 'application/json']);

        $response = $middleware->handle(Request::create('https://furimadeck.test/api/status'), $next);

        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_script_source_guard_rejects_external_and_ambiguous_urls(): void
    {
        $cases = [
            '/build/assets/app.js' => true,
            "{{ asset('js/app.js') }}" => true,
            "{{ Vite::asset('resources/js/app.js') }}" => false,
            '//attacker.example/payload.js' => false,
            "{{ asset('https://attacker.example/payload.js') }}" => false,
            "{{ asset('//attacker.example/payload.js') }}" => false,
            "{{ Vite::asset('javascript:alert(1)') }}" => false,
            'data:text/javascript,alert(1)' => false,
            ' https://attacker.example/payload.js' => false,
            '/\\attacker.example/payload.js' => false,
            '' => false,
        ];

        foreach ($cases as $source => $expected) {
            $this->assertSame($expected, $this->isSameOriginScriptSource($source), "Unexpected script source classification: {$source}");
        }
    }

    public function test_json_ld_data_cannot_close_its_script_element(): void
    {
        $payload = '</script><script>alert(document.domain)</script>';
        $serialized = json_encode(
            ['description' => $payload],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('</script>', $serialized);
        $this->assertStringContainsString('\u003C/script\u003E', $serialized);

        foreach ([
            'components/legal-layout.blade.php',
            'components/marketing-layout.blade.php',
            'welcome.blade.php',
        ] as $viewPath) {
            $this->assertStringContainsString(
                'JSON_HEX_TAG',
                File::get(resource_path('views/'.$viewPath)),
                "JSON-LD in {$viewPath} must escape HTML tag delimiters."
            );
        }
    }

    public function test_asset_helper_source_is_checked_against_the_effective_asset_origin(): void
    {
        $urlGenerator = app('url');
        $originalAssetUrl = config('app.asset_url');

        try {
            $urlGenerator->useAssetOrigin(null);
            $this->assertTrue($this->isSameOriginScriptSource("{{ asset('js/app.js') }}"));

            $urlGenerator->useAssetOrigin('https://attacker.example');
            $this->assertFalse($this->isSameOriginScriptSource("{{ asset('js/app.js') }}"));
        } finally {
            $urlGenerator->useAssetOrigin($originalAssetUrl);
        }
    }

    public function test_security_attributes_require_exact_html_attribute_names_and_quoted_values(): void
    {
        $spoofedAttributes = ' data-src="//attacker.example/app.js" data-nonce="{{ \\Illuminate\\Support\\Facades\\Vite::cspNonce() }}" data-integrity="sha384-fake" data-crossorigin="anonymous" data-x=" nonce=\'{{ \\Illuminate\\Support\\Facades\\Vite::cspNonce() }}\' src=\'/build/assets/app.js\' integrity=\'sha384-fake\' crossorigin=\'anonymous\'"';

        foreach (['src', 'nonce', 'integrity', 'crossorigin'] as $name) {
            $this->assertSame(0, $this->htmlAttributeCount($spoofedAttributes, $name), "data-{$name} must not count as the {$name} security attribute.");
            $this->assertSame([], $this->htmlAttributeValues($spoofedAttributes, $name));
        }

        $this->assertSame(1, $this->htmlAttributeCount(' src=//attacker.example/app.js', 'src'));
        $this->assertSame([], $this->htmlAttributeValues(' src=//attacker.example/app.js', 'src'), 'Unquoted src values must not be accepted.');
        $this->assertSame([''], $this->htmlAttributeValues(' src=""', 'src'), 'An empty quoted src must remain distinguishable from an absent src.');
        $this->assertSame(2, $this->htmlAttributeCount(' src="/local.js" src="//attacker.example/app.js"', 'src'));
    }

    public function test_opening_tag_scanner_does_not_stop_at_greater_than_inside_a_quoted_attribute(): void
    {
        $markup = '<script data-note=">" src="https://attacker.example/payload.js"></script>';
        $tags = $this->openingTagAttributes($markup);

        $this->assertCount(1, $tags);
        $this->assertSame('script', $tags[0]['name']);
        $this->assertSame(1, $this->htmlAttributeCount($tags[0]['attributes'], 'src'));
        $sourceValues = $this->htmlAttributeValues($tags[0]['attributes'], 'src');
        $this->assertSame(['https://attacker.example/payload.js'], $sourceValues);

        $nonceValues = $this->htmlAttributeValues($tags[0]['attributes'], 'nonce');
        $hasNonce = $this->htmlAttributeCount($tags[0]['attributes'], 'nonce') === 1
            && count($nonceValues) === 1
            && preg_match('/^\\s*\\{\\{\\s*\\\\?Illuminate\\\\Support\\\\Facades\\\\Vite::cspNonce\\(\\)\\s*\\}\\}\\s*$/i', $nonceValues[0]) === 1;
        $hasSameOriginSource = $this->isSameOriginScriptSource($sourceValues[0]);
        $this->assertFalse($hasNonce || $hasSameOriginSource, 'The injected external script must fail the existing script admission rule.');
    }

    public function test_opening_tag_scanner_continues_after_comments_declarations_and_closing_tags(): void
    {
        $markup = '<!doctype html><!-- template preamble --><html><body></body></html><script src="https://attacker.example/payload.js"></script>';
        $tags = $this->openingTagAttributes($markup);

        $this->assertSame(
            ['html', 'body', 'script'],
            array_column($tags, 'name'),
            'The scanner must continue past non-opening markup instead of silently skipping the rest of the document.'
        );
        $this->assertSame(
            ['https://attacker.example/payload.js'],
            $this->htmlAttributeValues($tags[2]['attributes'], 'src')
        );
    }

    public function test_application_views_do_not_add_inline_handlers_styles_or_unverified_scripts(): void
    {
        $approvedExternalScripts = [
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js' => 'sha384-vsrfeLOOY6KuIYKDlmVH5UiBmgIdB1oEf7p01YgWHuqmOHfZr374+odEv96n9tNC',
        ];

        foreach (File::allFiles(resource_path('views')) as $view) {
            $relativePath = str_replace('\\', '/', $view->getRelativePathname());
            if (str_starts_with($relativePath, 'emails/') || $relativePath === 'pwa/service-worker.blade.php') {
                continue;
            }

            $source = File::get($view->getPathname());
            $this->assertDoesNotMatchRegularExpression('/\\bx-(?:data|show|init|on|bind|transition|cloak)\\b/i', $source, "Alpine directive found in {$relativePath}; the standard Alpine build is incompatible with the strict CSP.");

            $openingTags = $this->openingTagAttributes($source);
            foreach ($openingTags as $tag) {
                $attributeNames = array_keys($this->parseHtmlAttributes($tag['attributes']));
                foreach ($attributeNames as $attributeName) {
                    $this->assertDoesNotMatchRegularExpression('/^on[a-z]+$/i', $attributeName, "Inline event handler found in {$relativePath}.");
                    $this->assertNotSame('style', strtolower($attributeName), "Inline style attribute found in {$relativePath}.");
                }
            }

            foreach ($openingTags as $tag) {
                if ($tag['name'] !== 'script') {
                    continue;
                }

                $attributes = $tag['attributes'];
                $nonceAttributeCount = $this->htmlAttributeCount($attributes, 'nonce');
                $nonceValues = $this->htmlAttributeValues($attributes, 'nonce');
                $this->assertLessThanOrEqual(1, $nonceAttributeCount, "Script in {$relativePath} must not repeat its nonce attribute.");
                $this->assertCount($nonceAttributeCount, $nonceValues, "Script nonce in {$relativePath} must be quoted.");
                $hasNonce = $nonceAttributeCount === 1
                    && preg_match('/^\\s*\\{\\{\\s*\\\\?Illuminate\\\\Support\\\\Facades\\\\Vite::cspNonce\\(\\)\\s*\\}\\}\\s*$/i', $nonceValues[0]) === 1;

                $sourceAttributeCount = $this->htmlAttributeCount($attributes, 'src');
                $sourceValues = $this->htmlAttributeValues($attributes, 'src');
                $this->assertLessThanOrEqual(1, $sourceAttributeCount, "Script in {$relativePath} must not repeat its src attribute.");
                $this->assertCount($sourceAttributeCount, $sourceValues, "Script src in {$relativePath} must be quoted.");
                $hasSource = $sourceAttributeCount === 1;
                $source = $hasSource ? $sourceValues[0] : '';
                if ($hasSource) {
                    $this->assertNotSame('', trim($source), "Script src in {$relativePath} must not be empty.");
                }
                $this->assertTrue($hasNonce || $hasSource, "Script tag in {$relativePath} must be nonced or bundled from the same origin.");

                if ($hasSource && preg_match('/^https?:\\/\\//i', $source) === 1 && ! $this->hasSameOrigin($source)) {
                    $this->assertTrue($hasNonce, "External script in {$relativePath} must carry a request nonce.");
                    $this->assertArrayHasKey($source, $approvedExternalScripts, "Unapproved third-party script in {$relativePath}.");

                    $integrityValues = $this->htmlAttributeValues($attributes, 'integrity');
                    $this->assertSame(1, $this->htmlAttributeCount($attributes, 'integrity'), "External script in {$relativePath} must have exactly one integrity attribute.");
                    $this->assertCount(1, $integrityValues, "External script integrity in {$relativePath} must be quoted.");
                    $this->assertSame($approvedExternalScripts[$source], $integrityValues[0], "External script in {$relativePath} must use the reviewed exact SRI hash.");

                    $crossoriginValues = $this->htmlAttributeValues($attributes, 'crossorigin');
                    $this->assertSame(1, $this->htmlAttributeCount($attributes, 'crossorigin'), "External script in {$relativePath} must have exactly one crossorigin attribute.");
                    $this->assertCount(1, $crossoriginValues, "External script crossorigin in {$relativePath} must be quoted.");
                    $this->assertSame('anonymous', strtolower($crossoriginValues[0]), "External script in {$relativePath} must use anonymous CORS for SRI.");
                } elseif ($hasSource) {
                    $this->assertTrue($this->isSameOriginScriptSource($source), "Script source in {$relativePath} must be an unambiguous same-origin URL.");
                }
            }

            foreach ($openingTags as $tag) {
                if ($tag['name'] !== 'style') {
                    continue;
                }

                $attributes = $tag['attributes'];
                $nonceValues = $this->htmlAttributeValues($attributes, 'nonce');
                $this->assertSame(1, $this->htmlAttributeCount($attributes, 'nonce'), "Inline style block in {$relativePath} must have exactly one nonce attribute.");
                $this->assertCount(1, $nonceValues, "Inline style nonce in {$relativePath} must be quoted.");
                $this->assertMatchesRegularExpression('/^\\s*\\{\\{\\s*\\\\?Illuminate\\\\Support\\\\Facades\\\\Vite::cspNonce\\(\\)\\s*\\}\\}\\s*$/i', $nonceValues[0], "Inline style block in {$relativePath} must use the request nonce.");
            }

            foreach ($openingTags as $tag) {
                if ($tag['name'] !== 'link') {
                    continue;
                }

                foreach ($this->htmlAttributeValues($tag['attributes'], 'href') as $href) {
                    if (preg_match('/^https?:\\/\\//i', $href) === 1) {
                        $this->fail("Unreviewed third-party link in {$relativePath}: {$href}");
                    }
                }
            }
        }

        foreach (File::allFiles(resource_path('js')) as $script) {
            $source = File::get($script->getPathname());
            $this->assertDoesNotMatchRegularExpression('/\\beval\\s*\\(|\\bnew\\s+Function\\s*\\(|\\bdocument\\.write\\s*\\(/i', $source, 'Application JavaScript must not use dynamic code execution or document.write.');
            $this->assertDoesNotMatchRegularExpression('/\\bsetTimeout\\s*\\(\\s*["\x27]|\\bsetInterval\\s*\\(\\s*["\x27]/i', $source, 'String-based timer execution is prohibited.');
        }

        foreach (File::allFiles(resource_path('css')) as $stylesheet) {
            $source = File::get($stylesheet->getPathname());
            $this->assertDoesNotMatchRegularExpression('/@import\\s+(?:url\\()?\\s*["\x27]?https?:|url\\(\\s*["\x27]?https?:/i', $source, 'Stylesheets must not load unreviewed third-party resources.');
        }
    }

    public function test_offline_fallback_uses_a_nonce_and_an_enforcing_policy(): void
    {
        $source = File::get(resource_path('views/pwa/service-worker.blade.php'));

        $this->assertStringContainsString("'Content-Security-Policy':", $source);
        $this->assertStringContainsString("script-src 'nonce-\${nonce}'", $source);
        $this->assertStringContainsString('<script nonce="${nonce}">', $source);
        $this->assertDoesNotMatchRegularExpression('/<body[^>]*\\sstyle\\s*=/i', $source);
    }

    private function htmlAttributeCount(string $attributes, string $name): int
    {
        return count($this->parseHtmlAttributes($attributes)[strtolower($name)] ?? []);
    }

    private function htmlAttributeValues(string $attributes, string $name): array
    {
        $matches = $this->parseHtmlAttributes($attributes)[strtolower($name)] ?? [];

        return array_values(array_map(
            static fn (array $attribute): string => $attribute['value'],
            array_filter($matches, static fn (array $attribute): bool => $attribute['quoted'])
        ));
    }

    private function openingTagAttributes(string $html): array
    {
        $tags = [];
        $length = strlen($html);
        $cursor = 0;

        while ($cursor < $length) {
            $start = strpos($html, '<', $cursor);
            if ($start === false || $start + 1 >= $length) {
                break;
            }

            if (substr($html, $start, 4) === '<!--') {
                $commentEnd = strpos($html, '-->', $start + 4);
                if ($commentEnd === false) {
                    break;
                }
                $cursor = $commentEnd + 3;

                continue;
            }

            if ($html[$start + 1] === '!' || $html[$start + 1] === '?') {
                $declarationEnd = strpos($html, '>', $start + 2);
                if ($declarationEnd === false) {
                    break;
                }
                $cursor = $declarationEnd + 1;

                continue;
            }

            if ($html[$start + 1] === '/') {
                $closingTagEnd = strpos($html, '>', $start + 2);
                if ($closingTagEnd === false) {
                    break;
                }
                $cursor = $closingTagEnd + 1;

                continue;
            }

            if (preg_match('/[a-z]/i', $html[$start + 1]) !== 1) {
                $cursor = $start + 1;

                continue;
            }

            $nameStart = $start + 1;
            $nameEnd = $nameStart;
            while ($nameEnd < $length && preg_match('/[a-z0-9:-]/i', $html[$nameEnd]) === 1) {
                $nameEnd++;
            }

            if ($nameEnd === $nameStart || ($nameEnd < $length && ! ctype_space($html[$nameEnd]) && ! in_array($html[$nameEnd], ['/', '>'], true))) {
                $cursor = $start + 1;

                continue;
            }

            $quote = null;
            $end = $nameEnd;
            while ($end < $length) {
                $character = $html[$end];
                if ($quote !== null) {
                    if ($character === $quote) {
                        $quote = null;
                    }
                } elseif ($character === '"' || $character === "'") {
                    $quote = $character;
                } elseif ($character === '>') {
                    break;
                }

                $end++;
            }

            if ($end >= $length) {
                break;
            }

            $tags[] = [
                'name' => strtolower(substr($html, $nameStart, $nameEnd - $nameStart)),
                'attributes' => substr($html, $nameEnd, $end - $nameEnd),
            ];
            $cursor = $end + 1;
        }

        return $tags;
    }

    private function parseHtmlAttributes(string $attributes): array
    {
        $parsed = [];
        $length = strlen($attributes);
        $offset = 0;

        while ($offset < $length) {
            while ($offset < $length && ctype_space($attributes[$offset])) {
                $offset++;
            }

            if ($offset >= $length) {
                break;
            }

            $nameStart = $offset;
            while ($offset < $length && ! ctype_space($attributes[$offset]) && $attributes[$offset] !== '=') {
                $offset++;
            }

            if ($offset === $nameStart) {
                $offset++;

                continue;
            }

            $name = strtolower(substr($attributes, $nameStart, $offset - $nameStart));
            while ($offset < $length && ctype_space($attributes[$offset])) {
                $offset++;
            }

            $value = '';
            $quoted = false;

            if ($offset < $length && $attributes[$offset] === '=') {
                $offset++;
                while ($offset < $length && ctype_space($attributes[$offset])) {
                    $offset++;
                }

                if ($offset < $length && ($attributes[$offset] === '"' || $attributes[$offset] === "'")) {
                    $quoted = true;
                    $quote = $attributes[$offset++];
                    $valueStart = $offset;
                    while ($offset < $length && $attributes[$offset] !== $quote) {
                        $offset++;
                    }
                    $value = substr($attributes, $valueStart, $offset - $valueStart);
                    if ($offset < $length) {
                        $offset++;
                    }
                } else {
                    $valueStart = $offset;
                    while ($offset < $length && ! ctype_space($attributes[$offset])) {
                        $offset++;
                    }
                    $value = substr($attributes, $valueStart, $offset - $valueStart);
                }
            }

            $parsed[$name][] = ['value' => $value, 'quoted' => $quoted];
        }

        return $parsed;
    }

    private function isSameOriginScriptSource(string $source): bool
    {
        if ($source === '' || preg_match('/[\\x00-\\x1f\\x7f\\\\\\\\]/', $source) === 1) {
            return false;
        }

        if (str_starts_with($source, '/') && ! str_starts_with($source, '//') && preg_match('/\\s/', $source) !== 1) {
            return true;
        }

        if (preg_match('/^https?:\\/\\//i', $source) === 1) {
            return $this->hasSameOrigin($source);
        }

        if (preg_match('/^\\{\\{\\s*asset\\(\\s*(["\x27])(.*?)\\1\\s*\\)\\s*\\}\\}$/i', $source, $matches) !== 1) {
            return false;
        }

        $assetPath = $matches[2];

        if ($assetPath === '' || trim($assetPath) !== $assetPath || preg_match('/[\\x00-\\x20\\x7f\\\\\\\\]/', $assetPath) === 1) {
            return false;
        }

        if (str_starts_with($assetPath, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $assetPath) === 1) {
            return false;
        }

        return $this->hasSameOrigin(asset($assetPath));
    }

    private function hasSameOrigin(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }

        $actual = parse_url($url);
        $expected = parse_url((string) config('app.url'));

        if (
            ! is_array($actual)
            || ! is_array($expected)
            || ! isset($actual['scheme'], $actual['host'], $expected['scheme'], $expected['host'])
            || isset($actual['user'])
            || isset($actual['pass'])
        ) {
            return false;
        }

        $actualScheme = strtolower($actual['scheme']);
        $expectedScheme = strtolower($expected['scheme']);
        $defaultPorts = ['http' => 80, 'https' => 443];
        $actualPort = $actual['port'] ?? ($defaultPorts[$actualScheme] ?? null);
        $expectedPort = $expected['port'] ?? ($defaultPorts[$expectedScheme] ?? null);

        return $actualScheme === $expectedScheme
            && strtolower($actual['host']) === strtolower($expected['host'])
            && $actualPort === $expectedPort;
    }

    private function nonceFromPolicy(string $policy): string
    {
        $this->assertNotSame('', $policy, 'An enforcing Content-Security-Policy header is required.');
        $this->assertSame(
            1,
            preg_match("/(?:^|;\\s*)script-src[^;]*'nonce-([A-Za-z0-9+\\/=]+)'(?:\\s|;|$)/", $policy, $matches),
            "Unable to parse the request nonce from CSP: {$policy}"
        );

        return $matches[1];
    }
}
