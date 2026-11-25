<?php

namespace App\Providers;

use App\Listeners\RecordAuthSecurityEvents;
use App\Models\AdCampaign;
use App\Models\Comment;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\PolicyPage;
use App\Models\Post;
use App\Models\User;
use App\Policies\AccountPolicy;
use App\Policies\AdCampaignPolicy;
use App\Policies\CommentPolicy;
use App\Policies\CommunityPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\PolicyPagePolicy;
use App\Policies\PostPolicy;
use App\Services\Billing\HmacPaymentWebhookService;
use App\Services\Billing\PaymentGatewayInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, HmacPaymentWebhookService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureSecurityListeners();
        $this->configureRouteRateLimits();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Register model policies and domain gates.
     */
    protected function configureAuthorization(): void
    {
        Gate::policy(User::class, AccountPolicy::class);
        Gate::policy(PolicyPage::class, PolicyPagePolicy::class);
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(Comment::class, CommentPolicy::class);
        Gate::policy(Community::class, CommunityPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(AdCampaign::class, AdCampaignPolicy::class);

        Gate::define('access-moderation', [AccountPolicy::class, 'accessModeration']);
        Gate::define('access-admin', [AccountPolicy::class, 'accessAdmin']);
        Gate::define('review-ads', [AdCampaignPolicy::class, 'review']);
    }

    /**
     * Subscribe security audit and authentication history listeners.
     */
    protected function configureSecurityListeners(): void
    {
        Event::subscribe(RecordAuthSecurityEvents::class);
    }

    /**
     * Enforce registration rate limiting on Fortify's registration endpoint.
     */
    protected function configureRouteRateLimits(): void
    {
        $this->app->booted(function (): void {
            if ($this->app->routesAreCached()) {
                return;
            }

            $routes = Route::getRoutes();
            $routes->refreshNameLookups();

            $registerRoute = $routes->getByName('register.store');

            if ($registerRoute !== null) {
                $registerRoute->middleware('throttle:register');
            }
        });
    }
}
