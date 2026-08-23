<?php

namespace Turiac\FilamentImageGallery\Tests\Unit;

use Filament\Forms\Components\Repeater;
use ReflectionMethod;
use Turiac\FilamentImageGallery\Tests\TestCase;

class FilamentCompatibilityTest extends TestCase
{
    public function test_reviewed_repeater_hydration_implementation_has_not_changed(): void
    {
        $method = new ReflectionMethod(Repeater::class, 'hydrateItems');
        $lines = file($method->getFileName());

        $this->assertIsArray($lines);

        $body = implode("\n", array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertSame(
            '1450544e4f4971dc3926d76c39457ebb917d49dc',
            sha1((string) preg_replace('/\s+/', ' ', $body)),
            'Filament Repeater::hydrateItems() changed; review ImageGallery::hydrateItems() before upgrading.',
        );
    }
}
