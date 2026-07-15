<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->panel = Filament::getPanel('admin');
});

it('seeds the three Smoothware roles', function () {
    expect(collect(RoleSeeder::ROLES))->each->toBeIn(
        Role::pluck('name')->all()
    );
});

it('lets an active user with a role access the panel', function () {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    expect($user->canAccessPanel($this->panel))->toBeTrue();
});

it('denies panel access to a user without any role', function () {
    $user = User::factory()->create();

    expect($user->canAccessPanel($this->panel))->toBeFalse();
});

it('denies panel access to a deactivated user even with a role', function () {
    $user = User::factory()->inactive()->create();
    $user->assignRole('sales_rep');

    expect($user->canAccessPanel($this->panel))->toBeFalse();
});
