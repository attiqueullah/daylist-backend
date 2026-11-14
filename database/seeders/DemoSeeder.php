<?php

namespace Database\Seeders;

use App\Models\ListGroup;
use App\Models\ListMember;
use App\Models\Step;
use App\Models\Task;
use App\Models\User;
use App\Services\ListService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo data for building and testing the iOS app.
 * Sign in with demo@daylist.test / password123 (a second user, sam@daylist.test, shares Groceries).
 */
class DemoSeeder extends Seeder
{
    public function run(ListService $lists): void
    {
        $today = Carbon::now('Asia/Karachi');
        $date = fn (int $days) => $today->copy()->addDays($days)->format('Y-m-d');

        $demo = User::create([
            'name' => 'Demo User', 'email' => 'demo@daylist.test', 'password' => 'password123',
            'timezone' => 'Asia/Karachi', 'email_verified_at' => now(),
        ]);
        $sam = User::create([
            'name' => 'Sam Rivera', 'email' => 'sam@daylist.test', 'password' => 'password123',
            'timezone' => 'Asia/Karachi', 'email_verified_at' => now(),
        ]);
        $inbox = $lists->createDefaultList($demo);
        $lists->createDefaultList($sam);

        $home = ListGroup::create(['user_id' => $demo->id, 'name' => 'Home', 'sort_order' => 1]);

        $groceries = $lists->create($demo, ['name' => 'Groceries', 'emoji' => '🛒', 'theme_color' => 'moss', 'group_id' => $home->id]);
        $work = $lists->create($demo, ['name' => 'Work', 'emoji' => '💼', 'theme_color' => 'violet', 'sort_mode' => 'due_date']);
        $trip = $lists->create($demo, ['name' => 'Trip to Hunza', 'emoji' => '🏔️', 'theme_color' => 'lagoon', 'group_id' => $home->id]);

        ListMember::create(['list_id' => $groceries->id, 'user_id' => $sam->id, 'role' => 'member', 'sort_order' => 1, 'joined_at' => now()]);
        $groceries->update(['is_shared' => true]);

        $add = function ($list, string $title, array $extra = []) use ($demo) {
            static $order = 0;

            return Task::create(array_merge([
                'list_id' => $list->id, 'created_by' => $demo->id, 'title' => $title, 'sort_order' => ++$order,
            ], $extra));
        };

        $add($inbox, 'Call the bank about the card', ['my_day_date' => $date(0), 'is_important' => true]);
        $add($inbox, 'Water the plants', ['due_date' => $date(0), 'recurrence' => 'FREQ=DAILY;INTERVAL=2', 'my_day_date' => $date(0)]);
        $add($inbox, 'Renew car registration', ['due_date' => $date(-2)]);
        $add($inbox, 'Pay electricity bill', ['due_date' => $date(5), 'recurrence' => 'FREQ=MONTHLY', 'reminder_at' => $today->copy()->addDays(5)->setTime(10, 0)->utc()]);

        $add($groceries, 'Milk', ['my_day_date' => $date(0)]);
        $add($groceries, 'Eggs (12)', ['assignee_id' => $sam->id]);
        $bread = $add($groceries, 'Bread');
        Step::create(['task_id' => $bread->id, 'title' => 'Whole wheat', 'sort_order' => 1]);
        Step::create(['task_id' => $bread->id, 'title' => 'Check expiry date', 'sort_order' => 2]);
        $add($groceries, 'Coffee beans', ['is_completed' => true, 'completed_at' => now(), 'completed_by' => $sam->id]);

        $deck = $add($work, 'Finish sprint demo deck', ['is_important' => true, 'due_date' => $date(1), 'notes' => 'Include the new Planned screen.']);
        Step::create(['task_id' => $deck->id, 'title' => 'Screenshots from TestFlight', 'sort_order' => 1, 'is_completed' => true]);
        Step::create(['task_id' => $deck->id, 'title' => 'Metrics slide', 'sort_order' => 2]);
        $add($work, 'Weekly 1:1 with Sara', ['due_date' => $date(3), 'recurrence' => 'FREQ=WEEKLY;BYDAY=TH']);
        $add($work, 'Review API pull request', ['due_date' => $date(10)]);

        $add($trip, 'Book hotel in Karimabad', ['due_date' => $date(14), 'is_important' => true]);
        $add($trip, 'Pack warm jacket');
    }
}
