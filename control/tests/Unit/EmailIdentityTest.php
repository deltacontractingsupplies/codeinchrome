<?php

namespace Tests\Unit;

use App\Auth\EmailIdentity;
use PHPUnit\Framework\TestCase;

class EmailIdentityTest extends TestCase
{
    public function test_gmail_spellings_of_one_inbox_are_one_identity(): void
    {
        foreach (['ab@gmail.com', 'a.b@gmail.com', 'A.B+x@Gmail.com', 'a.b+x+y@googlemail.com', ' AB@GMAIL.COM '] as $e) {
            $this->assertSame('ab@gmail.com', EmailIdentity::canonical($e), $e);
        }
    }

    public function test_other_providers_only_fold_case(): void
    {
        $this->assertSame('a.b+x@example.com', EmailIdentity::canonical('A.B+x@Example.COM'));
        $this->assertSame('a.b@gmail.com.evil.test', EmailIdentity::canonical('a.b@gmail.com.evil.test'));
        $this->assertSame('no-at-sign', EmailIdentity::canonical('No-At-Sign'));
    }
}
