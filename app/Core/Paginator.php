<?php
declare(strict_types=1);

namespace Core;

/**
 * Offset paginator. Keeps list queries bounded so a table with millions of
 * history rows is never loaded into a single page.
 */
final class Paginator
{
    public int $page;
    public int $perPage;
    public int $total;
    public int $lastPage;

    public function __construct(int $total, int $page, int $perPage)
    {
        $this->perPage = max(1, $perPage);
        $this->total   = max(0, $total);
        $this->lastPage = max(1, (int) ceil($this->total / $this->perPage));
        $this->page    = min(max(1, $page), $this->lastPage);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : $this->offset() + 1;
    }

    public function to(): int
    {
        return min($this->offset() + $this->perPage, $this->total);
    }

    public function hasPages(): bool
    {
        return $this->lastPage > 1;
    }

    public function summary(): string
    {
        if ($this->total === 0) {
            return 'No records';
        }

        return sprintf('%d-%d of %d', $this->from(), $this->to(), $this->total);
    }

    /**
     * Window of page numbers around the current page.
     *
     * @return array<int,int>
     */
    public function window(int $radius = 2): array
    {
        $start = max(1, $this->page - $radius);
        $end   = min($this->lastPage, $this->page + $radius);

        return range($start, $end);
    }
}
