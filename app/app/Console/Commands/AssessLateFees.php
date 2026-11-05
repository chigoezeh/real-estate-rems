<?php

namespace App\Console\Commands;

use App\Domain\Services\LateFeeAssessmentService;
use App\Models\OrganizationLatePaymentPolicy;
use Illuminate\Console\Command;

class AssessLateFees extends Command
{
    protected $signature = 'rent:assess-late-fees';

    protected $description = 'Assess due company-configured late-payment fees for active tenancies';

    public function handle(LateFeeAssessmentService $service): int
    {
        $organizationIds = OrganizationLatePaymentPolicy::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('organization_id')
            ->all();
        $count = $service->assess($organizationIds);

        $this->info("Assessed {$count} late fee(s).");

        return self::SUCCESS;
    }
}
