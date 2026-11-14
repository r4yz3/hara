<?php

namespace App\Console\Commands;

use App\Application\Commands\BootstrapOwner;
use App\Domain\Identity\BootstrapOwnerRefused;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * ADR-018. Run once, on the server, to get the link that lets the first
 * platform owner choose a password. Deliberately not in
 * ProductionGuard::REPAIR_COMMANDS: it prints a credential-bearing link, so
 * it must refuse to run in an environment the guard rejects.
 */
#[AsCommand(name: 'staff:bootstrap-owner', description: 'Print a one-time link that creates the first platform owner (only while none exists)')]
class BootstrapOwnerCommand extends Command
{
    protected $signature = 'staff:bootstrap-owner
        {email : The address of the first platform owner}
        {--name=Platform owner : Display name for the new account}
        {--replace : Revoke a still-pending bootstrap link and print a new one}';

    public function handle(BootstrapOwner $bootstrap): int
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

        $origin = (string) config('surfaces.origins.admin');
        if (! str_starts_with($origin, 'http')) {
            $this->error('ADMIN_APP_URL is not set to an origin.');

            return self::FAILURE;
        }

        try {
            $link = $bootstrap->issue($input['email'], $input['name'], $origin, (bool) $this->option('replace'));
        } catch (BootstrapOwnerRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info('First platform owner invitation created. It is valid for 72 hours and works once.');
        $this->line('Open this link in a browser to set a password; two-step sign-in is set up at the first sign-in.');
        $this->newLine();
        $this->line($link);
        $this->newLine();
        $this->line('The link is shown only now and is not stored. Do not paste it into chat, tickets or logs.');

        return self::SUCCESS;
    }
}
