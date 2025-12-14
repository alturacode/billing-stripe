<?php

declare(strict_types=1);

namespace Tests\Mothers;

use AlturaCode\Billing\Core\Common\BillableDetails;

final readonly class BillableDetailsMother
{
    public static function createMinimal(): BillableDetails
    {
        return BillableDetails::from(displayName: 'Jane Doe');
    }
}