<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\Auth\BootstrapAdminUser;
use App\Domain\Admin\AdminRole;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Creates platform staff from the server; the password is asked for without echo and never taken as an argument. */
final class CreateAdminUser extends Command
{
    protected $signature = 'admin:create {email} {name} {--role=owner : owner, finance, sales, support, engineer or readonly}';

    protected $description = 'Create a platform admin (staff) account';

    public function handle(BootstrapAdminUser $bootstrap): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim((string) $this->argument('name'));
        $role = AdminRole::tryFrom((string) $this->option('role'));

        $input = Validator::make(['email' => $email, 'name' => $name], ['email' => ['required', 'email'], 'name' => ['required', 'max:255']]);
        if ($role === null || $input->fails()) {
            $this->error($role === null ? 'Unknown role. Use one of: '.implode(', ', array_column(AdminRole::cases(), 'value')).'.' : $input->errors()->first());

            return self::FAILURE;
        }
        if (AdminUser::query()->email($email)->exists()) {
            $this->error("A staff member with email {$email} already exists.");

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password');
        if ($password !== (string) $this->secret('Confirm password')) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }
        $check = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);
        if ($check->fails()) {
            $this->error($check->errors()->first());

            return self::FAILURE;
        }

        try {
            $admin = $bootstrap($email, $name, $role, $password);
        } catch (DomainRuleViolation $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Created {$role->value} {$admin->email}. They set up two-factor authentication at first sign-in.");

        return self::SUCCESS;
    }
}
