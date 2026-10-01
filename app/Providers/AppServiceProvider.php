<?php

namespace App\Providers;

use App\Models\Transaction;
use App\Observers\TransactionObserver;
use App\Services\JournalService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register JournalService as singleton
        $this->app->singleton(JournalService::class, function ($app) {
            return new JournalService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register TransactionObserver untuk auto-journaling
        Transaction::observe(TransactionObserver::class);
    }
}
