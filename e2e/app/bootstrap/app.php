<?php

declare(strict_types=1);

use Emorfiq\Bridge\Laravel\SchedulerServiceProvider;
use Emorfiq\E2E\E2EServiceProvider;
use Illuminate\Foundation\Application;

/*
 * Minimální Laravel 12 aplikace pro e2e test – stejné zapojení jako v reálném projektu:
 * jen přidání SchedulerServiceProvider + modulu, který dodává úlohy.
 * Konfigurace přichází z env proměnných (docker-compose), výchozí config je z frameworku.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        SchedulerServiceProvider::class,
        E2EServiceProvider::class,
    ])
    ->withExceptions()
    ->create();
