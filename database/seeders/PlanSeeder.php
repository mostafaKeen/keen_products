<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'trial',
                'name' => 'Free Trial / Sandbox',
                'description' => '14-day full evaluation for Bitrix24 Marketplace portals with up to 150 syncs/month.',
                'price_monthly_cents' => 0,
                'price_yearly_cents' => 0,
                'currency' => 'USD',
                'trial_days' => 14,
                'max_monthly_syncs' => 150,
                'max_options' => 2,
                'sort_order' => 1,
                'is_active' => true,
                'is_public' => true,
                'features_json' => [
                    'allow_auto_background_sync' => true,
                    'allow_bidirectional_spa_sync' => false,
                    'allow_custom_spas' => true,
                    'allow_custom_product_attributes' => true,
                    'allow_backfill' => false,
                ],
            ],
            [
                'code' => 'starter',
                'name' => 'Starter',
                'description' => 'Ideal for small teams using multi-option quoting across Leads & Deals.',
                'price_monthly_cents' => 4900,
                'price_yearly_cents' => 49000,
                'currency' => 'USD',
                'trial_days' => 14,
                'max_monthly_syncs' => 1000,
                'max_options' => 2,
                'sort_order' => 2,
                'is_active' => true,
                'is_public' => true,
                'features_json' => [
                    'allow_auto_background_sync' => true,
                    'allow_bidirectional_spa_sync' => false,
                    'allow_custom_spas' => false,
                    'allow_custom_product_attributes' => false,
                    'allow_backfill' => true,
                ],
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'description' => 'Full bidirectional SPA synchronization, custom target SPAs, and custom product attributes.',
                'price_monthly_cents' => 12900,
                'price_yearly_cents' => 129000,
                'currency' => 'USD',
                'trial_days' => 14,
                'max_monthly_syncs' => 10000,
                'max_options' => 4,
                'sort_order' => 3,
                'is_active' => true,
                'is_public' => true,
                'features_json' => [
                    'allow_auto_background_sync' => true,
                    'allow_bidirectional_spa_sync' => true,
                    'allow_custom_spas' => true,
                    'allow_custom_product_attributes' => true,
                    'allow_backfill' => true,
                ],
            ],
            [
                'code' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Unlimited syncs, up to 6 quote options, high-throughput Bitrix24 Enterprise batching, and priority support.',
                'price_monthly_cents' => 29900,
                'price_yearly_cents' => 299000,
                'currency' => 'USD',
                'trial_days' => 30,
                'max_monthly_syncs' => null,
                'max_options' => 6,
                'sort_order' => 4,
                'is_active' => true,
                'is_public' => true,
                'features_json' => [
                    'allow_auto_background_sync' => true,
                    'allow_bidirectional_spa_sync' => true,
                    'allow_custom_spas' => true,
                    'allow_custom_product_attributes' => true,
                    'allow_backfill' => true,
                ],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], $plan);
        }
    }
}

