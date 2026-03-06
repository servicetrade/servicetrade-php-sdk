<?php

declare(strict_types=1);

namespace ServiceTrade\Tests;

use PHPUnit\Framework\TestCase;
use ServiceTrade\Client;
use ServiceTrade\HttpTransportInterface;
use ServiceTrade\Paginator;
use ServiceTrade\Response;

final class PaginatorTest extends TestCase
{
    private function makeJwt(int $exp): string
    {
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode(['exp' => $exp, 'sub' => 'test']));
        $signature = base64_encode('fake-signature');

        return "{$header}.{$payload}.{$signature}";
    }

    private function tokenResponse(): Response
    {
        return new Response(200, json_encode([
            'access_token' => $this->makeJwt(time() + 3600),
            'expires_in' => 86400,
            'token_type' => 'Bearer',
        ]));
    }

    public function testIteratesAllPages(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
                $page = (int) ($query['page'] ?? 1);

                $jobs = match ($page) {
                    1 => [['id' => 1], ['id' => 2]],
                    2 => [['id' => 3], ['id' => 4]],
                    3 => [['id' => 5]],
                    default => [],
                };

                return new Response(200, json_encode([
                    'data' => [
                        'jobs' => $jobs,
                        'page' => $page,
                        'totalPages' => 3,
                    ],
                    'messages' => [],
                ]));
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );

        $paginator = new Paginator($client, '/jobs', 'jobs');
        $allIds = [];
        foreach ($paginator as $job) {
            $allIds[] = $job['id'];
        }

        $this->assertSame([1, 2, 3, 4, 5], $allIds);
    }

    public function testSinglePage(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                return new Response(200, json_encode([
                    'data' => [
                        'locations' => [['id' => 10]],
                        'page' => 1,
                        'totalPages' => 1,
                    ],
                    'messages' => [],
                ]));
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );

        $paginator = new Paginator($client, '/locations', 'locations');
        $items = iterator_to_array($paginator);

        $this->assertCount(1, $items);
        $this->assertSame(10, $items[0]['id']);
    }

    public function testEmptyResult(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                return new Response(200, json_encode([
                    'data' => [
                        'jobs' => [],
                        'page' => 1,
                        'totalPages' => 0,
                    ],
                    'messages' => [],
                ]));
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );

        $paginator = new Paginator($client, '/jobs', 'jobs');
        $items = iterator_to_array($paginator);

        $this->assertSame([], $items);
    }

    public function testPassesQueryParams(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                $this->assertStringContainsString('status=scheduled', $url);
                $this->assertStringContainsString('page=1', $url);

                return new Response(200, json_encode([
                    'data' => [
                        'jobs' => [['id' => 1]],
                        'page' => 1,
                        'totalPages' => 1,
                    ],
                    'messages' => [],
                ]));
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );

        $paginator = new Paginator($client, '/jobs', 'jobs', ['status' => 'scheduled']);
        iterator_to_array($paginator);
    }

    public function testMissingItemsKeyReturnsEmpty(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                return new Response(200, json_encode([
                    'data' => [
                        'jobs' => [['id' => 1]],
                        'page' => 1,
                        'totalPages' => 1,
                    ],
                    'messages' => [],
                ]));
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );

        // itemsKey 'foobar' doesn't match the 'jobs' key in the response
        $paginator = new Paginator($client, '/jobs', 'foobar');
        $items = iterator_to_array($paginator);

        $this->assertSame([], $items);
    }
}
