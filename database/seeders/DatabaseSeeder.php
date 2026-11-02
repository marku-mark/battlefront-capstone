<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
            DevelopmentChatbotKnowledgeSeeder::class,
        ]);

        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->call(PcBuildTemplateSeeder::class);

        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'email_verified_at' => Carbon::now(),
                'password' => 'password',
                'role' => UserRole::Customer->value,
            ],
        );

        foreach ([
            ['email' => 'admin@example.com', 'name' => 'Development Owner', 'role' => 'owner'],
            ['email' => 'staff@example.com', 'name' => 'Development Staff', 'role' => 'staff'],
            ['email' => 'manager@example.com', 'name' => 'Development Manager', 'role' => 'manager'],
        ] as $account) {
            DB::transaction(function () use ($account): void {
                $administrator = User::query()->firstOrCreate(
                    ['email' => $account['email']],
                    [
                        'name' => $account['name'],
                        'email_verified_at' => Carbon::now(),
                        'password' => 'password',
                    ],
                );
                if (! $administrator->wasRecentlyCreated) {
                    return;
                }

                $administrator->role = UserRole::Administrator;
                $administrator->administration_role_id = DB::table('administration_roles')->where('code', $account['role'])->soleValue('id');
                $administrator->save();
                DB::table('audit_logs')->insert([
                    'actor_id' => null,
                    'action' => $account['role'] === 'owner' ? 'team.owner.created_by_seeder' : 'team.created_by_seeder',
                    'subject_type' => 'user',
                    'subject_id' => $administrator->id,
                    'reason' => 'New local/testing demonstration account only',
                    'changes' => json_encode(['role' => $account['role']], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            });
        }
    }
}
