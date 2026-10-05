<?php

namespace Fleetbase\FleetOps\Seeders\Testing;

use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoSeeder extends Seeder
{
    private const COMPANY_KEY = 'fleetbase-demo-company';

    public function run(): void
    {
        if (app()->environment('production') && !filter_var(env('ALLOW_DEMO_DATA', false), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Demo data is disabled in production. Set ALLOW_DEMO_DATA=true only if you understand the risk.');
        }

        $email    = env('DEMO_ADMIN_EMAIL', 'demo@fleetbase.test');
        $password = env('DEMO_ADMIN_PASSWORD', 'fleetbase-demo');

        $company = DB::transaction(function () use ($email, $password): Company {
            $company = Company::where('_key', self::COMPANY_KEY)->first() ?? new Company();
            $company->forceFill([
                '_key'                    => self::COMPANY_KEY,
                'name'                    => 'Fleetbase Demo',
                'status'                  => 'active',
                'type'                    => 'demo',
                'country'                 => 'SG',
                'timezone'                => 'Asia/Singapore',
                'currency'                => 'SGD',
                'onboarding_completed_at' => now(),
            ])->save();

            $user = User::firstOrNew(['email' => $email]);
            $user->forceFill([
                '_key'             => 'fleetbase-demo-admin',
                'company_uuid'     => $company->uuid,
                'name'             => 'Fleetbase Demo Admin',
                'username'         => 'fleetbase_demo_admin',
                'status'           => 'active',
                'timezone'         => 'Asia/Singapore',
                'country'          => 'SG',
                'email_verified_at' => now(),
            ]);
            $user->password = $password;
            $user->save();
            $user->setUserType('admin');

            $company->setOwner($user, true)->save();
            $user->assignCompany($company, 'Administrator');
            $user->assignSingleRole('Administrator');

            return $company;
        });

        $previousCompanyUuid = getenv('SEED_COMPANY_UUID');
        putenv('SEED_COMPANY_UUID=' . $company->uuid);

        try {
            $this->call(TestingSeeder::class);
        } finally {
            if ($previousCompanyUuid === false) {
                putenv('SEED_COMPANY_UUID');
            } else {
                putenv('SEED_COMPANY_UUID=' . $previousCompanyUuid);
            }
        }

        $this->command?->info("Demo organization ready. Sign in with {$email}.");
    }
}
