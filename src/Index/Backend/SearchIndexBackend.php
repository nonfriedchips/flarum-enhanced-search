<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Index\Backend;

use Illuminate\Database\Schema\Blueprint;
use NonFriedChips\EnhancedSearch\Search\QueryPlan;

interface SearchIndexBackend
{
    public function name(): string;

    public function assertCompatible(): void;

    public function configureTable(Blueprint $table): void;

    /** @return array<string, string> */
    public function indexPayload(string $normalized): array;

    public function fulltextColumn(): string;

    public function fulltextIndexName(): string;

    /** @param string[] $transpositionVariants */
    public function query(QueryPlan $plan, array $transpositionVariants): FulltextQuery;

    public function createFulltextIndex(string $tableName): void;
}
