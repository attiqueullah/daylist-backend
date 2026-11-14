<?php

namespace Tests\Unit;

use App\Services\RecurrenceService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RecurrenceServiceTest extends TestCase
{
    private function next(string $rule, string $from): ?string
    {
        return (new RecurrenceService)->nextDate($rule, Carbon::createFromFormat('!Y-m-d', $from, 'UTC'))?->format('Y-m-d');
    }

    /** Every option in the repeat picker, plus month-end and leap-year edges. */
    public static function presets(): array
    {
        return [
            'daily' => ['FREQ=DAILY', '2026-11-14', '2026-11-15'],
            'every 3 days' => ['FREQ=DAILY;INTERVAL=3', '2026-11-30', '2026-12-03'],
            'weekdays from Friday' => ['FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR', '2026-11-13', '2026-11-16'],
            'weekdays from Saturday' => ['FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR', '2026-11-14', '2026-11-16'],
            'weekly same weekday' => ['FREQ=WEEKLY', '2026-11-14', '2026-11-21'],
            'weekly Mon and Wed from Mon' => ['FREQ=WEEKLY;BYDAY=MO,WE', '2026-11-16', '2026-11-18'],
            'weekly Mon and Wed from Wed' => ['FREQ=WEEKLY;BYDAY=MO,WE', '2026-11-18', '2026-11-23'],
            'every 2 weeks' => ['FREQ=WEEKLY;INTERVAL=2', '2026-11-14', '2026-11-28'],
            'monthly' => ['FREQ=MONTHLY', '2026-11-14', '2026-12-14'],
            'monthly across year end' => ['FREQ=MONTHLY', '2026-12-15', '2027-01-15'],
            'monthly last day' => ['FREQ=MONTHLY;BYMONTHDAY=-1', '2027-01-31', '2027-02-28'],
            'yearly' => ['FREQ=YEARLY', '2026-11-14', '2027-11-14'],
            'yearly leap day' => ['FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=-1', '2027-02-28', '2028-02-29'],
        ];
    }

    #[DataProvider('presets')]
    public function test_next_occurrence(string $rule, string $from, string $expected): void
    {
        $this->assertSame($expected, $this->next($rule, $from));
    }

    public function test_series_with_until_ends(): void
    {
        $this->assertSame('2026-11-15', $this->next('FREQ=DAILY;UNTIL=20261115T235959Z', '2026-11-14'));
        $this->assertNull($this->next('FREQ=DAILY;UNTIL=20261115T235959Z', '2026-11-15'));
    }

    public function test_validation(): void
    {
        $service = new RecurrenceService;

        $this->assertNull($service->validationError('FREQ=WEEKLY;BYDAY=MO,WE'));
        $this->assertNull($service->validationError('RRULE:FREQ=daily;interval=2'));
        $this->assertNotNull($service->validationError(''));
        $this->assertNotNull($service->validationError('FREQ=HOURLY'));
        $this->assertNotNull($service->validationError('FREQ=DAILY;COUNT=5'));
        $this->assertNotNull($service->validationError('FREQ=WEEKLY;BYDAY=XX'));
        $this->assertNotNull($service->validationError('BYDAY=MO'));
        $this->assertSame('FREQ=DAILY;INTERVAL=2', $service->normalise('RRULE:freq=daily;interval=2;'));
        $this->assertSame('FREQ=WEEKLY;UNTIL=20261231', $service->normalise('FREQ=WEEKLY;UNTIL=20261231T235959Z'));
        $this->assertNull($service->validationError('FREQ=WEEKLY;UNTIL=20261231T235959Z'));
    }
}
