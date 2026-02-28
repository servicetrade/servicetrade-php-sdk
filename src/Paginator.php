<?php

declare(strict_types=1);

namespace ServiceTrade;

/**
 * Iterates through all pages of a paginated list endpoint.
 *
 * Usage:
 *   $paginator = new Paginator($client, '/jobs', 'jobs', ['status' => 'scheduled']);
 *   foreach ($paginator as $job) {
 *       echo $job['name'];
 *   }
 */
final class Paginator implements \IteratorAggregate
{
    public function __construct(
        private readonly Client $client,
        private readonly string $path,
        private readonly string $itemsKey,
        private readonly array $query = [],
    ) {
    }

    public function getIterator(): \Generator
    {
        $page = 1;

        do {
            $query = array_merge($this->query, ['page' => $page]);
            $data = $this->client->get($this->path, $query);

            if ($data === null || !isset($data[$this->itemsKey])) {
                return;
            }

            foreach ($data[$this->itemsKey] as $item) {
                yield $item;
            }

            $totalPages = $data['totalPages'] ?? 1;
            $page++;
        } while ($page <= $totalPages);
    }
}
