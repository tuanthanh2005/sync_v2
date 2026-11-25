<?php

use App\Models\User;
use App\Services\Account\UsernameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('username generator transliterates vietnamese names and avoids collisions', function (): void {
    $generator = new UsernameGenerator;

    expect($generator->generate('Trần Thị Minh Anh'))->toBe('tran_thi_minh_anh');

    User::factory()->create(['username' => 'tran_thi_minh_anh']);

    expect($generator->generate('Trần Thị Minh Anh'))->toBe('tran_thi_minh_anh_1');
});

test('username generator falls back to member prefix when seed is too short', function (): void {
    $generator = new UsernameGenerator;

    expect($generator->generate('@@'))->toBe('member');
});
