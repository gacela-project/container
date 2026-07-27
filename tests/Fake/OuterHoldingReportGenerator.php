<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

final class OuterHoldingReportGenerator
{
    public function __construct(
        public ExpensiveReportGenerator $reportGenerator,
    ) {
    }
}
