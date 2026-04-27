<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Scabarcas\LaravelPermissionsRedis\Events\UserDeleted;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\DispatchingUser;

test('trait dispatches UserDeleted when model is deleted', function () {
    $user = DispatchingUser::create(['name' => 'Alice', 'email' => 'alice@test.com']);

    Event::fake([UserDeleted::class]);

    $user->delete();

    Event::assertDispatched(
        UserDeleted::class,
        fn (UserDeleted $event): bool => $event->userId === $user->id,
    );
});

test('trait does not dispatch UserDeleted when model is saved but not deleted', function () {
    Event::fake([UserDeleted::class]);

    $user = DispatchingUser::create(['name' => 'Bob', 'email' => 'bob@test.com']);
    $user->update(['name' => 'Robert']);

    Event::assertNotDispatched(UserDeleted::class);
});

test('trait dispatches UserDeleted with the actual primary key, not a null', function () {
    $user = DispatchingUser::create(['name' => 'Carol', 'email' => 'carol@test.com']);
    $expectedId = $user->id;

    Event::fake([UserDeleted::class]);

    $user->delete();

    Event::assertDispatched(
        UserDeleted::class,
        fn (UserDeleted $event): bool => $event->userId === $expectedId && $event->userId !== null,
    );
});
