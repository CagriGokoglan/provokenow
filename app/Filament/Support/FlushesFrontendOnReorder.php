<?php

namespace App\Filament\Support;

use App\Support\FrontendCache;

/** Drag-and-drop reordering writes with a bulk query (no model events), so flush explicitly. */
trait FlushesFrontendOnReorder
{
    public function reorderTable(array $order): void
    {
        parent::reorderTable($order);

        FrontendCache::flush();
    }
}
