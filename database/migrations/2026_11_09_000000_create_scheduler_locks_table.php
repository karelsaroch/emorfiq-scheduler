<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel varianta database/sql/scheduler_locks.sql (schéma musí zůstat shodné).
 * Respektuje scheduler.mutex.database.connection/table – tabulka vznikne tam, kde ji store hledá.
 */
return new class () extends Migration {
    public function getConnection(): ?string
    {
        return config('scheduler.mutex.database.connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table): void {
            $table->string('lock_key', 191)->primary();
            $table->string('owner', 64);
            $table->bigInteger('expires_at')->index(); // unix ms
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('scheduler.mutex.database.table', 'scheduler_locks');
    }
};
