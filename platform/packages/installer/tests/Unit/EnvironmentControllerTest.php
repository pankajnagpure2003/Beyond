<?php

namespace Botble\Installer\Tests\Unit;

use Botble\Installer\Http\Controllers\EnvironmentController;
use Botble\Installer\Http\Requests\SaveEnvironmentRequest;
use Botble\Installer\Services\ImportDatabaseService;
use Botble\Installer\Supports\EnvironmentManager;
use Illuminate\Http\RedirectResponse;
use Tests\TestCase;

class EnvironmentControllerTest extends TestCase
{
    public function test_it_rejects_database_credentials_that_cannot_connect_without_writing_env(): void
    {
        // Mocks instead of the real services: if the connection check ever stops the request
        // from going through, the test fails here instead of overwriting the repository .env
        // or importing sample data into the dev database.
        $environmentManager = $this->mock(EnvironmentManager::class, fn ($mock) => $mock->shouldNotReceive('save'));
        $importDatabaseService = $this->mock(ImportDatabaseService::class, fn ($mock) => $mock->shouldNotReceive('handle'));

        // These credentials cannot log in whether or not a MySQL server is running locally.
        $request = SaveEnvironmentRequest::create('/', 'POST', [
            'app_name' => 'Test Site',
            'app_url' => 'https://example.com',
            'database_connection' => 'mysql',
            'database_hostname' => '127.0.0.1',
            'database_port' => '3306',
            'database_name' => 'installer_test_db',
            'database_username' => 'installer_test_no_such_user',
            'database_password' => 'wrong-password',
        ]);

        $response = app(EnvironmentController::class)->store($request, $environmentManager, $importDatabaseService);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertTrue($response->getSession()->get('errors')->has('database'));
    }
}
