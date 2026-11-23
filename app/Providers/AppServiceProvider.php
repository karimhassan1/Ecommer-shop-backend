<?php

namespace App\Providers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Policies\BrandPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\ContactMessagePolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ReviewPolicy;
use App\Policies\UserPolicy;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The policy map, registered explicitly so the schema directives
     * (`@canModel`, `@canFind`) always resolve to the right policy.
     *
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        User::class => UserPolicy::class,
        Product::class => ProductPolicy::class,
        Brand::class => BrandPolicy::class,
        Category::class => CategoryPolicy::class,
        Order::class => OrderPolicy::class,
        ContactMessage::class => ContactMessagePolicy::class,
        Review::class => ReviewPolicy::class,
    ];

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
        app(PermissionRegistrar::class)->registerPermissions(Gate::getFacadeRoot());

        $this->registerPolicies();
        $this->registerGates();
        $this->registerPasswordRules();
        $this->registerEmailLinks();
    }

    protected function registerPolicies(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    protected function registerGates(): void
    {
        // Super admins bypass every ability. Registering this once here keeps
        // the individual policies free of role logic.
        Gate::before(function (User $user): ?bool {
            return $user->hasRole(RoleSeeder::SUPER_ADMIN) ? true : null;
        });

        // Coarse gates used by the admin panel navigation and by resolvers that
        // do not map onto a single model.
        //
        // `admin` means "may open the panel": administrators, sellers and any
        // other staff role. What each of them may then see is narrowed by the
        // permissions on the individual screens and by the row level scopes
        // (`Product::visibleTo`, `MyOrders`), never by this gate alone.
        Gate::define('admin', fn (User $user): bool => $user->isStaff());
        Gate::define('seller', fn (User $user): bool => $user->isSeller());
        Gate::define('manage-catalog', fn (User $user): bool => $user->can('product.create'));
        Gate::define('manage-orders', fn (User $user): bool => $user->can('order.update-status'));
        Gate::define('manage-users', fn (User $user): bool => $user->can('user.view'));
        Gate::define('manage-settings', fn (User $user): bool => $user->can('setting.update'));
    }

    protected function registerPasswordRules(): void
    {
        Password::defaults(fn () => $this->app->isLocal()
            ? Password::min(8)
            : Password::min(8)->letters()->mixedCase()->numbers());
    }

    /**
     * Point the e-mailed password reset URL at the SPA, not at the API host.
     *
     * Email verification does not use a signed link any more: the customer
     * enters the six digit code from their inbox on the verification screen
     * (see VerificationCodeService).
     */
    protected function registerEmailLinks(): void
    {
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $frontend = rtrim((string) config('app.frontend_url'), '/');

            if ($frontend === '') {
                return url(route('password.reset', [
                    'token' => $token,
                    'email' => $user->getEmailForPasswordReset(),
                ], false));
            }

            return $frontend.'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });
    }
}
