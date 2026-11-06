<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DevelopmentCustomerSeeder extends Seeder
{
    use WithoutModelEvents;

    private const PASSWORD = 'customer123';

    /** Fictional local demonstration identities, never client records.
     *
     * @var list<string>
     */
    private const NAMES = [
        'Miguel Santos',
        'Isabella Reyes',
        'Gabriel Dela Cruz',
        'Sofia Mendoza',
        'Paolo Garcia',
        'Camille Bautista',
        'Rafael Villanueva',
        'Andrea Ramos',
        'Joshua Navarro',
        'Bianca Torres',
        'Carlo Fernandez',
        'Patricia Aquino',
        'Luis Mercado',
        'Angela Castillo',
        'Mark Angelo Flores',
        'Nicole Valdez',
        'Adrian Santiago',
        'Jasmine Soriano',
        'Francis Lim',
        'Danica Gonzales',
        'Enrico Manalo',
        'Alyssa Gutierrez',
        'Vincent Salazar',
        'Kristine Tolentino',
        'Jerome Pineda',
        'Marielle Agustin',
        'Noel Alvarado',
        'Trisha Caballero',
        'Dominic Escobar',
        'Elena Magbanua',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $isLocalEnvironment = App::environment(['local', 'testing']);
        $isEnabledCloudDemoEnvironment = App::environment(['production', 'staging'])
            && config('forecasting.cloud_demo_history_enabled') === true;

        if (! $isLocalEnvironment && ! $isEnabledCloudDemoEnvironment) {
            throw new RuntimeException('Customer demonstration data is only allowed in local and testing environments unless Cloud demo history is explicitly enabled.');
        }

        $accounts = self::accounts();

        DB::transaction(function () use ($accounts): void {
            $existing = User::query()->whereIn(DB::raw('LOWER(email)'), array_keys($accounts))
                ->lockForUpdate()->get()->keyBy('email');

            foreach ($existing as $customer) {
                $email = strtolower($customer->email);
                if (! $this->matchesAccount($customer, $email, $accounts[$email])) {
                    throw new RuntimeException("Customer demonstration identity conflicts with an existing account: {$email}. No accounts were changed. Review the conflict or reset the development database manually.");
                }
            }

            foreach ($accounts as $email => $name) {
                if ($existing->has($email)) {
                    continue;
                }

                (new User)->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'role' => UserRole::Customer,
                    'email_verified_at' => now(),
                    'password' => self::PASSWORD,
                ])->save();
            }
        });
    }

    /** @return array<string, string> */
    public static function accounts(): array
    {
        $accounts = [];
        foreach (self::NAMES as $index => $name) {
            $accounts[sprintf('demo.customer%02d@example.test', $index + 1)] = $name;
        }

        return $accounts;
    }

    private function matchesAccount(User $customer, string $email, string $name): bool
    {
        if ($customer->email !== $email || $customer->name !== $name
            || $customer->getRawOriginal('role') !== UserRole::Customer->value
            || ! $customer->hasVerifiedEmail()) {
            return false;
        }

        try {
            return Hash::check(self::PASSWORD, $customer->password);
        } catch (RuntimeException) {
            return false;
        }
    }
}
