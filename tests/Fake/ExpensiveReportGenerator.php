<?php

declare(strict_types=1);

namespace GacelaTest\Fake;

/**
 * Carries no #[Lazy]: it stands in for a vendor class that lazy() has to make
 * lazy from the outside.
 */
final class ExpensiveReportGenerator implements ReportGeneratorInterface
{
    public function __construct(
        public ClassWithoutDependencies $dependency,
    ) {
        ConstructionCounter::record(self::class);
    }
}
