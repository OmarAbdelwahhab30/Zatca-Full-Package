<?php

namespace Zatca\Services\SDK;

use Zatca\Exceptions\ZatcaSdkFingerprintMismatchException;
use Illuminate\Support\Facades\Config;

/**
 * Records/verifies the SDK jar's binary identity so a silent SDK swap
 * (upgrade, corruption, wrong file at the configured path) fails loudly at
 * health-check time instead of being misread as a ZATCA API/behavior change.
 */
class ZatcaSdkFingerprint
{
    public function __construct(private readonly ZatcaSdkRuntimeManager $runtime) {}

    public function current(): string
    {
        return hash_file('sha256', $this->runtime->jarPath());
    }

    public function expected(): ?string
    {
        return Config::get('zatca.sdk.expected_fingerprint') ?: null;
    }

    /** @throws ZatcaSdkFingerprintMismatchException when a fingerprint is recorded and it no longer matches. */
    public function verify(): void
    {
        $expected = $this->expected();

        if (! $expected) {
            return; // Nothing recorded yet — onboarding hasn't run, or health-check is the one recording it.
        }

        $actual = $this->current();

        if (! hash_equals($expected, $actual)) {
            throw ZatcaSdkFingerprintMismatchException::mismatch($expected, $actual);
        }
    }
}
