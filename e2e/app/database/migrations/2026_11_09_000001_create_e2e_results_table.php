<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('e2e_results', function (Blueprint $table): void {
            $table->id();
            $table->string('task_id', 100);
            $table->string('host', 100);
            $table->string('run_id', 32);
            $table->string('status', 40);
            $table->dateTime('scheduled_at');
            $table->unsignedBigInteger('started_at_ms')->nullable();
            $table->unsignedBigInteger('finished_at_ms')->nullable();
            $table->text('error')->nullable();
            $table->index(['task_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e2e_results');
    }
};
