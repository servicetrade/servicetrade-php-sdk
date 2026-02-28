<?php

declare(strict_types=1);

namespace ServiceTrade\Tests;

use PHPUnit\Framework\TestCase;
use ServiceTrade\CurlHttpTransport;
use ServiceTrade\HttpTransportInterface;

final class CurlHttpTransportTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $transport = new CurlHttpTransport('ServiceTrade PHP SDK/test');

        $this->assertInstanceOf(HttpTransportInterface::class, $transport);
    }

    public function testConstructorWithCustomValues(): void
    {
        // Simply verify construction doesn't throw
        $transport = new CurlHttpTransport(
            userAgent: 'Custom Agent',
        );

        $this->assertInstanceOf(CurlHttpTransport::class, $transport);
    }
}
