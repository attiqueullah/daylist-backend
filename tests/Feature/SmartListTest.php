<?php

namespace Tests\Feature;

use App\Models\Step;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartListTest extends TestCase
{
    use RefreshDatabase;

    private function titles(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data'))->pluck('title')->sort()->values()->all();
    }

    public function test_my_day_uses_the_clients_today(): void
    {
        $user = $this->signIn();
        $list = $this->makeList($user);
        $this->makeTask($list, ['title' => 'Today', 'my_day_date' => '2026-11-14']);
        $this->makeTask($list, ['title' => 'Yesterday', 'my_day_date' => '2026-11-13']);
        $this->makeTask($list, ['title' => 'Done today', 'my_day_date' => '2026-11-14', 'is_completed' => true]);

        $this->assertSame(['Done today', 'Today'], $this->titles('/api/v1/smart-lists/my-day/tasks?today=2026-11-14'));
        // Next day, My Day is empty without deleting anything.
        $this->assertSame([], $this->titles('/api/v1/smart-lists/my-day/tasks?today=2026-11-15'));

        $this->getJson('/api/v1/smart-lists/my-day/tasks?today=14-11-2026')->assertUnprocessable();
        $this->getJson('/api/v1/smart-lists/nope/tasks')->assertNotFound();
    }

    public function test_important_assigned_all_completed(): void
    {
        $user = $this->signIn();
        $friend = $this->makeUser();
        $list = $this->makeList($user);
        $this->share($list, $friend);

        $this->makeTask($list, ['title' => 'Starred', 'is_important' => true]);
        $this->makeTask($list, ['title' => 'Starred done', 'is_important' => true, 'is_completed' => true]);
        $this->makeTask($list, ['title' => 'Mine', 'assignee_id' => $user->id]);
        $this->makeTask($list, ['title' => 'Friend', 'assignee_id' => $friend->id]);

        // Tasks in lists I am not on never show up.
        $this->makeTask($this->makeList($this->makeUser()), ['title' => 'Stranger', 'is_important' => true]);

        $this->assertSame(['Starred'], $this->titles('/api/v1/smart-lists/important/tasks'));
        $this->assertSame(['Mine'], $this->titles('/api/v1/smart-lists/assigned/tasks'));
        $this->assertSame(['Friend', 'Mine', 'Starred'], $this->titles('/api/v1/smart-lists/all/tasks'));
        $this->assertSame(['Starred done'], $this->titles('/api/v1/smart-lists/completed/tasks'));
    }

    public function test_planned_buckets_including_month_boundary(): void
    {
        $user = $this->signIn();
        $list = $this->makeList($user);
        // Today is Monday 2026-11-30; the week runs to Sunday 2026-12-06.
        $this->makeTask($list, ['title' => 'Overdue', 'due_date' => '2026-11-29']);
        $this->makeTask($list, ['title' => 'Today', 'due_date' => '2026-11-30']);
        $this->makeTask($list, ['title' => 'Tomorrow', 'due_date' => '2026-12-01']);
        $this->makeTask($list, ['title' => 'This week', 'due_date' => '2026-12-06']);
        $this->makeTask($list, ['title' => 'Later', 'due_date' => '2026-12-07']);
        $this->makeTask($list, ['title' => 'Reminder only', 'reminder_at' => '2026-12-02 05:00:00']);
        $this->makeTask($list, ['title' => 'No date']);

        $buckets = collect($this->getJson('/api/v1/smart-lists/planned/tasks?today=2026-11-30')->json('data'))
            ->mapWithKeys(fn ($t) => [$t['title'] => $t['bucket']])->all();

        $this->assertSame([
            'Overdue' => 'overdue',
            'Today' => 'today',
            'Tomorrow' => 'tomorrow',
            'Reminder only' => 'this_week',
            'This week' => 'this_week',
            'Later' => 'later',
        ], $buckets);
    }

    public function test_week_start_setting_changes_this_week(): void
    {
        $user = $this->signIn();
        $user->update(['settings' => ['week_start' => 'sunday']]);
        $this->makeTask($this->makeList($user), ['title' => 'Sunday', 'due_date' => '2026-12-06']);

        $this->getJson('/api/v1/smart-lists/planned/tasks?today=2026-11-30')->assertJsonPath('data.0.bucket', 'later');
    }

    public function test_counts_for_home_tiles(): void
    {
        $user = $this->signIn();
        $list = $this->makeList($user);
        $this->makeTask($list, ['my_day_date' => '2026-11-14', 'is_important' => true, 'due_date' => '2026-11-14']);
        $this->makeTask($list, ['is_completed' => true]);

        $this->getJson('/api/v1/smart-lists/counts?today=2026-11-14')->assertOk()->assertExactJson(['data' => [
            'my_day' => 1, 'important' => 1, 'planned' => 1, 'assigned' => 0, 'all' => 1, 'completed' => 1,
        ]]);
    }

    public function test_my_day_suggestions(): void
    {
        $this->travelTo('2026-11-14 10:00:00');
        $user = $this->signIn();
        $list = $this->makeList($user);
        $this->makeTask($list, ['title' => 'Left from yesterday', 'my_day_date' => '2026-11-13', 'created_at' => '2026-10-01 00:00:00']);
        $this->makeTask($list, ['title' => 'Overdue', 'due_date' => '2026-11-10', 'created_at' => '2026-10-01 00:00:00']);
        $this->makeTask($list, ['title' => 'New', 'created_at' => '2026-11-12 00:00:00']);
        $this->makeTask($list, ['title' => 'Already in My Day', 'my_day_date' => '2026-11-14', 'due_date' => '2026-11-14']);
        $this->makeTask($list, ['title' => 'Old and undated', 'created_at' => '2026-09-01 00:00:00']);

        $data = $this->getJson('/api/v1/my-day/suggestions?today=2026-11-14')->assertOk()->json('data');

        $this->assertSame(['Left from yesterday'], array_column($data['yesterday'], 'title'));
        $this->assertSame(['Overdue'], array_column($data['due'], 'title'));
        $this->assertSame(['New'], array_column($data['recent'], 'title'));
    }

    public function test_search_title_notes_and_steps_grouped_by_list(): void
    {
        $user = $this->signIn();
        $groceries = $this->makeList($user);
        $work = $this->makeList($user, ['name' => 'Work']);
        $this->makeTask($groceries, ['title' => 'Buy milk']);
        $this->makeTask($work, ['title' => 'Email', 'notes' => 'ask about milk budget']);
        $withStep = $this->makeTask($work, ['title' => 'Cake']);
        Step::create(['task_id' => $withStep->id, 'title' => 'Milk and eggs']);
        $this->makeTask($groceries, ['title' => 'Old milk', 'is_completed' => true]);
        $this->makeTask($this->makeList($this->makeUser()), ['title' => 'Stranger milk']);

        $response = $this->getJson('/api/v1/search?q=milk')->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertEqualsCanonicalizing(['Groceries', 'Work'], array_column(array_column($response->json('data'), 'list'), 'name'));

        $this->getJson('/api/v1/search?q=milk&include_completed=1')->assertJsonPath('meta.total', 4);
        $this->getJson('/api/v1/search?q=50%25')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/search')->assertUnprocessable();
    }
}
