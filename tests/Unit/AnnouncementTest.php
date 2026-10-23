<?php

use App\Queue\Announcement;

it('reads a call the way the front desk says it', function () {
    expect(Announcement::forCall('A', 12, 2))
        ->toBe('Nomor antrean A dua belas, silakan ke loket dua');
});

it('spells numbers in Indonesian', function (int $n, string $words) {
    expect(Announcement::words($n))->toBe($words);
})->with([
    [1, 'satu'],
    [10, 'sepuluh'],
    [11, 'sebelas'],
    [19, 'sembilan belas'],
    [20, 'dua puluh'],
    [21, 'dua puluh satu'],
    [100, 'seratus'],
    [111, 'seratus sebelas'],
    [205, 'dua ratus lima'],
    [999, 'sembilan ratus sembilan puluh sembilan'],
]);

it('refuses numbers it cannot spell', function () {
    Announcement::words(1000);
})->throws(InvalidArgumentException::class);
