<?php

namespace App\Providers;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\DeployCommand::class,
            ]);
        }

        // Staging-wide BCC. Whatever address (or comma-separated list) is in
        // MAIL_GLOBAL_BCC silently receives a copy of every outgoing email,
        // useful while wiring up SMTP / templates on a non-prod environment.
        // Leave the env var unset on production.
        $globalBcc = (string) config('mail.global_bcc', '');
        if ($globalBcc !== '') {
            $addresses = array_values(array_filter(array_map('trim', explode(',', $globalBcc))));
            if ($addresses !== []) {
                Event::listen(MessageSending::class, function (MessageSending $event) use ($addresses): void {
                    foreach ($addresses as $address) {
                        $event->message->addBcc($address);
                    }
                });
            }
        }
    }
}
