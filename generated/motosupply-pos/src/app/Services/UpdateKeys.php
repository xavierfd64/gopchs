<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Public keys (Ed25519, base64) whose signatures the updater accepts. The matching private key
 * is kept by the publisher OFFLINE and is never part of the application or its repository.
 * A future signed update may add a new key here (key rotation).
 */
final class UpdateKeys
{
    public const KEYS = [
        'zq0X6gsnb1kfFPbMY/qfAKuVQ6crvvICA8qq2X3zyks=', // MotoSupply release key 2026
    ];
}
