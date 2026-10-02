<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Postal\CdsRepository;
use App\Services\SqlServerSearchService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PostalNavigationTest extends TestCase
{
    use RefreshDatabase;

    public static function profiles(): array
    {
        return [
            'sin permisos' => [[], false],
            'solo IPS' => [['ips.read'], false],
            'solo CDS' => [['cds.read'], false],
            'IPS y CDS' => [['ips.read', 'cds.read'], false],
            'administrador' => [[], true],
        ];
    }

    #[DataProvider('profiles')]
    public function test_sidebar_keeps_one_entry_per_task_and_respects_permissions(array $permissions, bool $admin): void
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        if ($admin) $user->assignRole(Role::findOrCreate('admin', 'web'));
        $this->mock(SqlServerSearchService::class)->shouldNotReceive('search');
        $this->mock(CdsRepository::class)->shouldNotReceive('search');
        $response = $this->actingAs($user)->get('/dashboard')->assertOk();
        $xpath = $this->html($response->getContent());
        $sidebar = '//aside[contains(@class, "main-sidebar")]';
        $ips = $admin || in_array('ips.read', $permissions, true);
        $cds = $admin || in_array('cds.read', $permissions, true);
        $routes = [
            'postal.combined' => $ips || $cds,
            'postal.ips' => $ips,
            'postal.dispatches' => $ips,
            'postal.receptacles' => $ips,
            'postal.cds' => $cds,
            'postal.customs.remittance' => $cds,
            'postal.operations' => $ips,
            'operaciones.index' => in_array('ips.read', $permissions, true),
            'sqlserver.datos' => in_array('ips.read', $permissions, true),
            'postal.access.index' => $admin,
        ];
        foreach ($routes as $route => $allowed) {
            $this->assertSame($allowed ? 1 : 0, $xpath->query($sidebar.'//a[@href="'.route($route).'"]')->length, $route);
        }
        // The old addresses still work, but do not create duplicate menu entries.
        $this->assertSame(0, $xpath->query($sidebar.'//a[@href="'.route('consultas.index').'"]')->length);
        $this->assertSame(0, $xpath->query($sidebar.'//a[@href="'.route('postal.customs').'"]')->length);
        if ($cds) {
            $this->assertSame(1, $xpath->query($sidebar.'//li[./a/p[contains(., "Gestión aduanera")]]//a[@href="'.route('postal.customs.remittance').'"]')->length);
        }
        if (in_array('ips.read', $permissions, true)) {
            $this->assertSame(1, $xpath->query($sidebar.'//li[./a/p[contains(., "Supervisión del sistema")]]//a[@href="'.route('sqlserver.datos').'"]')->length);
        }
    }

    public static function queryAliases(): array
    {
        return [
            'consulta anterior' => ['/consultas', 'postal.combined'],
            'expediente' => ['/conjunto', 'postal.combined'],
            'CDS' => ['/cds', 'postal.cds'],
            'aduana anterior' => ['/aduana', 'postal.cds'],
        ];
    }

    #[DataProvider('queryAliases')]
    public function test_existing_query_addresses_highlight_the_canonical_menu_entry(string $url, string $route): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $this->mock(SqlServerSearchService::class)->shouldNotReceive('search');
        $this->mock(CdsRepository::class)->shouldNotReceive('search');
        $response = $this->actingAs($user)->get($url)->assertOk();
        $xpath = $this->html($response->getContent());
        $active = '//aside[contains(@class, "main-sidebar")]//ul[contains(@class, "nav-treeview")]//a[contains(concat(" ", normalize-space(@class), " "), " active ")]';
        $this->assertSame(1, $xpath->query($active)->length);
        $this->assertSame(route($route), $xpath->query($active)->item(0)->getAttribute('href'));
    }

    private function html(string $content): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">'.$content, LIBXML_NOERROR | LIBXML_NOWARNING);
        return new DOMXPath($document);
    }
}
