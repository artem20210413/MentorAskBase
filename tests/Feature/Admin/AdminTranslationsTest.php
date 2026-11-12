<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Pages\Auth\PhoneLogin;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

/**
 * Мова адмін-панелі — APP_LOCALE: усі підписи беруться з перекладів admin.php.
 */
class AdminTranslationsTest extends TestCase
{
    use SeedsRoles;

    public static function locales(): array
    {
        return [['uk'], ['ru'], ['en']];
    }

    #[DataProvider('locales')]
    public function test_user_management_page_is_rendered_in_app_locale(string $locale): void
    {
        app()->setLocale($locale);
        $this->seedRoles();

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(UserRole::SuperAdmin);
        User::factory()->create(['status' => 'approved'])->assignRole(UserRole::Moderator);

        $this->actingAs($superAdmin)
            ->get(UserResource::getUrl())
            ->assertOk()
            ->assertSee(__('admin.users.plural', [], $locale))
            ->assertSee(__('admin.users.phone', [], $locale))
            ->assertSee(__('admin.users.statuses.approved', [], $locale))
            ->assertSee(__('admin.roles.moderator', [], $locale));
    }

    public function test_role_options_are_translated(): void
    {
        app()->setLocale('en');

        $this->assertSame(
            ['user' => 'User', 'moderator' => 'Moderator', 'admin' => 'Administrator'],
            UserRole::assignableOptions(),
        );
    }

    public function test_login_errors_are_translated(): void
    {
        app()->setLocale('en');

        Livewire::test(PhoneLogin::class)
            ->set('data.phone', '+15550004000')
            ->call('submit')
            ->assertHasErrors(['data.phone'])
            ->assertSee(__('admin.login.no_access', [], 'en'));
    }
}
