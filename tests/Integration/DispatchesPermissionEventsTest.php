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

test('trait does not dispatch UserDeleted when model is not deleted', function () {
    DispatchingUser::create(['name' => 'Bob', 'email' => 'bob@test.com']);

    Event::fake([UserDeleted::class]);

    Event::assertNotDispatched(UserDeleted::class);
});
