<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanOrganisation;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\LoanOrganisation\API\DTO\BranchInput;
use Plugins\LoanOrganisation\API\DTO\StaffQuery;
use Plugins\LoanOrganisation\Domain\BusinessHours;
use Plugins\LoanOrganisation\Domain\OpeningAmount;

/** Rules that need no database: the value objects and the day-by-day hours checks. */
#[CoversClass(OpeningAmount::class)]
#[CoversClass(BusinessHours::class)]
final class DomainTest extends TestCase
{
    public function test_not_counted_is_null_and_a_counted_zero_is_zero(): void
    {
        self::assertNull(OpeningAmount::from(null, 'opening_cash'), 'absent means not counted');
        self::assertNull(OpeningAmount::from('', 'opening_cash'), 'a blank field means not counted');
        self::assertSame(0, OpeningAmount::from(0, 'opening_cash'), 'an integer zero is a real answer');
        self::assertSame(0, OpeningAmount::from('0', 'opening_cash'), 'a string zero is a real answer');
        self::assertSame(250000, OpeningAmount::from('250000', 'opening_cash'));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function badAmounts(): iterable
    {
        yield 'a float' => [1500.0];
        yield 'a fractional float' => [1500.5];
        yield 'a fractional string' => ['1500.5'];
        yield 'a negative integer' => [-1];
        yield 'a thousands separator' => ['1,500'];
        yield 'letters' => ['12a'];
        yield 'a boolean' => [true];
    }

    #[DataProvider('badAmounts')]
    public function test_money_is_whole_shillings_and_never_a_float(mixed $raw): void
    {
        $this->expectException(\DomainException::class);

        OpeningAmount::from($raw, 'opening_cash');
    }

    public function test_hours_must_list_all_seven_days_in_order(): void
    {
        $week = [];

        foreach (['sun', 'sat', 'fri', 'thu', 'wed', 'tue', 'mon'] as $day) {
            $week[] = ['day' => $day, 'open' => '08:00', 'close' => '17:00', 'closed' => false];
        }

        $ordered = BusinessHours::normaliseDays($week);

        self::assertSame(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], array_column($ordered, 'day'));
    }

    public function test_hours_refuse_six_days(): void
    {
        $this->expectException(\DomainException::class);

        BusinessHours::normaliseDays(array_slice(
            array_map(static fn(string $d): array => ['day' => $d, 'closed' => true], BusinessHours::DAYS),
            0,
            6,
        ));
    }

    public function test_hours_refuse_a_close_that_is_not_after_the_open(): void
    {
        $days = array_map(static fn(string $d): array => ['day' => $d, 'closed' => true], BusinessHours::DAYS);
        $days[0] = ['day' => 'mon', 'open' => '18:00', 'close' => '08:00', 'closed' => false];

        $this->expectException(\DomainException::class);

        BusinessHours::normaliseDays($days);
    }

    public function test_a_branch_needs_a_name_on_create(): void
    {
        $this->expectException(ValidationException::class);

        BranchInput::fromArray(['district' => 'Wakiso'], creating: true);
    }

    public function test_a_float_in_the_opening_cash_is_a_validation_error_not_a_rounding(): void
    {
        try {
            BranchInput::fromArray(['name' => 'X', 'opening_cash' => 1500.5], creating: true);
            self::fail('a fractional amount must be refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('opening_cash', $e->errors);
        }
    }

    public function test_the_default_listing_shows_only_people_who_may_be_assigned(): void
    {
        self::assertSame(StaffQuery::ACTIVE, StaffQuery::fromQuery([])->status);
    }
}
