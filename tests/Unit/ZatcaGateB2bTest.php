<?php

namespace Zatca\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Zatca\Support\B2bReadiness;
use Zatca\Support\ZatcaGate;
use Zatca\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Blueprint P13: the B2B switch. Fail-closed, and dependent on the global switch.
 * ZatcaKillSwitchTest keeps covering enabled(), which is unchanged.
 */
class ZatcaGateB2bTest extends TestCase
{
    public function test_b2b_is_off_by_default(): void
    {
        $this->assertFalse(Config::get('zatca.b2b.enabled'));
        $this->assertFalse((new ZatcaGate)->b2bEnabled());
        $this->assertTrue((new ZatcaGate)->b2bDisabled());
    }

    /** @dataProvider rawValues */
    #[DataProvider('rawValues')]
    public function test_only_an_explicit_on_turns_b2b_on(mixed $raw, bool $expected): void
    {
        Config::set('zatca.enabled', true);
        Config::set('zatca.b2b.enabled', $raw);

        $this->assertSame($expected, (new ZatcaGate)->b2bEnabled());
        $this->assertSame($expected, ZatcaGate::isExplicitlyOn($raw));
    }

    public static function rawValues(): array
    {
        return [
            'null' => [null, false],
            'empty string' => ['', false],
            'string false' => ['false', false],
            'string 0' => ['0', false],
            'off' => ['off', false],
            'no' => ['no', false],
            'yes please' => ['yes please', false],
            'int 2' => [2, false],
            'float 1.0' => [1.0, false],
            'array' => [['true'], false],
            'bool false' => [false, false],
            'bool true' => [true, true],
            'int 1' => [1, true],
            'string 1' => ['1', true],
            'string true' => ['true', true],
            'padded, upper case' => [' TRUE ', true],
            'on' => ['on', true],
            'yes' => ['Yes', true],
        ];
    }

    /** @dataProvider switches */
    #[DataProvider('switches')]
    public function test_b2b_requires_the_global_switch(mixed $zatca, mixed $b2b, bool $expected): void
    {
        Config::set('zatca.enabled', $zatca);
        Config::set('zatca.b2b.enabled', $b2b);

        $this->assertSame($expected, (new ZatcaGate)->b2bEnabled());
    }

    public static function switches(): array
    {
        return [
            'both on' => [true, true, true],
            'ZATCA off, B2B on' => [false, true, false],
            'ZATCA on, B2B off' => [true, false, false],
            'both off' => [false, false, false],
            'ZATCA unset (enabled), B2B on' => [null, 'true', true],
            'ZATCA unset (enabled), B2B unset' => [null, null, false],
        ];
    }

    /** A published or cached config written before P13 has no b2b key at all. */
    public function test_a_config_without_the_b2b_key_means_off(): void
    {
        $config = Config::get('zatca');
        unset($config['b2b']);
        Config::set('zatca', $config);

        $this->assertFalse((new ZatcaGate)->b2bEnabled());
    }

    public function test_the_global_switch_is_unaffected_by_b2b(): void
    {
        Config::set('zatca.enabled', true);
        Config::set('zatca.b2b.enabled', false);
        $this->assertTrue((new ZatcaGate)->enabled());

        Config::set('zatca.enabled', false);
        Config::set('zatca.b2b.enabled', true);
        $this->assertFalse((new ZatcaGate)->enabled());
    }

    public function test_skip_b2b_logs_at_info(): void
    {
        Log::spy();

        (new ZatcaGate)->skipB2b('company invoice for payment:41');

        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $m) => str_contains($m, 'Skipped company invoice for payment:41: B2B invoicing is disabled'));
    }

    /** Skeleton until P40: never ready, whatever the switches say. */
    public function test_readiness_is_never_ready_yet(): void
    {
        Config::set('zatca.enabled', true);
        Config::set('zatca.b2b.enabled', true);

        $this->assertFalse((new B2bReadiness)->ready());
        $this->assertFalse(app(B2bReadiness::class)->ready());
    }
}
