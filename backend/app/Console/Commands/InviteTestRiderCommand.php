<?php

namespace App\Console\Commands;

use App\Application\Commands\InviteTestRider;
use App\Domain\Identity\TestRiderRefused;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * ADR-021. Creates (or reuses) one synthetic approved rider and emails a
 * set-password link, so the operator chooses the password. Deliberately not in
 * ProductionGuard::REPAIR_COMMANDS: it sends a credential-bearing link.
 */
#[AsCommand(name: 'rider:invite-test', description: 'Create a synthetic test rider and email a link to choose its password')]
class InviteTestRiderCommand extends Command
{
    protected $signature = 'rider:invite-test
        {email : The address that receives the set-password link}
        {--name=Test rider : Display name for the account}';

    public function handle(InviteTestRider $invite): int
    {
        $input = ['email' => trim((string) $this->argument('email')), 'name' => trim((string) $this->option('name'))];

        $validator = Validator::make($input, [
            'email' => ['required', 'string', 'max:254', 'email:rfc'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        $origin = (string) config('surfaces.origins.customer');
        if (! str_starts_with($origin, 'http')) {
            $this->error('CUSTOMER_APP_URL is not set to an origin.');

            return self::FAILURE;
        }

        try {
            $created = $invite->handle($input['email'], $input['name'], $origin);
        } catch (TestRiderRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info($created ? 'Test rider created.' : 'Existing test rider reused.');
        $this->line('A set-password link was emailed (valid 60 minutes, works once). The link is not printed or stored.');

        return self::SUCCESS;
    }
}
