<?php

namespace Database\Seeders;

use App\Models\RetentionPolicy;
use Illuminate\Database\Seeder;

class RetentionPolicySeeder extends Seeder
{
    public function run(): void
    {
        foreach (RetentionPolicy::schema() as $definition) {
            RetentionPolicy::query()->updateOrCreate(
                ['key' => $definition['key']],
                [
                    'label' => $definition['label'],
                    'data_type' => $definition['data_type'],
                    'retention_days' => $definition['retention_days'],
                    'archive_enabled' => $definition['archive_enabled'],
                    'archive_after_days' => $definition['archive_after_days'],
                    'deletion_enabled' => $definition['deletion_enabled'],
                    'deletion_after_days' => $definition['deletion_after_days'],
                    'enabled' => $definition['enabled'],
                    'rules' => $definition['rules'],
                    'notes' => $definition['notes'],
                ],
            );
        }
    }
}
