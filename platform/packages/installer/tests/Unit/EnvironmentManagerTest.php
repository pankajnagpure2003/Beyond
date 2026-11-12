<?php

namespace Botble\Installer\Tests\Unit;

use Botble\Installer\Supports\EnvironmentManager;
use Dotenv\Dotenv;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnvironmentManagerTest extends TestCase
{
    /**
     * Build the .env content only - never writes to disk, so the repository .env is safe.
     */
    protected function buildContent(string $appUrl): string
    {
        return (new EnvironmentManager())->buildEnvironmentContent(Request::create('/', 'POST', [
            'app_name' => 'Test Site',
            'app_url' => $appUrl,
            'database_connection' => 'mysql',
            'database_hostname' => '127.0.0.1',
            'database_port' => '3306',
            'database_name' => 'test_db',
            'database_username' => 'test_user',
            'database_password' => 'test_password',
        ]));
    }

    public function test_it_leaves_force_root_url_commented_out_for_a_root_domain_install(): void
    {
        $content = $this->buildContent('https://example.com');

        // The line must stay exactly as .env.example ships it: commented out, with the
        // placeholder domain. Anything else pins the site to the install domain and breaks
        // a later move to another domain.
        $this->assertStringContainsString('#FORCE_ROOT_URL=https://your-domain.com', $content);
        $this->assertStringNotContainsString('FORCE_ROOT_URL=https://example.com', $content);
        $this->assertStringContainsString('APP_URL=https://example.com', $content);
    }

    public function test_it_leaves_force_root_url_commented_out_when_the_url_has_a_trailing_slash(): void
    {
        $content = $this->buildContent('https://example.com/');

        $this->assertStringContainsString('#FORCE_ROOT_URL=https://your-domain.com', $content);
        // The trailing slash is trimmed so APP_URL never produces double-slashed links.
        $this->assertStringContainsString('APP_URL=https://example.com' . PHP_EOL, $content);
    }

    public function test_it_writes_force_root_url_for_a_sub_folder_install(): void
    {
        $content = $this->buildContent('https://example.com/my-site');

        $this->assertStringContainsString('FORCE_ROOT_URL=https://example.com/my-site', $content);
        $this->assertStringNotContainsString('#FORCE_ROOT_URL=', $content);
        $this->assertStringContainsString('APP_URL=https://example.com/my-site', $content);
    }

    public function test_it_writes_the_remaining_environment_values(): void
    {
        $content = $this->buildContent('https://example.com');

        $this->assertStringContainsString('APP_NAME="Test Site"', $content);
        $this->assertStringContainsString('DB_HOST=127.0.0.1', $content);
        $this->assertStringContainsString('DB_DATABASE="test_db"', $content);
        $this->assertStringContainsString('DB_USERNAME="test_user"', $content);
        $this->assertStringContainsString('DB_PASSWORD="test_password"', $content);
    }

    public function test_it_writes_special_characters_so_phpdotenv_reads_them_back_unchanged(): void
    {
        // Each value hits one way the old writer corrupted it: '"' was stripped, "\a" made the
        // whole .env unparseable, "${APP_NAME}" was interpolated, "$1" / "\\" were eaten by
        // preg_replace as backreferences, and "#" must not start a comment.
        $values = [
            'app_name' => 'My "Quoted" Site',
            'database_name' => 'db$1name',
            'database_username' => 'user\\name',
            'database_password' => 'p"a\\ss$1${APP_NAME}\\a#x\'y\\',
        ];

        $content = (new EnvironmentManager())->buildEnvironmentContent(Request::create('/', 'POST', [
            ...$values,
            'app_url' => 'https://example.com',
            'database_connection' => 'mysql',
            'database_hostname' => '127.0.0.1',
            'database_port' => '3306',
        ]));

        $env = Dotenv::parse($content);

        $this->assertSame($values['app_name'], $env['APP_NAME']);
        $this->assertSame($values['database_name'], $env['DB_DATABASE']);
        $this->assertSame($values['database_username'], $env['DB_USERNAME']);
        $this->assertSame($values['database_password'], $env['DB_PASSWORD']);
    }
}
