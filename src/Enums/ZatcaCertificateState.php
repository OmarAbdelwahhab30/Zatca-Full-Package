<?php

namespace Asas\Zatca\Enums;

/**
 * Note: an earlier revision had a separate `Active` state reached only
 * right after a successful renewal. It was removed — the very next
 * scheduled `zatca:certificate:check` run always collapsed it back to
 * `Valid` anyway (nothing in the code ever treated `Active` differently
 * from `Valid`), so it was a cosmetic distinction, not a tested one.
 * `Valid` is the one durable "good to report" state.
 */
enum ZatcaCertificateState: string
{
    case Valid = 'valid';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';
    case Renewing = 'renewing';
    case Failed = 'failed';
}
