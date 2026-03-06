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

    private function makeTransport(callable $apiHandler): HttpTransportInterface
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url, array $headers = [], ?string $body = null, array $options = []) use ($apiHandler) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }
                return $apiHandler($method, $url, $headers, $body, $options);
            });

        return $transport;
    }

    private function makeClient(HttpTransportInterface $transport): Client
    {
        return new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );
    }

    public function testIteratesAllPages(): void
    {
        $transport = $this->makeTransport(function (string $method, string $url) {
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

        $paginator = new Paginator($this->makeClient($transport), '/jobs', 'jobs');
        $allIds = [];
        foreach ($paginator as $job) {
            $allIds[] = $job['id'];
        }

        $this->assertSame([1, 2, 3, 4, 5], $allIds);
    }

    public function testSinglePage(): void
    {
        $transport = $this->makeTransport(fn() => new Response(200, json_encode([
            'data' => [
                'locations' => [['id' => 10]],
                'page' => 1,
                'totalPages' => 1,
            ],
            'messages' => [],
        ])));

        $paginator = new Paginator($this->makeClient($transport), '/locations', 'locations');
        $items = iterator_to_array($paginator);

        $this->assertCount(1, $items);
        $this->assertSame(10, $items[0]['id']);
    }

    public function testEmptyResult(): void
    {
        $transport = $this->makeTransport(fn() => new Response(200, json_encode([
            'data' => [
                'jobs' => [],
                'page' => 1,
                'totalPages' => 0,
            ],
            'messages' => [],
        ])));

        $paginator = new Paginator($this->makeClient($transport), '/jobs', 'jobs');
        $items = iterator_to_array($paginator);

        $this->assertSame([], $items);
    }

    public function testPassesQueryParams(): void
    {
        $transport = $this->makeTransport(function (string $method, string $url) {
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

        $paginator = new Paginator($this->makeClient($transport), '/jobs', 'jobs', ['status' => 'scheduled']);
        iterator_to_array($paginator);
    }

    public function testMissingItemsKeyReturnsEmpty(): void
    {
        $transport = $this->makeTransport(fn() => new Response(200, json_encode([
            'data' => [
                'jobs' => [['id' => 1]],
                'page' => 1,
                'totalPages' => 1,
            ],
            'messages' => [],
        ])));

        // itemsKey 'foobar' doesn't match the 'jobs' key in the response
        $paginator = new Paginator($this->makeClient($transport), '/jobs', 'foobar');
        $items = iterator_to_array($paginator);

        $this->assertSame([], $items);
    }
}
