<?php

namespace Tests\Unit;

use App\Support\Countries;
use PHPUnit\Framework\TestCase;

class CountriesTest extends TestCase
{
    public function test_normalize_keeps_known_iso_codes(): void
    {
        $this->assertSame('RO', Countries::normalize('RO'));
        $this->assertSame('MA', Countries::normalize('ma'));
    }

    public function test_normalize_resolves_english_and_romanian_names_to_the_same_code(): void
    {
        $this->assertSame('MA', Countries::normalize('Morocco'));
        $this->assertSame('MA', Countries::normalize('Maroc'));
        $this->assertSame('RO', Countries::normalize('România'));
        $this->assertSame('NL', Countries::normalize('Olanda'));
        $this->assertSame('DO', Countries::normalize('Republica Dominicană'));
    }

    public function test_normalize_blanks_become_null(): void
    {
        $this->assertNull(Countries::normalize(null));
        $this->assertNull(Countries::normalize('   '));
    }

    public function test_normalize_keeps_unknown_values_intact(): void
    {
        $this->assertSame('Atlantis', Countries::normalize('Atlantis'));
    }
}
