<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_a_strict_csp_is_sent(): void
    {
        $csp = $this->get('/')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("style-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    /**
     * The CSP forbids inline scripts, handlers and styles; this keeps the
     * views honest so that stays true without anyone noticing a broken page.
     */
    public function test_no_view_relies_on_inline_script_or_style(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            $html = $file->getContents();
            $name = $file->getRelativePathname();
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html, "$name has an inline <script>");
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $html, "$name has an inline event handler");
            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=\s*["\']/i', $html, "$name has an inline style attribute");
            $this->assertStringNotContainsString('javascript:', $html, "$name has a javascript: URL");
        }
    }
}
