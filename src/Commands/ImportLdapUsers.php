<?php

namespace LdapRecord\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use LdapRecord\Laravel\Auth\DatabaseUserProvider;
use LdapRecord\Laravel\Auth\UserProvider;
use LdapRecord\Laravel\DetectsSoftDeletes;
use LdapRecord\Laravel\Events\Import\Imported;
use LdapRecord\Laravel\Events\Import\ImportFailed;
use LdapRecord\Laravel\Events\Import\BulkImportStarted;
use LdapRecord\Laravel\Events\Import\BulkImportCompleted;
use LdapRecord\Laravel\Events\BulkImportDeletedMissing;
use Symfony\Component\Console\Helper\ProgressBar;

class ImportLdapUsers extends Command
{
    use DetectsSoftDeletes;

    /**
     * The signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ldap:import {provider : The authentication provider to import.}
            {user? : The specific user to import.}
            {--f|filter= : The raw LDAP filter for limiting users imported.}
            {--a|attributes= : Comma separated list of LDAP attributes to select. }
            {--d|delete : Soft-delete the users model if their LDAP account is disabled.}
            {--r|restore : Restores soft-deleted models if their LDAP account is enabled.}
            {--delete-missing : Soft-delete all users that are missing from the import. }
            {--no-log : Disables logging successful and unsuccessful imports.}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Imports LDAP users into the application database.';

    /**
     * The LDAP user import instance.
     *
     * @var LdapUserImporter
     */
    protected $import;

    /**
     * The LDAP objects being imported.
     *
     * @var \LdapRecord\Query\Collection
     */
    protected $objects;

    /**
     * The import progress bar indicator.
     *
     * @var ProgressBar|null
     */
    protected $progress;

    /**
     * Constructor.
     *
     * @param LdapUserImporter $import
     */
    public function __construct(LdapUserImporter $import)
    {
        parent::__construct();

        $this->import = $import;
    }

    /**
     * Execute the console command.
     *
     * @return void
     *
     * @throws \LdapRecord\Models\ModelNotFoundException
     */
    public function handle()
    {
        /** @var \LdapRecord\Laravel\Auth\DatabaseUserProvider $provider */
        $provider = Auth::createUserProvider($this->argument('provider'));

        if (! $provider instanceof UserProvider) {
            return $this->error("Provider [{$this->argument('provider')}] is not configured for LDAP authentication.");
        } elseif (! $provider instanceof DatabaseUserProvider) {
            return $this->error("Provider [{$this->argument('provider')}] is not configured for database synchronization.");
        }

        $this->applyCommandOptions();
        $this->applyProviderImporter($provider);
        $this->applyProviderRepository($provider);

        if (! $this->hasObjectsToImport()) {
            return;
        }

        $this->registerEventListeners();

        $this->confirmAndDisplayObjects();

        $this->confirmAndExecuteImport();
    }

    /**
     * Confirm and execute the import.
     *
     * @return void
     */
    protected function confirmAndExecuteImport()
    {
        if (
            ! $this->input->isInteractive()
            || $this->confirm('Would you like these users to be imported / synchronized?', $default = true)
        ) {
            $imported = $this->import->execute();

            $this->info("Successfully imported / synchronized [{$imported->count()}] user(s).");
        } else {
            $this->info('Okay, no users were imported / synchronized.');
        }
    }

    /**
     * Register the import event callbacks for the command.
     *
     * @return void
     */
    protected function registerEventListeners()
    {
        Event::listen(BulkImportStarted::class, function (BulkImportStarted $event) {
            $this->progress = $this->output->createProgressBar($event->objects->count());
        });

        Event::listen(Imported::class, function () {
            if ($this->progress) {
                $this->progress->advance();
            }
        });

        Event::listen(ImportFailed::class, function () {
            if ($this->progress) {
                $this->progress->advance();
            }
        });

        Event::listen(BulkImportDeletedMissing::class, function (BulkImportDeletedMissing $event) {
            $event->deleted->isEmpty()
                ? $this->info('No missing users found. None have been soft-deleted.')
                : $this->info("Successfully soft-deleted [{$event->deleted->count()}] users.");
        });

        Event::listen(BulkImportCompleted::class, function (BulkImportCompleted $event) {
            if ($this->progress) {
                $this->progress->finish();
            }
        });
    }

    /**
     * Determine if there are LDAP objects to import.
     *
     * @return bool
     */
    protected function hasObjectsToImport()
    {
        $this->objects = $this->import->loadObjectsFromRepository($this->argument('user'));

        switch(true) {
            case $this->objects->count() === 0:
                $this->info('There were no users found to import.');
                return false;
            case $this->objects->count() === 1:
                $this->info("Found user [{$this->objects->first()->getRdn()}].");
                return true;
            default:
                $this->info("Found [{$this->objects->count()}] user(s).");
                return true;
        }
    }

    /**
     * Prepare the import by applying the command options.
     *
     * @return void
     */
    protected function applyCommandOptions()
    {
        if ($filter = $this->option('filter')) {
            $this->import->setLdapRawFilter($filter);
        }

        if ($attributes = $this->option('attributes')) {
            $this->import->setLdapRequestAttributes(explode(',', $attributes));
        }

        if (! $this->isLogging()) {
            $this->import->disableLogging();
        }

        if ($this->isRestoring()) {
            $this->import->restoreEnabledUsers();
        }

        if ($this->isDeleting()) {
            $this->import->trashDisabledUsers();
        }

        if ($this->isDeletingMissing()) {
            $this->import->enableSoftDeletes();
        }
    }

    /**
     * Set the importer to use on the import.
     *
     * @param DatabaseUserProvider $provider
     */
    protected function applyProviderImporter(DatabaseUserProvider $provider)
    {
        $this->import->setLdapImporter($provider->getLdapUserImporter());
    }

    /**
     * Set the repository to use on the import.
     *
     * @param DatabaseUserProvider $provider
     */
    protected function applyProviderRepository(DatabaseUserProvider $provider)
    {
        $this->import->setLdapUserRepository($provider->getLdapUserRepository());
    }

    /**
     * Displays the given users in a table.
     *
     * @return void
     */
    protected function confirmAndDisplayObjects()
    {
        if (! $this->input->isInteractive()) {
            return;
        }

        if (! $this->confirm('Would you like to display the user(s) to be imported / synchronized?', $default = false)) {
            return;
        }

        $headers = ['Name', 'Distinguished Name'];

        $rows = [];

        foreach ($this->objects as $object) {
            $rows[] = [
                'name' => $object->getRdn(),
                'dn' => $object->getDn(),
            ];
        }

        $this->table($headers, $rows);
    }

    /**
     * Determine if logging is enabled.
     *
     * @return bool
     */
    protected function isLogging()
    {
        return ! $this->option('no-log');
    }

    /**
     * Determine if soft-deleting disabled user accounts is enabled.
     *
     * @return bool
     */
    protected function isDeleting()
    {
        return $this->option('delete') == 'true';
    }

    /**
     * Determine if soft-deleting all missing users is enabled.
     *
     * @return bool
     */
    protected function isDeletingMissing()
    {
        return $this->option('delete-missing') == 'true' && is_null($this->argument('user'));
    }

    /**
     * Determine if restoring re-enabled users is enabled.
     *
     * @return bool
     */
    protected function isRestoring()
    {
        return $this->option('restore') == 'true';
    }
}
