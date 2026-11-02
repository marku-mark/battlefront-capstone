<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\TeamAccessService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Signature('administration:assign-role {email} {role : staff, manager, or owner} {--create : Create the first Owner using hidden password prompts} {--name= : Name required when creating the first Owner}')]
#[Description('Explicitly assign an existing Administrator role or provision the first Owner.')]
class AssignAdministrationRole extends Command
{
    use PasswordValidationRules;

    public function handle(TeamAccessService $access): int
    {
        $roleCode = (string) $this->argument('role');
        if (! in_array($roleCode, ['staff', 'manager', 'owner'], true)) {
            $this->error('Role must be staff, manager, or owner.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');

        try {
            if ($this->option('create')) {
                if ($roleCode !== 'owner' || ! $this->input->isInteractive()) {
                    $this->error('First Owner creation requires role owner and an interactive terminal for hidden password prompts.');

                    return self::FAILURE;
                }
                $attributes = Validator::make(['email' => $email, 'name' => $this->option('name')], [
                    'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                    'name' => ['required', 'string', 'max:255'],
                ])->validate();
                $credentials = Validator::make([
                    'password' => $this->secret('Password', false),
                    'password_confirmation' => $this->secret('Confirm password', false),
                ], ['password' => $this->passwordRules()])->validate();
                $user = $access->createInitialOwner($attributes['name'], $attributes['email'], $credentials['password']);
            } else {
                $user = User::query()->where('email', $email)
                    ->where('role', UserRole::Administrator->value)->first();
                if ($user === null) {
                    $this->error('No administrator has that email address. No account was changed.');

                    return self::FAILURE;
                }

                $access->assignRoleFromConsole($user, $roleCode);
            }
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $this->info("Assigned {$user->email} to {$roleCode}.".($user->is_active ? '' : ' The account remains inactive.'));

        return self::SUCCESS;
    }
}
