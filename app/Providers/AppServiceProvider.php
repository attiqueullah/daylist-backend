<?php

namespace App\Providers;

use App\Models\Attachment;
use App\Models\ListGroup;
use App\Models\Step;
use App\Models\Task;
use App\Models\TaskList;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // Route parameters are UUIDs: anything else is a plain 404 without a database query.
        Route::pattern('list', '[0-9a-fA-F-]{36}');
        Route::pattern('task', '[0-9a-fA-F-]{36}');
        Route::pattern('step', '[0-9a-fA-F-]{36}');
        Route::pattern('group', '[0-9a-fA-F-]{36}');
        Route::pattern('attachment', '[0-9a-fA-F-]{36}');
        Route::pattern('userId', '[0-9a-fA-F-]{36}');

        Route::model('list', TaskList::class);
        Route::model('task', Task::class);
        Route::model('step', Step::class);
        Route::model('group', ListGroup::class);
        Route::model('attachment', Attachment::class);

        // The reset link opens the app (or a small web page) with token and email.
        ResetPassword::createUrlUsing(fn ($user, string $token) => config('daylist.password_reset_url')
            .'?token='.$token.'&email='.urlencode($user->email));
    }
}
